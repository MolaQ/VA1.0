"""Suggest the calibration points automatically: the two sidelines and the two 3 m attack lines.

Works on one frame from a side-stand camera. White floor lines are isolated with a top-hat
filter, straight segments found with a Hough transform, then grouped into lines. The
attack lines are the strongest across-court lines nearest to the net post on each side.
The user can always correct the result in the calibration window.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

import numpy as np


TOP_CUT = 0.35  # ignore the top of the frame (ceiling, banners, stands)


@dataclass
class Line:
    a: float  # y = a * x + b for near-horizontal lines; x = a * y + b for across-court lines
    b: float
    weight: float


def _segments(frame) -> np.ndarray:
    import cv2

    h, w = frame.shape[:2]
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    hsv = cv2.cvtColor(frame, cv2.COLOR_BGR2HSV)
    tophat = cv2.morphologyEx(gray, cv2.MORPH_TOPHAT, cv2.getStructuringElement(cv2.MORPH_RECT, (15, 15)))
    mask = ((tophat > 30) & (hsv[..., 1] < 70)).astype(np.uint8) * 255
    mask[: int(h * TOP_CUT)] = 0  # ceiling, banners
    segs = cv2.HoughLinesP(mask, 1, np.pi / 180, 60, minLineLength=int(w * 0.04), maxLineGap=25)
    return np.empty((0, 4)) if segs is None else segs.reshape(-1, 4).astype(float)


def _group(items: list[tuple[float, float, float, float]], tol: float) -> list[Line]:
    """items: (key_at_1, key_at_2, a, b, length) clustered on the two keys."""
    groups: list[list] = []
    for it in sorted(items, key=lambda t: -t[4]):
        for g in groups:
            if abs(g[0][0] - it[0]) < tol and abs(g[0][1] - it[1]) < tol:
                g.append(it)
                break
        else:
            groups.append([it])
    out = []
    for g in groups:
        wsum = sum(t[4] for t in g)
        out.append(Line(sum(t[2] * t[4] for t in g) / wsum, sum(t[3] * t[4] for t in g) / wsum, wsum))
    return sorted(out, key=lambda l: -l.weight)


def _net_x(frame) -> float | None:
    """The net post: the image column with the most saturated, upright blue/colour pixels below mid-height."""
    import cv2

    h, w = frame.shape[:2]
    hsv = cv2.cvtColor(frame, cv2.COLOR_BGR2HSV)
    lower = hsv[h // 2:, int(w * 0.2): int(w * 0.8)]
    coloured = ((lower[..., 1] > 120) & (lower[..., 2] > 80)).astype(np.float32)
    cols = coloured.sum(axis=0)
    cols = np.convolve(cols, np.ones(9) / 9, mode="same")
    if cols.max() < h * 0.12:
        return None
    return float(np.argmax(cols) + int(w * 0.2))


def _across(x1: float, y1: float, x2: float, y2: float) -> bool:
    angle = abs(math.degrees(math.atan2(y2 - y1, x2 - x1)))
    return min(angle, 180 - angle) > 25


def suggest(frame) -> list[tuple[float, float]] | None:
    """Four normalised points: left attack line far end, its near end, right attack line near end, far end.

    Returns None when the court lines can't be found.
    """
    h, w = frame.shape[:2]
    segs = _segments(frame)
    horiz, across = [], []
    for x1, y1, x2, y2 in segs:
        length = math.hypot(x2 - x1, y2 - y1)
        angle = abs(math.degrees(math.atan2(y2 - y1, x2 - x1)))
        angle = min(angle, 180 - angle)
        if angle < 10:
            a = (y2 - y1) / (x2 - x1)
            b = y1 - a * x1
            horiz.append((a * w * 0.25 + b, a * w * 0.75 + b, a, b, length))
        elif angle > 25:
            a = (x2 - x1) / (y2 - y1)
            b = x1 - a * y1
            across.append((a * h * 0.5 + b, a * h * 0.9 + b, a, b, length))

    sides = _group(horiz, tol=h * 0.012)[:20]
    net = _net_x(frame) or w / 2
    if not sides:
        return None

    def y_at(line: Line, x: float) -> float:
        return line.a * x + line.b

    # Sidelines: across-court lines (end lines, attack lines and their dashed extensions) start
    # or end on them, so segment ends gather along the two sidelines. The bleachers' edge or a
    # banner has no such support.
    ends = [p for x1, y1, x2, y2 in segs
            if abs((x1 + x2) / 2 - net) >= w * 0.03 and _across(x1, y1, x2, y2)
            for p in ((x1, y1), (x2, y2))
            if p[1] > h * (TOP_CUT + 0.03) and 5 < p[0] < w - 5 and p[1] < h - 5]  # not cut by the mask or frame

    def support(line: Line) -> float:
        return sum(1.0 for x, y in ends if abs(y - y_at(line, x)) < h * 0.012) + 0.1 * line.weight / w

    ranked = sorted(sides, key=support, reverse=True)
    pairs = [(a, b) for i, a in enumerate(ranked[:6]) for b in ranked[i + 1:6]
             if abs(y_at(a, w / 2) - y_at(b, w / 2)) > h * 0.12 and abs(a.a - b.a) < 0.05]
    if not pairs:
        return None
    far, near = sorted(max(pairs, key=lambda p: support(p[0]) + support(p[1])), key=lambda l: y_at(l, w / 2))

    def cross(side: Line, ac: Line) -> tuple[float, float]:
        # y = sa x + sb ; x = aa y + ab  ->  y = sa (aa y + ab) + sb
        y = (side.a * ac.b + side.b) / (1 - side.a * ac.a)
        return ac.a * y + ac.b, y

    lines = _group(across, tol=w * 0.015)
    slack = w * 0.01
    # Seen from the side stand, lines across the court lean outwards towards the camera.
    left = [l for l in lines if cross(far, l)[0] < net - w * 0.04 and cross(near, l)[0] <= cross(far, l)[0] + slack]
    right = [l for l in lines if cross(far, l)[0] > net + w * 0.04 and cross(near, l)[0] >= cross(far, l)[0] - slack]
    if not left or not right:
        return None

    def ratio(a: float, b: float) -> float:
        return min(a, b) / max(a, b) if a > 0 and b > 0 else 0.0

    # The two attack lines sit symmetrically around the net; the end lines do too, but further out.
    scored = []
    for la in left:
        for ra in right:
            sym = ratio(net - cross(far, la)[0], cross(far, ra)[0] - net) * \
                ratio(net - cross(near, la)[0], cross(near, ra)[0] - net)
            if sym < 0.6:
                continue
            dist = (cross(far, ra)[0] - cross(far, la)[0]) / w
            scored.append((la, ra, (la.weight + ra.weight) * sym, dist))
    if not scored:
        return None
    best = max(x[2] for x in scored)
    la, ra, _, _ = min((x for x in scored if x[2] >= best * 0.25), key=lambda x: x[3])
    pts = [cross(far, la), cross(near, la), cross(near, ra), cross(far, ra)]
    return [(x / w, y / h) for x, y in pts]
