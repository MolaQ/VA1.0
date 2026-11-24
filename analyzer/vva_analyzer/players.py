"""Player positions for the 2D court view: people seen by YOLO -> court metres -> tracks.

The ball model already runs on every sampled frame; asking it for COCO class 0 ("person")
as well costs nothing extra. For each person the middle of the bottom edge of the box
(the feet) is mapped onto the floor with the calibration homography, so the position is
in court metres: x 0..9 across, y 0..18 along (0 = near end line, 9 = net).

People far outside the court (bench, referees on the stand, line judges, spectators)
are dropped. A simple nearest-neighbour tracker gives every player a track id so the
web app can interpolate between frames and the analyst can name a track once
("this is #7") when the AI cannot read jersey numbers yet.

Facing (the small triangle on the dot) is estimated from the direction of movement;
a player standing still is assumed to face the net. Degrees: 0 = towards the far end
line, 90 = towards x = 9.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import numpy as np

from .court import COURT_L, COURT_W, NET_Y, to_court

# How far outside the court lines a person still counts as a player (serving zone, chasing balls).
MARGIN_SIDE = 1.2
MARGIN_END = 3.5
MAX_ON_COURT = 14
MIN_NUM_CONF = 0.5  # OCR reads below this confidence are ignored
# Referees stand at the net posts, just outside the side lines: not players.
REF_ZONE = 1.5


@dataclass
class Person:
    """A person box seen in the image: feet point (bottom centre) and box size, normalised 0..1.

    `num` / `num_conf`: jersey number read from the torso (jersey.py), when it was tried.
    """

    x: float
    y: float
    conf: float = 1.0
    w: float = 0.0
    h: float = 0.0
    num: int | None = None
    num_conf: float = 0.0


@dataclass
class Track:
    id: int
    x: float
    y: float
    t: float
    vx: float = 0.0
    vy: float = 0.0
    facing: float | None = None
    hits: int = 1
    votes: dict[int, float] = field(default_factory=dict)  # jersey number -> summed OCR confidence
    reads: dict[int, int] = field(default_factory=dict)  # jersey number -> how many reads

    def vote(self, p: "Person | None") -> None:
        if p is not None and p.num is not None and 1 <= p.num <= 99 and p.num_conf >= MIN_NUM_CONF:
            self.votes[p.num] = self.votes.get(p.num, 0.0) + p.num_conf
            self.reads[p.num] = self.reads.get(p.num, 0) + 1

    def number(self) -> int | None:
        """The jersey number when the reads agree: at least two reads and 60 % of the weight."""
        if not self.votes:
            return None
        best = max(self.votes, key=self.votes.get)
        total = sum(self.votes.values())
        return best if self.reads[best] >= 2 and self.votes[best] >= 0.6 * total else None


@dataclass
class Tracker:
    """Greedy nearest-neighbour association in court metres."""

    max_speed: float = 7.0  # m/s, a sprinting player
    slack: float = 0.6  # metres of detection noise
    max_missing_s: float = 1.6
    min_speed_facing: float = 0.8  # m/s; slower than this keeps the previous facing
    tracks: list[Track] = field(default_factory=list)
    next_id: int = 1

    def update(self, t: float, points: list) -> list[Track]:
        """`points`: (x, y) or (x, y, person) in court metres. Returns the tracks seen now;
        a track's jersey votes take the person's OCR read."""
        return [tr for tr, _ in self.match(t, points)]

    def match(self, t: float, points: list) -> list[tuple[Track, "Person | None"]]:
        self.tracks = [tr for tr in self.tracks if t - tr.t <= self.max_missing_s]
        pairs = []
        for i, (x, y, *_) in enumerate(points):
            for tr in self.tracks:
                dt = max(1e-3, t - tr.t)
                d = math.hypot(x - tr.x, y - tr.y)
                if d <= self.max_speed * dt + self.slack:
                    pairs.append((d, i, tr))
        pairs.sort(key=lambda p: p[0])

        def person(i: int):
            return points[i][2] if len(points[i]) > 2 else None

        used_points: set[int] = set()
        used_tracks: set[int] = set()
        seen: list[tuple[Track, Person | None]] = []
        for _, i, tr in pairs:
            if i in used_points or tr.id in used_tracks:
                continue
            used_points.add(i)
            used_tracks.add(tr.id)
            self._move(tr, t, points[i][0], points[i][1])
            tr.vote(person(i))
            seen.append((tr, person(i)))

        for i, (x, y, *_) in enumerate(points):
            if i in used_points:
                continue
            tr = Track(self.next_id, x, y, t, facing=_towards_net(y))
            tr.vote(person(i))
            self.next_id += 1
            self.tracks.append(tr)
            seen.append((tr, person(i)))
        return seen

    def _move(self, tr: Track, t: float, x: float, y: float) -> None:
        dt = max(1e-3, t - tr.t)
        vx, vy = (x - tr.x) / dt, (y - tr.y) / dt
        # smooth the velocity: feet points jitter by a few centimetres every frame
        tr.vx, tr.vy = 0.5 * tr.vx + 0.5 * vx, 0.5 * tr.vy + 0.5 * vy
        if math.hypot(tr.vx, tr.vy) >= self.min_speed_facing:
            tr.facing = heading(tr.vx, tr.vy)
        elif tr.facing is None:
            tr.facing = _towards_net(y)
        tr.x, tr.y, tr.t = x, y, t
        tr.hits += 1


def heading(dx: float, dy: float) -> float:
    """Degrees, 0 = +y (towards the far end line), 90 = +x."""
    return round(math.degrees(math.atan2(dx, dy)) % 360, 0)


def _towards_net(y: float) -> float:
    return 0.0 if y < NET_Y else 180.0


def on_court(x: float, y: float) -> bool:
    if (x < 0 or x > COURT_W) and abs(y - NET_Y) < REF_ZONE:
        return False  # first / second referee at the net posts
    return -MARGIN_SIDE <= x <= COURT_W + MARGIN_SIDE and -MARGIN_END <= y <= COURT_L + MARGIN_END


def to_court_points(h: np.ndarray, people: list[Person]) -> list[tuple[float, float, Person]]:
    """Feet points (normalised image coords, h built for a 1x1 image) -> court metres of the players."""
    pts = []
    for p in sorted(people, key=lambda p: -p.conf):
        x, y = to_court(h, p.x, p.y)
        if math.isfinite(x) and math.isfinite(y) and on_court(x, y):
            pts.append((x, y, p))
    # crowded frame (subs warming up by the court): keep the ones closest to the court centre
    pts.sort(key=lambda q: abs(q[0] - COURT_W / 2) / COURT_W + abs(q[1] - COURT_L / 2) / COURT_L)
    return pts[:MAX_ON_COURT]


def team_of(y: float, t: float, set_starts: list[tuple[int, float]], our_side_set1: str) -> str:
    """'us' or 'them' from the half a player stands in and the set being played at time t."""
    from .rallies import our_half

    number = 1
    for n, start in sorted(set_starts, key=lambda s: s[1]):
        if start <= t:
            number = n
    ours = our_half(number, our_side_set1)
    half = "near" if y < NET_Y else "far"
    return "us" if half == ours else "them"


@dataclass
class Seen:
    """One tracked player at one sample time: court metres plus the image box it came from."""

    track: int
    x: float
    y: float
    f: float | None
    person: Person | None


@dataclass
class Tracking:
    samples: list[tuple[float, list[Seen]]]
    numbers: dict[int, int]  # track id -> jersey number agreed by the reads (OCR, rotations)
    tracks: dict[int, Track] = field(default_factory=dict)

    def vote(self, track: int, number: int, weight: float) -> None:
        """An extra read for a track (e.g. from the rotation at the serve), then re-agree its number."""
        tr = self.tracks.get(track)
        if tr is None:
            return
        tr.vote(Person(0, 0, num=number, num_conf=weight))
        n = tr.number()
        if n is None:
            self.numbers.pop(track, None)
        else:
            self.numbers[track] = n

    def at(self, t: float, within: float = 0.6) -> list[Seen]:
        """Players at the sample nearest to t."""
        best, gap = [], within
        for st, seen in self.samples:
            if abs(st - t) <= gap:
                best, gap = seen, abs(st - t)
        return best


def track_players(
    samples: list[tuple[float, list[Person]]],
    h: np.ndarray,
    replays: list[tuple[float, float]] | None = None,
) -> Tracking:
    tracker = Tracker()
    out: list[tuple[float, list[Seen]]] = []
    tracks: dict[int, Track] = {}
    replays = replays or []
    for t, people in sorted(samples, key=lambda s: s[0]):
        if any(a <= t <= b for a, b in replays):
            tracker.tracks.clear()  # a replay shows another camera angle: start the tracks again
            continue
        seen = []
        for tr, person in tracker.match(t, to_court_points(h, people)):
            tracks[tr.id] = tr
            seen.append(Seen(tr.id, round(tr.x, 2), round(tr.y, 2), tr.facing, person))
        if seen:
            out.append((t, seen))
    numbers = {k: n for k, tr in tracks.items() if (n := tr.number()) is not None}
    return Tracking(out, numbers, tracks)


def build_frames(
    samples: list[tuple[float, list[Person]]],
    h: np.ndarray,
    set_starts: list[tuple[int, float]],
    our_side_set1: str = "near",
    replays: list[tuple[float, float]] | None = None,
    tracking: Tracking | None = None,
) -> list[dict]:
    """Frames for the VVA API: [{t, players: [{team, n, k, x, y, f}]}], one per sample time."""
    tracking = tracking or track_players(samples, h, replays)
    return [
        {
            "t": round(t, 2),
            "players": [
                {
                    "team": team_of(p.y, t, set_starts, our_side_set1),
                    "n": tracking.numbers.get(p.track),
                    "k": p.track,
                    "x": p.x,
                    "y": p.y,
                    "f": p.f,
                }
                for p in seen
            ],
        }
        for t, seen in tracking.samples
    ]
