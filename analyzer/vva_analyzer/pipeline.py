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
from .scoreboard import Reading, stabilise

log = logging.getLogger("vva")

Progress = Callable[[int, str], None]


class Job:
    def __init__(self, cal: Calibration, s: Settings, workdir: Path, client: VvaClient | None = None,
                 game_id: int | None = None, device: str | None = None, on_progress: Progress | None = None):
        self.cal, self.s, self.workdir = cal, s, workdir
        self.client, self.game_id, self.device = client, game_id, device
        self.on_progress = on_progress or (lambda p, m: log.info("%3d%% %s", p, m))
        self.obs: list[ral.BallObs] = []
        self.readings: list[Reading] = []
        self.whistles: list[float] = []

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

        cache = self.workdir / f"{video.stem}.detections.json"
        if reuse and cache.exists():
            self._load(cache)
            self.progress(85, "Używam zapisanych wykryć")
            if self.cal.has_scoreboard and (rescore or not self.readings):
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
        last_pct = -1
        last_publish = 0

        def flush():
            for t, found in zip(times, detector.detect(batch)):
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
            if ocr and t >= next_ocr:
                self.readings.append(ocr.read(t, frame, self.cal))
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

    def _read_scores(self, video: Path, vid) -> None:
        """Only the score overlay, once per second (minutes instead of a full scan)."""
        from .scoreboard import OcrReader

        _, _, _, duration = vid.info(video)
        ocr = OcrReader(gpu=self.device not in (None, "cpu"))
        self.readings = []
        last = -1
        for t, frame in vid.frames(video, 1.0 / self.s.ocr_every_s):
            self.readings.append(ocr.read(t, frame, self.cal))
            pct = int(100 * t / duration) if duration else 0
            if pct // 5 != last:
                last = pct // 5
                ok = sum(1 for r in self.readings if r.left is not None and r.right is not None)
                self.on_progress(pct, f"Odczyt wyniku: {int(t // 60)} min, udane odczyty {ok}/{len(self.readings)}")

    def analyse(self) -> dict:
        s = self.s
        candidates = ral.rally_candidates(ral.activity(ral.drop_static(self.obs, s), s), s)
        candidates = ral.trim_with_whistles(candidates, self.whistles)
        scores = stabilise(self.readings, self.cal, s)
        rallies, sets = ral.build(candidates, scores, s, self.cal.our_side_set1)
        return {
            "sets": [asdict(x) for x in sets],
            "rallies": act.report(rallies),
            "actions": act.serve_actions(rallies),
            "stats": {
                "score_reads_ok": sum(1 for r in self.readings if r.left is not None and r.right is not None),
                "score_reads": len(self.readings),
                "ball_detections": len(self.obs),
                "plays": len(candidates),
                "score_changes": len(scores),
                "whistles": len(self.whistles),
                "rallies": len(rallies),
                "rallies_with_video_time": sum(1 for r in rallies if r.start is not None),
            },
        }

    def publish(self, result: dict, final: bool) -> None:
        assert self.client and self.game_id
        for st in result["sets"]:
            self.client.put_set(self.game_id, st["number"], st["us"], st["them"], st["video_start"])
        if result["actions"]:
            self.client.post_actions(self.game_id, result["actions"])
        if final:
            n = result["stats"]["rallies"]
            sets = ", ".join(f"{x['us']}:{x['them']}" for x in result["sets"]) or "brak tablicy wyników"
            self.client.progress(self.game_id, "completed", 100, f"Etap 1: {n} wymian, sety {sets}")

    # ------------------------------------------------------------------ cache

    def _save(self, path: Path) -> None:
        data = {
            "obs": [[o.t, o.x, o.y, o.conf, o.half] for o in self.obs],
            "readings": [[r.t, r.left, r.right] for r in self.readings],
        }
        path.write_text(json.dumps(data), encoding="utf-8")

    def _load(self, path: Path) -> None:
        data = json.loads(path.read_text(encoding="utf-8"))
        self.obs = [ral.BallObs(t, x, y, c, h) for t, x, y, c, h in data["obs"]]
        self.readings = [Reading(t, a, b) for t, a, b in data["readings"]]
