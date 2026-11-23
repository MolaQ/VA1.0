"""Court geometry: image pixels -> metres on a 9 x 18 m court seen from above.

Court coordinates: x across the court 0..9, y along it 0..18, y=0 the near end line
(closest to the camera), y=9 the net, y=18 the far end line.
"""

from __future__ import annotations

import numpy as np

from .config import Point

COURT_W = 9.0
COURT_L = 18.0
NET_Y = 9.0


def homography(corners: list[Point], width: int, height: int) -> np.ndarray:
    """3x3 matrix mapping image pixels to court metres. `corners` normalised, order NL, NR, FR, FL."""
    src = np.array([[x * width, y * height] for x, y in corners], dtype=np.float64)
    dst = np.array([[0, 0], [COURT_W, 0], [COURT_W, COURT_L], [0, COURT_L]], dtype=np.float64)
    return _find_homography(src, dst)


def _find_homography(src: np.ndarray, dst: np.ndarray) -> np.ndarray:
    # Direct linear transform for exactly four points (no OpenCV needed).
    rows = []
    for (x, y), (u, v) in zip(src, dst):
        rows.append([-x, -y, -1, 0, 0, 0, u * x, u * y, u])
        rows.append([0, 0, 0, -x, -y, -1, v * x, v * y, v])
    _, _, vt = np.linalg.svd(np.array(rows))
    h = vt[-1].reshape(3, 3)
    return h / h[2, 2]


def to_court(h: np.ndarray, x: float, y: float) -> tuple[float, float]:
    p = h @ np.array([x, y, 1.0])
    return float(p[0] / p[2]), float(p[1] / p[2])


def from_court(h: np.ndarray, x: float, y: float) -> tuple[float, float]:
    p = np.linalg.inv(h) @ np.array([x, y, 1.0])
    return float(p[0] / p[2]), float(p[1] / p[2])


class NetLine:
    """Which half of the court a ball is over, from its image position.

    The floor homography cannot place a ball in the air, but the vertical plane of the net
    is seen almost edge-on from the stands at the side of the court, so in the image it is
    close to the line through the two ends of the centre line. The side of that line tells
    the half ("near" = the half of the first two clicked corners, "far" = the other one).
    Works best with the camera roughly level with the net (typical for a side stand).
    """

    def __init__(self, h: np.ndarray):
        self.a = from_court(h, 0.0, NET_Y)
        self.b = from_court(h, COURT_W, NET_Y)
        ref = from_court(h, COURT_W / 2, NET_Y / 2)
        self.near_sign = 1.0 if self._cross(*ref) >= 0 else -1.0

    def _cross(self, x: float, y: float) -> float:
        (ax, ay), (bx, by) = self.a, self.b
        return (bx - ax) * (y - ay) - (by - ay) * (x - ax)

    def half(self, x: float, y: float) -> str:
        return "near" if self._cross(x, y) * self.near_sign >= 0 else "far"


def net_crossings(halves: list[str], confirm: int = 2) -> int:
    """Times a ball passes over the net; a new half must be seen `confirm` times in a row."""
    count, current, streak, candidate = 0, None, 0, None
    for h in halves:
        if current is None:
            current = h
            continue
        if h == current:
            streak, candidate = 0, None
            continue
        if h == candidate:
            streak += 1
        else:
            candidate, streak = h, 1
        if streak >= confirm:
            current, streak, candidate = h, 0, None
            count += 1
    return count


def zone(x: float, y: float) -> int | None:
    """Volleyball zone 1..6 of a point, from the point of view of the team on that half."""
    if not (0 <= x <= COURT_W and 0 <= y <= COURT_L):
        return None
    near = y < NET_Y
    depth = y if near else COURT_L - y  # distance from own end line
    across = x if near else COURT_W - x  # left -> right as the team faces the net
    col = min(2, int(across // 3))
    front = depth >= 6.0  # within 3 m of the net
    if front:
        return (4, 3, 2)[col]
    return (5, 6, 1)[col]
