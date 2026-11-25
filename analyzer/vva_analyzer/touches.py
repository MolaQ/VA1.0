"""Serve -> reception -> set: where the ball was received and where the pass went.

A touch is where the ball, seen in the picture, stops falling and goes up again (y in the
image grows downwards, so its velocity flips from + to -). The player who touched it is
the one whose box the ball is in (or just above, for a set). That player's feet, mapped
onto the court, are the point of the touch: for a reception the contact point (where the
serve arrived), for the set right after it the point where the pass went.

Reception grade from the pass point, and the serve grade paired with it, follow the same
rules as the web app (app/Stats/PassQuality.php):
  #  within 1 m of the target (between zones 3 and 2, 1 m off the net)
  +  in front of the 3 m line, within 3.5 m of the target
  !  on the court behind the 3 m line
  -  off the court
  /  over the net
"""

from __future__ import annotations

import math
from dataclasses import dataclass

from .court import COURT_L, COURT_W, NET_Y
from .players import Seen, Tracking
from .rallies import BallObs, Rally, our_half

TARGET = (6.0, 8.0)  # receiving team's frame: x as they face the net, y 0 = own end line
SERVE_FOR_RECEPTION = {"=": "#", "/": "/", "-": "+", "!": "!", "+": "-", "#": "-"}


@dataclass
class Touch:
    t: float
    x: float  # ball, normalised image coords
    y: float
    half: str | None


def find_touches(obs: list[BallObs], min_speed: float = 0.15, gap: float = 0.5) -> list[Touch]:
    """Moments the ball goes from falling to rising (normalised image units per second)."""
    pts = sorted(obs, key=lambda o: o.t)
    out: list[Touch] = []
    for a, b, c in zip(pts, pts[1:], pts[2:]):
        if b.t - a.t > gap or c.t - b.t > gap:
            continue
        v1 = (b.y - a.y) / max(1e-3, b.t - a.t)
        v2 = (c.y - b.y) / max(1e-3, c.t - b.t)
        if v1 > min_speed and v2 < -min_speed:
            if out and b.t - out[-1].t < 0.4:
                continue  # one contact, several noisy frames
            out.append(Touch(b.t, b.x, b.y, b.half))
    return out


def toucher(touch: Touch, tracking: Tracking) -> Seen | None:
    """The tracked player whose box holds the ball (a set is played a bit above the head).

    Positions are sampled twice a second, so the box is taken from the samples just before and
    just after the touch; the further the sample is in time, the more slack (the player moves)."""
    best, best_d = None, math.inf
    for st, seen in tracking.around(touch.t):
        slack = 0.02 + 0.06 * abs(st - touch.t)
        for s in seen:
            p = s.person
            if p is None or p.h <= 0:
                continue
            dx = max(0.0, abs(touch.x - p.x) - p.w * 0.6)
            top, bottom = p.y - p.h * 1.5, p.y
            dy = max(0.0, top - touch.y, touch.y - bottom)
            d = math.hypot(dx, dy)
            if d <= slack and d / slack < best_d:
                best, best_d = s, d / slack
    return best


def team_frame(x: float, y: float, half: str) -> tuple[float, float]:
    """Camera court coords -> frame of the team defending `half` (y 0 = its end line, 9 = net)."""
    return (x, y) if half == "near" else (COURT_W - x, COURT_L - y)


def reception_grade(x: float, y: float) -> str:
    """Grade of a pass ending at (x, y) in the receiving team's frame."""
    if y > NET_Y:
        return "/"
    d = math.hypot(x - TARGET[0], y - TARGET[1])
    if d <= 1.0:
        return "#"
    if y >= 6.0 and d <= 3.5:
        return "+"
    if 0 <= x <= COURT_W and y >= 0:
        return "!"
    return "-"


@dataclass
class ServeReception:
    receiving_half: str
    contact: tuple[float, float] | None = None  # receiving team's frame
    passed: tuple[float, float] | None = None
    receiver_track: int | None = None
    receiver_number: int | None = None
    grade: str | None = None
    t: float | None = None


def serve_reception(rally: Rally, obs: list[BallObs], tracking: Tracking, max_s: float = 6.0) -> ServeReception | None:
    """First touch on the receiving half after the serve, and the next touch there (the set)."""
    if rally.start is None or rally.serve_side is None:
        return None
    receiving = "far" if rally.serve_side == "near" else "near"
    window = [o for o in obs if rally.start + 0.5 <= o.t <= rally.start + max_s]
    touches = [t for t in find_touches(window) if t.half == receiving]
    if not touches:
        return None

    out = ServeReception(receiving, t=touches[0].t)
    first = toucher(touches[0], tracking)
    if first is not None:
        out.contact = team_frame(first.x, first.y, receiving)
        out.receiver_track = first.track
        out.receiver_number = tracking.numbers.get(first.track)
    if len(touches) > 1 and touches[1].t - touches[0].t <= 3.5:
        second = toucher(touches[1], tracking)
        if second is not None and second.track != out.receiver_track:
            out.passed = team_frame(second.x, second.y, receiving)
            out.grade = reception_grade(*out.passed)
    return out


def ball_actions(rallies: list[Rally], obs: list[BallObs], tracking: Tracking, our_side_set1: str) -> tuple[list[dict], dict[tuple[int, int], dict]]:
    """Our receptions as new actions, plus extra fields for our serves keyed by (set, rally).

    Points are sent in OUR team's frame (what VVA stores): for our serve the reception of the
    opponent is on their half, so y > 9.
    """
    receptions: list[dict] = []
    serve_extra: dict[tuple[int, int], dict] = {}
    for r in rallies:
        sr = serve_reception(r, obs, tracking)
        if sr is None or sr.contact is None:
            continue
        ours = our_half(r.set, our_side_set1)
        if r.server == "them" and sr.receiving_half == ours:
            item = {
                "external_id": f"s2-set{r.set}-r{r.number}-reception",
                "set": r.set,
                "rally": r.number,
                **({"player_number": sr.receiver_number} if sr.receiver_number is not None else {}),
                "skill": "reception",
                "grade": sr.grade or "!",
                "video_time": round(sr.t, 2) if sr.t is not None else None,
                "confidence": 0.65 if sr.grade and sr.receiver_number is not None else 0.45,
                "from_x": round(sr.contact[0], 2),
                "from_y": round(sr.contact[1], 2),
                "meta": {"stage": 2, "track": sr.receiver_track},
            }
            if sr.passed is not None:
                item["to_x"], item["to_y"] = round(sr.passed[0], 2), round(sr.passed[1], 2)
            else:
                item["review_note"] = "Nie widać, dokąd poszło przyjęcie"
            receptions.append(item)
        elif r.server == "us" and sr.receiving_half != ours:
            # their frame -> ours: mirror the court
            cx, cy = COURT_W - sr.contact[0], COURT_L - sr.contact[1]
            extra: dict = {"to_x": round(cx, 2), "to_y": round(cy, 2)}
            if sr.grade is not None and r.serve_grade not in ("#", "="):
                extra["grade"] = SERVE_FOR_RECEPTION[sr.grade]
                extra["opponent_reception"] = sr.grade
            serve_extra[(r.set, r.number)] = extra
    return receptions, serve_extra
