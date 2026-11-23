"""Ball (and player) detection with a YOLO model (COCO class 32 'sports ball' out of the box).

The pretrained model finds a volleyball reasonably well from a fixed camera; a model
fine-tuned on your own matches (stage 2) can be dropped in with --model.
With the COCO model the same pass also returns people (class 0): their feet points feed
the 2D court view at no extra cost. A custom ball-only model gives no people.
"""

from __future__ import annotations

from .config import Settings

SPORTS_BALL = 32
PERSON = 0


class BallDetector:
    def __init__(self, s: Settings, device: str | None = None):
        from ultralytics import YOLO

        self.s = s
        self.model = YOLO(s.model)
        self.device = device
        names = getattr(self.model, "names", {}) or {}
        # a custom model trained only on volleyballs has a single class 0
        self.coco = names.get(SPORTS_BALL) == "sports ball"
        self.classes = [SPORTS_BALL] if self.coco else None

    def detect(self, batch: list) -> list[tuple[float, float, float] | None]:
        """Best ball per frame as (x, y, conf) normalised, or None."""
        return [ball for ball, _ in self.detect_all(batch, people=False)]

    def detect_all(self, batch: list, people: bool = True):
        """Per frame: (best ball or None, people as [(feet x, feet y, conf)] normalised)."""
        people = people and self.coco
        classes = [SPORTS_BALL, PERSON] if people else self.classes
        results = self.model.predict(
            batch, imgsz=self.s.detect_size, conf=min(self.s.ball_conf, self.s.person_conf) if people else self.s.ball_conf,
            classes=classes, device=self.device, verbose=False,
        )
        out = []
        for r in results:
            ball, persons = None, []
            if r.boxes is not None and len(r.boxes) > 0:
                cls = r.boxes.cls.tolist() if people else [SPORTS_BALL] * len(r.boxes)
                confs = r.boxes.conf.tolist()
                boxes = r.boxes.xywhn.tolist()
                for c, conf, (x, y, w, h) in zip(cls, confs, boxes):
                    if int(c) == PERSON:
                        if conf >= self.s.person_conf:
                            persons.append((float(x), float(y + h / 2), float(conf)))  # feet: bottom centre
                    elif conf >= self.s.ball_conf and (ball is None or conf > ball[2]):
                        ball = (float(x), float(y), float(conf))
            out.append((ball, persons))
        return out
