"""Reading the score overlay (Sportcam and similar apps burn it into the video).

`stabilise` is pure logic (tested); `OcrReader` wraps EasyOCR on the calibrated boxes.
"""

from __future__ import annotations

from dataclasses import dataclass

from .config import Box, Calibration, Settings
from .rallies import ScoreEvent


@dataclass
class Reading:
    t: float
    left: int | None
    right: int | None


def stabilise(readings: list[Reading], cal: Calibration, s: Settings) -> list[ScoreEvent]:
    """Turn noisy per-second OCR reads into score changes (us/them).

    A new score is accepted once read `ocr_stable_reads` times in a row. Normal changes
    (+1 for one side, reset to 0:0, a -1 correction) are accepted at once; anything else
    (a jump after missed reads, OCR confusing 1 and 7) needs three times the evidence.
    The event time is the first read of the new score.
    """
    events: list[ScoreEvent] = []
    current: tuple[int, int] = (0, 0)
    pending: tuple[int, int] | None = None
    pending_t = 0.0
    count = 0

    for r in readings:
        if r.left is None or r.right is None:
            continue
        us, them = (r.left, r.right) if cal.our_score == "left" else (r.right, r.left)
        value = (us, them)

        if value == current:
            pending, count = None, 0
            continue
        if value != pending:
            pending, pending_t, count = value, r.t, 0
        count += 1

        needed = s.ocr_stable_reads if _normal_step(current, value) else s.ocr_stable_reads * 3
        if count >= needed:
            events.append(ScoreEvent(pending_t, us, them))
            current, pending, count = value, None, 0

    return events


def _normal_step(a: tuple[int, int], b: tuple[int, int]) -> bool:
    d = (b[0] - a[0], b[1] - a[1])
    return b == (0, 0) or d in {(1, 0), (0, 1), (-1, 0), (0, -1)}


def crop(frame, box: Box):
    h, w = frame.shape[:2]
    x, y, bw, bh = box
    return frame[int(y * h): int((y + bh) * h), int(x * w): int((x + bw) * w)]


class OcrReader:
    """Digits from the two score boxes, with EasyOCR (GPU when available)."""

    def __init__(self, gpu: bool = True):
        import easyocr  # heavy import, only when the scoreboard is used

        self.reader = easyocr.Reader(["en"], gpu=gpu, verbose=False)

    def number(self, image) -> int | None:
        import cv2

        if image.size == 0:
            return None
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        scale = max(1.0, 64 / max(1, gray.shape[0]))
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
        texts = self.reader.readtext(gray, allowlist="0123456789", detail=0)
        digits = "".join(texts).strip()
        if not digits or len(digits) > 2:
            return None
        return int(digits)

    def read(self, t: float, frame, cal: Calibration) -> Reading:
        assert cal.score_left and cal.score_right
        return Reading(t, self.number(crop(frame, cal.score_left)), self.number(crop(frame, cal.score_right)))
