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
    replay: bool = False  # the broadcast shows a replay, not live play


REPLAY_BOX = (0.40, 0.03, 0.20, 0.09)  # Sportcam's yellow "REPLAY" badge, top centre


def is_replay(frame, box: Box = REPLAY_BOX, min_share: float = 0.12) -> bool:
    """Sportcam marks replays with a yellow badge; ball activity during a replay isn't live play."""
    import cv2

    roi = crop(frame, box)
    if roi.size == 0:
        return False
    mask = cv2.inRange(cv2.cvtColor(roi, cv2.COLOR_BGR2HSV), (18, 120, 150), (38, 255, 255))
    return float(mask.mean()) / 255.0 >= min_share


def replay_spans(readings: list[Reading], pad: float = 1.5) -> list[tuple[float, float]]:
    spans: list[tuple[float, float]] = []
    for r in readings:
        if not r.replay:
            continue
        if spans and r.t - pad <= spans[-1][1]:
            spans[-1] = (spans[-1][0], r.t + pad)
        else:
            spans.append((r.t - pad, r.t + pad))
    return spans


def stabilise(readings: list[Reading], cal: Calibration, s: Settings) -> list[ScoreEvent]:
    """Turn noisy per-second OCR reads into score changes (us/them).

    Every raw number is first decoded against the current score, because the overlay is
    misread in repeatable ways: a leading 1 read as 7 (72 = 12), a stray digit from the box
    border (51 = 5, 50 = 0), a lost tens digit (3 = 23). A side may only stay, move by one, or
    jump forward a few points (missed reads); going back more than one point is noise.

    A new score is accepted once read `ocr_stable_reads` times in a row; jumps and set resets
    need three times the evidence. A change that is undone within `ocr_revert_s` (warm-up
    flicker, an operator correction) is dropped. The event time is the first read of the score.
    """
    events: list[ScoreEvent] = []
    current: tuple[int, int] = (0, 0)
    pending: tuple[int, int] | None = None
    pending_t = 0.0
    count = 0

    for r in readings:
        if r.left is None or r.right is None:
            continue
        raw = (r.left, r.right) if cal.our_score == "left" else (r.right, r.left)
        value = _decode(raw, current, s)
        if value is None:
            continue

        if value == current:
            pending, count = None, 0
            continue
        if value != pending:
            pending, pending_t, count = value, r.t, 0
        count += 1

        needed = s.ocr_stable_reads if _normal_step(current, value) else s.ocr_stable_reads * 3
        if count >= needed:
            events.append(ScoreEvent(pending_t, *value))
            current, pending, count = value, None, 0

    return _drop_reverts(events, s.ocr_revert_s)


def _decode(raw: tuple[int, int], current: tuple[int, int], s: Settings) -> tuple[int, int] | None:
    """The most plausible score for a raw read, or None when it can't be right."""
    if raw[0] % 10 == 0 and raw[1] % 10 == 0 and raw[0] <= 50 and raw[1] <= 50 \
            and max(current) >= s.set_reset_min and (raw[0] < 20 or raw[1] < 20):
        if raw == (0, 0) or (min(raw) == 0 and max(raw) in (50, 70)):
            return (0, 0)  # a new set (sometimes with a stray 5/7 in front of the zero)
    us = _decode_side(raw[0], current[0], s)
    them = _decode_side(raw[1], current[1], s)
    if us is None or them is None:
        return None
    return (us, them)


def _decode_side(r: int, c: int, s: Settings) -> int | None:
    options = [r]
    if r >= 10:
        options.append(r // 10)  # stray digit after the number (border)
        if r // 10 == 7:
            options.append(10 + r % 10)  # 1 read as 7
        options.append(r % 10)  # stray digit in front
    options += [r % 10 + 10, r % 10 + 20]  # lost tens digit
    near = [x for x in options if c - 1 <= x <= c + 1]
    if near:
        return min(near, key=lambda x: abs(x - c))  # stable sort: the raw read wins ties
    ahead = [x for x in options if c < x <= c + s.ocr_max_jump]
    return min(ahead) if ahead else None


def _drop_reverts(events: list[ScoreEvent], window: float) -> list[ScoreEvent]:
    out = list(events)
    changed = True
    while changed:
        changed = False
        for i in range(len(out) - 1):
            before = (out[i - 1].us, out[i - 1].them) if i else (0, 0)
            if (out[i + 1].us, out[i + 1].them) == before and out[i + 1].t - out[i].t < window:
                del out[i: i + 2]
                changed = True
                break
    return out


def _normal_step(a: tuple[int, int], b: tuple[int, int]) -> bool:
    d = (b[0] - a[0], b[1] - a[1])
    return d in {(1, 0), (0, 1), (-1, 0), (0, -1)}


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
        gray = cv2.copyMakeBorder(gray, 12, 12, 12, 12, cv2.BORDER_REPLICATE)
        _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        for img in (gray, binary):
            texts = self.reader.readtext(img, allowlist="0123456789", detail=0, paragraph=False)
            digits = "".join(texts).strip()
            if digits and len(digits) <= 2:
                return int(digits)
        return None

    def read(self, t: float, frame, cal: Calibration) -> Reading:
        assert cal.score_left and cal.score_right
        return Reading(t, self.number(crop(frame, cal.score_left)), self.number(crop(frame, cal.score_right)),
                       is_replay(frame))
