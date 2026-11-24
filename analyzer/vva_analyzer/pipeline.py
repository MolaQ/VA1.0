"""Stage 1 pipeline: video -> ball activity + score overlay -> sets, rallies, serves -> VVA."""

from __future__ import annotations

import json
import logging
from collections.abc import Callable
from dataclasses import asdict
from pathlib import Path

from . import actions as act
from . import rallies as ral
from .api import VvaClient
from .config import Calibration, Settings
from .players import Person, build_frames, track_players
from .scoreboard import Reading, is_replay, replay_spans, stabilise

log = logging.getLogger("vva")

Progress = Callable[[int, str], None]


class Job:
    def __init__(self, cal: Calibration, s: Settings, workdir: Path, client: VvaClient | None = None,
                 game_id: int | None = None, device: str | None = None, on_progress: Progress | None = None):
        self.cal, self.s, self.workdir = cal, s, workdir
        self.client, self.game_id, self.device = client, game_id, device
        self.lineups: dict[int, list[int | None]] = {}  # set -> numbers in rotation positions I..VI
        self.first_serve: dict[int, bool] = {}
        self.liberos: dict[int, tuple[int | None, int | None]] = {}  # set -> (reception, defence) numbers
        self.middles: set[int] = set()  # jersey numbers of our middle blockers
        self.labels: dict[int, int] = {}  # analyzer track -> jersey number the analyst gave (2D view)
        self.roster: set[int] = set()  # our jersey numbers
        self.team_id: int | None = None
        self.looks: dict[int, dict] = {}  # jersey number -> learned look (appearance.py)
        self.gallery: dict[int, dict] = {}  # jersey number -> learned re-ID fingerprint (reid.py)
        self.prints: dict[int, dict] = {}  # BoT-SORT track id -> mean fingerprint of this video
        self.video_stem = "video"
        self.scanning = False  # partial results during the scan skip the slow player model
        self.last: tuple | None = None  # (tracking, our track ids, numbers before the look-based guesses)
        self.on_progress = on_progress or (lambda p, m: log.info("%3d%% %s", p, m))
        self.obs: list[ral.BallObs] = []
        self.readings: list[Reading] = []
        self.replay_checked = True  # False for caches made before replays were detected
        self.whistles: list[float] = []
        # people (feet points, normalised image coords) every positions_every_s, for the 2D court view
        self.people: list[tuple[float, list[Person]]] = []

    # ------------------------------------------------------------------ steps

    def progress(self, pct: int, message: str) -> None:
        self.on_progress(pct, message)
        if self.client and self.game_id:
            try:
                self.client.progress(self.game_id, "processing", pct, message)
            except Exception as e:  # progress is best effort
                log.warning("progress not sent: %s", e)

    def run(self, video: Path, reuse: bool = True, rescore: bool = False) -> dict:
        from . import video as vid

        if self.client and self.game_id:
            self._load_lineups()
        from . import appearance, reid
        self.looks = appearance.load(self.looks_file())
        self.gallery = reid.load(self.looks_file("reid"))

        self.video_stem = video.stem
        cache = self.workdir / f"{video.stem}.detections.json"
        if reuse and cache.exists():
            self._load(cache)
            self._rehalf()  # the court calibration may have changed since the scan
            self.progress(85, "Używam zapisanych wykryć")
            if (self.cal.has_scoreboard and (rescore or not self.readings)) or not self.replay_checked:
                self._read_scores(video, vid)
                self._save(cache)
        else:
            self.scanning = True
            try:
                self._scan(video, vid)
            finally:
                self.scanning = False
            self._save(cache)

        self.progress(88, "Szukam gwizdka sędziego w dźwięku")
        try:
            from . import whistle
            self.whistles = whistle.detect(whistle.load_audio(str(video)), 16000)
        except Exception as e:
            log.warning("whistle detection skipped: %s", e)

        result = self.analyse()
        if getattr(self, "learned", None):
            from . import appearance
            appearance.save(self.looks_file(), self.learned)
        if getattr(self, "learned_prints", None):
            from . import reid
            reid.save(self.looks_file("reid"), self.learned_prints)
        self._export_crops()
        report_path = self.workdir / f"{video.stem}.report.json"
        report_path.write_text(json.dumps(result, indent=2), encoding="utf-8")
        log.info("report: %s", report_path)

        if self.client and self.game_id:
            self.publish(result, final=True)
        return result

    def _scan(self, video: Path, vid) -> None:
        from .court import NetLine, homography
        from .detect import BallDetector

        w, h, _, duration = vid.info(video)
        net = NetLine(homography(self.cal.court, w, h, self.cal.court_ref)) if self.cal.has_court else None
        detector = BallDetector(self.s, self.device)
        ocr = None
        if self.cal.has_scoreboard:
            from .scoreboard import OcrReader
            ocr = OcrReader(gpu=self.device not in (None, "cpu"))

        batch, times = [], []
        next_ocr = 0.0
        want_people = self.s.players and self.cal.has_court
        next_pos = [0.0]
        next_num = [0.0]
        jerseys = None
        if want_people and self.s.jerseys:
            try:
                from .jersey import JerseyReader
                jerseys = JerseyReader(ocr.reader if ocr else None, gpu=self.device not in (None, "cpu"))
            except Exception as e:  # numbers are extra: positions still work without OCR
                log.warning("jersey numbers off: %s", e)
        follower = None
        if want_people and self.s.tracker == "botsort":
            from . import reid
            if reid.available():
                try:
                    follower = reid.ReidTracker(self.device, self.s.reid_model, self.s.sample_fps)
                except Exception as e:  # the simple tracker still works
                    log.warning("BoT-SORT off: %s", e)
            else:
                log.info("BoxMOT not installed (pip install .[tracking]): simple tracker")
        court_h = homography(self.cal.court, 1, 1, self.cal.court_ref) if want_people else None
        poser = None
        if want_people and self.s.pose:
            try:
                from .pose import PoseReader
                poser = PoseReader(self.s.pose_model, self.device, self.s.detect_size, self.s.person_conf)
            except Exception as e:  # facing then comes from the movement
                log.warning("YOLO-pose off: %s", e)
        crops_dir = self.crops_dir() if want_people and self.s.crops else None
        next_pose = [0.0]
        next_crop = [0.0]
        last_pct = -1
        last_publish = 0

        def flush():
            for t, frame, (found, persons) in zip(times, batch, detector.detect_all(batch, people=want_people)):
                keep = want_people and t >= next_pos[0]
                people = [Person(x, y, c, w_, h_) for x, y, c, w_, h_ in persons] if want_people else []
                if follower is not None:
                    try:
                        follower.update(frame, people, court_h, keep=keep)  # every frame: ids stay stable
                    except Exception as e:
                        log.debug("BoT-SORT frame skipped: %s", e)
                if keep:
                    if t >= next_num[0]:
                        if jerseys:
                            jerseys.read(frame, people)
                        try:
                            from .appearance import signature
                            for person in people:
                                person.sig = signature(frame, person)
                        except Exception as e:  # looks are extra
                            log.debug("no signature: %s", e)
                        next_num[0] = t + self.s.jersey_every_s
                    if poser is not None and t >= next_pose[0]:
                        try:
                            poser.read(frame, people)
                        except Exception as e:
                            log.debug("no pose: %s", e)
                        next_pose[0] = t + self.s.pose_every_s
                    if crops_dir is not None and t >= next_crop[0]:
                        try:
                            from .dataset import save_crops
                            save_crops(frame, people, t, crops_dir, court_h)
                        except Exception as e:  # training material is extra
                            log.debug("no crops: %s", e)
                        next_crop[0] = t + self.s.crops_every_s
                    self.people.append((t, people))
                    next_pos[0] = t + self.s.positions_every_s
                if found is None:
                    continue
                x, y, conf = found
                self.obs.append(ral.BallObs(t, x, y, conf, net.half(x * w, y * h) if net else None))
            batch.clear()
            times.clear()

        self.progress(5, "Analiza obrazu")
        for t, frame in vid.frames(video, self.s.sample_fps):
            batch.append(frame)
            times.append(t)
            if t >= next_ocr:
                self.readings.append(ocr.read(t, frame, self.cal) if ocr else Reading(t, None, None, is_replay(frame)))
                next_ocr = t + self.s.ocr_every_s
            if len(batch) >= 16:
                flush()
            if duration:
                pct = 5 + int(80 * t / duration)
                if pct != last_pct and pct % 2 == 0:
                    last_pct = pct
                    self.progress(pct, f"Analiza obrazu: {int(t // 60)} z {int(duration // 60)} min")
                if self.client and self.game_id and pct - last_publish >= 10:
                    # stream partial results: actions are idempotent by external_id
                    last_publish = pct
                    try:
                        self.publish(self.analyse(), final=False)
                    except Exception as e:
                        log.warning("partial publish failed: %s", e)
        if batch:
            flush()
        if follower is not None:
            self.prints = follower.fingerprints()

    def _rehalf(self) -> None:
        """Court half of every ball position from the current calibration (coordinates are normalised)."""
        from .court import NetLine, homography

        if not self.cal.has_court:
            for o in self.obs:
                o.half = None
            return
        net = NetLine(homography(self.cal.court, 1, 1, self.cal.court_ref))
        for o in self.obs:
            o.half = net.half(o.x, o.y)

    def _read_scores(self, video: Path, vid) -> None:
        """Only the score overlay and replay badge, once per second (minutes instead of a full scan)."""
        from .scoreboard import OcrReader

        _, _, _, duration = vid.info(video)
        ocr = OcrReader(gpu=self.device not in (None, "cpu")) if self.cal.has_scoreboard else None
        self.readings = []
        last = -1
        for t, frame in vid.frames(video, 1.0 / self.s.ocr_every_s):
            self.readings.append(ocr.read(t, frame, self.cal) if ocr else Reading(t, None, None, is_replay(frame)))
            pct = int(100 * t / duration) if duration else 0
            if pct // 5 != last:
                last = pct // 5
                ok = sum(1 for r in self.readings if r.left is not None and r.right is not None)
                self.on_progress(pct, f"Odczyt wyniku: {int(t // 60)} min, udane odczyty {ok}/{len(self.readings)}")

    def analyse(self) -> dict:
        s = self.s
        spans = replay_spans(self.readings)
        live = _outside(self.obs, spans)
        candidates = ral.rally_candidates(ral.activity(ral.drop_static(live, s), s), s)
        candidates = ral.trim_with_whistles(candidates, self.whistles)
        scores = stabilise(self.readings, self.cal, s)
        rallies, sets = ral.build(candidates, scores, s, self.cal.our_side_set1, self.first_serve)
        numbered = ral.assign_servers(rallies, self.lineups)
        tracking = self.tracking(spans)
        rotation_matches = looks_matched = 0
        if tracking is not None:
            for track, n in self.labels.items():  # the analyst's corrections weigh most
                for _ in range(3):
                    tracking.vote(track, n, 1.0)
        if tracking is not None and self.lineups:
            from .rotation import rotation_votes
            rotation_matches = rotation_votes(rallies, tracking, self.lineups, self.cal.our_side_set1, self.liberos, self.middles)
        reid_matched = model_matched = 0
        if tracking is not None:
            ours = self._ours(tracking, sets)
            trusted = dict(tracking.numbers)  # analyst, rotations, OCR: what the crops are labelled with
            reid_matched = self._reid(tracking, ours)
            looks_matched = self._appearance(tracking, ours)
            model_matched = self._player_model_votes(tracking, ours)
            self.last = (tracking, ours, trusted)
        frames = self.player_frames(sets, tracking)
        receptions, serve_extra = [], {}
        if tracking is not None:
            from .touches import ball_actions
            receptions, serve_extra = ball_actions(rallies, live, tracking, self.cal.our_side_set1)
        return {
            "sets": [asdict(x) for x in sets],
            "rallies": act.report(rallies),
            "actions": act.serve_actions(rallies, serve_extra) + receptions,
            "frames": frames,
            "stats": {
                "score_reads_ok": sum(1 for r in self.readings if r.left is not None and r.right is not None),
                "score_reads": len(self.readings),
                "ball_detections": len(self.obs),
                "replay_seconds": sum(1 for r in self.readings if r.replay),
                "plays": len(candidates),
                "score_changes": len(scores),
                "whistles": len(self.whistles),
                "rallies": len(rallies),
                "serves_with_player": numbered,
                "rallies_with_video_time": sum(1 for r in rallies if r.start is not None),
                "position_frames": len(frames),
                "numbered_tracks": len(tracking.numbers) if tracking else 0,
                "rotation_matches": rotation_matches,
                "looks_matched": looks_matched,
                "reid_matched": reid_matched,
                "model_matched": model_matched,
                "receptions": len(receptions),
                "serves_with_point": len(serve_extra),
            },
        }

    def tracking(self, replays):
        """Players tracked on the court (needs the court calibration and the people detections)."""
        if not self.cal.has_court or not self.people:
            return None
        from .court import homography

        h = homography(self.cal.court, 1, 1, self.cal.court_ref)
        return track_players(self.people, h, replays)

    def _ours(self, tracking, sets) -> set[int]:
        """Track ids of our team: on our half for most of the time they were seen."""
        from .players import team_of

        starts = [(x.number, x.video_start) for x in sets if x.video_start is not None]
        side: dict[int, list[str]] = {}
        for t, seen in tracking.samples:
            for s in seen:
                side.setdefault(s.track, []).append(team_of(s.y, t, starts, self.cal.our_side_set1))
        return {k for k, v in side.items() if v.count("us") * 2 > len(v)}

    def _reid(self, tracking, ours: set[int]) -> int:
        """Learn the re-ID fingerprint of every numbered player of ours, then name unnamed tracks by it."""
        if not self.prints:
            return 0
        from . import reid

        prints = reid.track_fingerprints(tracking, self.prints)
        mine = {k: n for k, n in tracking.numbers.items() if k in ours and (not self.roster or n in self.roster)}
        known = reid.learn(prints, mine, self.gallery)
        matched = reid.reid_votes(tracking, prints, known, self.roster or None, ours)
        self.learned_prints = known
        return matched

    def _appearance(self, tracking, ours: set[int]) -> int:
        """Learn the look of every numbered player of ours, then name our unnamed tracks by look."""
        from . import appearance
        from .court import homography

        h = homography(self.cal.court, 1, 1, self.cal.court_ref)
        looks = appearance.track_looks(tracking, h)
        mine = {k: n for k, n in tracking.numbers.items() if k in ours and (not self.roster or n in self.roster)}
        known = appearance.learn(looks, mine, self.looks)
        matched = appearance.appearance_votes(tracking, looks, known, self.roster or None, ours)
        self.learned = known  # learned only from numbers that did not come from the looks themselves
        return matched

    def crops_dir(self) -> Path:
        return self.workdir / "crops" / self.video_stem

    def dataset_dir(self) -> Path:
        return self.workdir / (f"dataset-team{self.team_id}" if self.team_id else "dataset")

    def _player_model_votes(self, tracking, ours: set[int]) -> int:
        """Votes of our own trained player model (train-players), when there is one."""
        path = self.looks_file("players").with_suffix(".pt")
        if self.scanning or not path.exists() or not self.crops_dir().exists():
            return 0
        from . import dataset

        try:
            if getattr(self, "_model", None) is None:
                self._model = dataset.PlayerModel(path, self.device)
            return dataset.model_votes(tracking, self.crops_dir(), self._model.probs, ours, self.roster or None)
        except Exception as e:  # the model is extra
            log.warning("player model skipped: %s", e)
            return 0

    def _export_crops(self) -> None:
        """Label this video's crops with the numbers now known: material for train-players."""
        if self.last is None or not self.crops_dir().exists():
            return
        from . import dataset

        tracking, ours, trusted = self.last
        n = dataset.export(tracking, trusted, self.crops_dir(), self.dataset_dir(), self.video_stem,
                           set(self.labels), self.roster or None, ours)
        log.info("zbiór do nauki / training crops: +%d in %s", n, self.dataset_dir())

    def looks_file(self, kind: str = "looks") -> Path:
        return self.workdir / (f"{kind}-team{self.team_id}.json" if self.team_id else f"{kind}.json")

    def player_frames(self, sets, tracking) -> list[dict]:
        """Player positions in court metres for the 2D view."""
        if tracking is None:
            return []
        starts = [(x.number, x.video_start) for x in sets if x.video_start is not None]
        return build_frames([], None, starts, self.cal.our_side_set1, tracking=tracking)

    def publish(self, result: dict, final: bool) -> None:
        assert self.client and self.game_id
        for st in result["sets"]:
            side = ral.our_half(st["number"], self.cal.our_side_set1) if self.cal.has_court else None
            self.client.put_set(self.game_id, st["number"], st["us"], st["them"], st["video_start"], our_side=side)
        if result["actions"]:
            self.client.post_actions(self.game_id, result["actions"])
        outcomes = act.rally_outcomes(result["rallies"])
        if outcomes:
            self.client.post_rallies(self.game_id, outcomes)
        if final and result.get("frames"):
            try:
                self.client.post_frames(self.game_id, result["frames"])
            except Exception as e:  # positions are extra: the stats must still be completed
                log.warning("player positions not sent: %s", e)
        if final:
            n = result["stats"]["rallies"]
            sets = ", ".join(f"{x['us']}:{x['them']}" for x in result["sets"]) or "brak tablicy wyników"
            self.client.progress(self.game_id, "completed", 100, f"Etap 1: {n} wymian, sety {sets}")

    def _load_lineups(self) -> None:
        """Starting rotations and first server per set, as entered in VVA (Match > Starting lineups)."""
        assert self.client and self.game_id
        try:
            game = self.client.game(self.game_id).get("data", {})
        except Exception as e:  # analysis still works without it
            log.warning("lineups not loaded: %s", e)
            return
        for st in game.get("sets", []):
            n = int(st["number"])
            if st.get("we_serve_first") is not None:
                self.first_serve[n] = bool(st["we_serve_first"])
            if st.get("libero_reception") is not None or st.get("libero_defense") is not None:
                self.liberos[n] = (st.get("libero_reception"), st.get("libero_defense"))
            by_pos = {int(e["rotation_position"]): e.get("number") for e in st.get("lineup", [])}
            if by_pos:
                self.lineups[n] = [by_pos.get(p) for p in range(1, 7)]
        self.middles = {int(p["number"]) for p in game.get("roster", []) if p.get("position") == "middle" and p.get("number") is not None}
        self.roster = {int(p["number"]) for p in game.get("roster", []) if p.get("number") is not None}
        self.team_id = (game.get("team") or {}).get("id")
        self.labels = {int(k): int(v) for k, v in (game.get("tracks") or {}).items() if v is not None}
        if self.lineups:
            log.info("ustawienia z VVA / lineups: %s", {k: v for k, v in sorted(self.lineups.items())})

    # ------------------------------------------------------------------ cache

    def _save(self, path: Path) -> None:
        data = {
            "obs": [[o.t, o.x, o.y, o.conf, o.half] for o in self.obs],
            "readings": [[r.t, r.left, r.right, int(r.replay)] for r in self.readings],
            "people": [[t, [[round(p.x, 4), round(p.y, 4), round(p.conf, 2), round(p.w, 4), round(p.h, 4), p.num, round(p.num_conf, 2), p.sig, p.tid,
                             p.crop, p.sh]
                            for p in ps]] for t, ps in self.people],
            "prints": {str(k): v for k, v in self.prints.items()},
        }
        path.write_text(json.dumps(data), encoding="utf-8")

    def _load(self, path: Path) -> None:
        data = json.loads(path.read_text(encoding="utf-8"))
        self.obs = [ral.BallObs(t, x, y, c, h) for t, x, y, c, h in data["obs"]]
        self.readings = [Reading(r[0], r[1], r[2], bool(r[3]) if len(r) > 3 else False) for r in data["readings"]]
        self.people = [(t, [Person(*p) for p in ps]) for t, ps in data.get("people", [])]
        self.prints = {int(k): v for k, v in data.get("prints", {}).items()}
        self.replay_checked = not data["readings"] or len(data["readings"][0]) > 3


def _outside(obs: list[ral.BallObs], spans: list[tuple[float, float]]) -> list[ral.BallObs]:
    """Ball positions outside the given (sorted) time spans."""
    if not spans:
        return obs
    out, i = [], 0
    for o in sorted(obs, key=lambda o: o.t):
        while i < len(spans) and spans[i][1] < o.t:
            i += 1
        if i < len(spans) and spans[i][0] <= o.t <= spans[i][1]:
            continue
        out.append(o)
    return out
