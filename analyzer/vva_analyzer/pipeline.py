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

        cache = self.workdir / f"{video.stem}.detections.json"
        if reuse and cache.exists():
            self._load(cache)
            self._rehalf()  # the court calibration may have changed since the scan
            self.progress(85, "Używam zapisanych wykryć")
            if (self.cal.has_scoreboard and (rescore or not self.readings)) or not self.replay_checked:
                self._read_scores(video, vid)
                self._save(cache)
        else:
            self._scan(video, vid)
            self._save(cache)

        self.progress(88, "Szukam gwizdka sędziego w dźwięku")
        try:
            from . import whistle
            self.whistles = whistle.detect(whistle.load_audio(str(video)), 16000)
        except Exception as e:
            log.warning("whistle detection skipped: %s", e)

        result = self.analyse()
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
        last_pct = -1
        last_publish = 0

        def flush():
            for t, frame, (found, persons) in zip(times, batch, detector.detect_all(batch, people=want_people)):
                if want_people and t >= next_pos[0]:
                    people = [Person(x, y, c, w_, h_) for x, y, c, w_, h_ in persons]
                    if jerseys and t >= next_num[0]:
                        jerseys.read(frame, people)
                        next_num[0] = t + self.s.jersey_every_s
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
            by_pos = {int(e["rotation_position"]): e.get("number") for e in st.get("lineup", [])}
            if by_pos:
                self.lineups[n] = [by_pos.get(p) for p in range(1, 7)]
        if self.lineups:
            log.info("ustawienia z VVA / lineups: %s", {k: v for k, v in sorted(self.lineups.items())})

    # ------------------------------------------------------------------ cache

    def _save(self, path: Path) -> None:
        data = {
            "obs": [[o.t, o.x, o.y, o.conf, o.half] for o in self.obs],
            "readings": [[r.t, r.left, r.right, int(r.replay)] for r in self.readings],
            "people": [[t, [[round(p.x, 4), round(p.y, 4), round(p.conf, 2), round(p.w, 4), round(p.h, 4), p.num, round(p.num_conf, 2)]
                            for p in ps]] for t, ps in self.people],
        }
        path.write_text(json.dumps(data), encoding="utf-8")

    def _load(self, path: Path) -> None:
        data = json.loads(path.read_text(encoding="utf-8"))
        self.obs = [ral.BallObs(t, x, y, c, h) for t, x, y, c, h in data["obs"]]
        self.readings = [Reading(r[0], r[1], r[2], bool(r[3]) if len(r) > 3 else False) for r in data["readings"]]
        self.people = [(t, [Person(*p) for p in ps]) for t, ps in data.get("people", [])]
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
