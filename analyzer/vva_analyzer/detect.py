"""Ball detection with a YOLO model (COCO class 32 'sports ball' out of the box).

The pretrained model finds a volleyball reasonably well from a fixed camera; a model
fine-tuned on your own matches (stage 2) can be dropped in with --model.
"""

from __future__ import annotations

from .config import Settings

SPORTS_BALL = 32


class BallDetector:
    def __init__(self, s: Settings, device: str | None = None):
        from ultralytics import YOLO

        self.s = s
        self.model = YOLO(s.model)
        self.device = device
        names = getattr(self.model, "names", {}) or {}
        # a custom model trained only on volleyballs has a single class 0
        self.classes = [SPORTS_BALL] if names.get(SPORTS_BALL) == "sports ball" else None

    def detect(self, batch: list) -> list[tuple[float, float, float] | None]:
        """Best ball per frame as (x, y, conf) normalised, or None."""
        results = self.model.predict(
            batch, imgsz=self.s.detect_size, conf=self.s.ball_conf, classes=self.classes,
            device=self.device, half=self.device not in (None, "cpu"), verbose=False,
        )
        out: list[tuple[float, float, float] | None] = []
        for r in results:
            if r.boxes is None or len(r.boxes) == 0:
                out.append(None)
                continue
            best = int(r.boxes.conf.argmax())
            x, y, _, _ = r.boxes.xywhn[best].tolist()
            out.append((float(x), float(y), float(r.boxes.conf[best])))
        return out
