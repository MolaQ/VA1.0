"""Jersey numbers from the players' torsos, with the same EasyOCR the scoreboard uses.

From a side stand the number is ~20-40 px tall in 1080p, so most single reads fail or are
wrong. That's fine: reads are only votes. The tracker adds them up per player track and a
number is given only when several reads agree (players.Track.number). Players that are too
small in the picture (far side of the court) are skipped.
"""

from __future__ import annotations

from .players import Person

MIN_BOX_PX = 90  # people shorter than this in the picture have unreadable numbers


def torso_box(p: Person, width: int, height: int) -> tuple[int, int, int, int] | None:
    """Pixel box (x0, y0, x1, y1) of the upper torso, where the number is printed (front and back)."""
    bw, bh = p.w * width, p.h * height
    if bh < MIN_BOX_PX or bw <= 0:
        return None
    bottom = p.y * height
    cx = p.x * width
    x0, x1 = int(max(0, cx - 0.35 * bw)), int(min(width, cx + 0.35 * bw))
    y0, y1 = int(max(0, bottom - 0.85 * bh)), int(min(height, bottom - 0.5 * bh))
    if x1 - x0 < 8 or y1 - y0 < 8:
        return None
    return x0, y0, x1, y1


def best_number(results) -> tuple[int | None, float]:
    """EasyOCR results [(box, text, conf)] -> the most confident 1-2 digit number 1..99."""
    best: tuple[int | None, float] = (None, 0.0)
    for _, text, conf in results:
        digits = "".join(ch for ch in str(text) if ch.isdigit())
        if 1 <= len(digits) <= 2 and 1 <= int(digits) <= 99 and float(conf) > best[1]:
            best = (int(digits), float(conf))
    return best


class JerseyReader:
    def __init__(self, reader=None, gpu: bool = True):
        if reader is None:
            import easyocr  # heavy import, only when numbers are read

            reader = easyocr.Reader(["en"], gpu=gpu, verbose=False)
        self.reader = reader

    def read(self, frame, people: list[Person]) -> None:
        """Fill `num` / `num_conf` of the people whose number may be readable."""
        import cv2

        height, width = frame.shape[:2]
        for p in people:
            box = torso_box(p, width, height)
            if box is None:
                continue
            x0, y0, x1, y1 = box
            crop = cv2.cvtColor(frame[y0:y1, x0:x1], cv2.COLOR_BGR2GRAY)
            scale = max(1.0, 96 / max(1, crop.shape[0]))
            crop = cv2.resize(crop, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
            try:
                results = self.reader.readtext(crop, allowlist="0123456789", detail=1, paragraph=False)
            except Exception:  # a bad crop must not stop the analysis
                continue
            p.num, p.num_conf = best_number(results)
