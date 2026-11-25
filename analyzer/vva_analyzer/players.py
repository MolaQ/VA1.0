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
    `sig`: how the player looks (appearance.py), taken now and then.
    `tid`: the id BoT-SORT gave this box (reid.py); the tracks then follow it instead of distance.
    `sh`: the shoulders seen by YOLO-pose (pose.py); they give the facing better than movement.
    """

    x: float
    y: float
    conf: float = 1.0
    w: float = 0.0
    h: float = 0.0
    num: int | None = None
    num_conf: float = 0.0
    sig: list[float] | None = None  # look: shoes + head colours (appearance.py)
    tid: int | None = None  # BoT-SORT track id on every frame (reid.py), when it ran
    crop: str | None = None  # file of this person's crop (dataset.py), when one was cut out
    sh: list[float] | None = None  # shoulders from YOLO-pose: left x, y, right x, y (normalised)


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
    epoch: int = 0
    by_id: dict[tuple[int, int], Track] = field(default_factory=dict)

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

    def follow(self, t: float, points: list) -> list[tuple[Track, "Person | None"]]:
        """Like `match`, but the people carry a BoT-SORT id: keep one track per id.

        Ids are given new track numbers after `restart()` (a replay), so a player seen again
        after a replay is not joined to the old dot by an id BoT-SORT kept through the replay."""
        seen: list[tuple[Track, Person | None]] = []
        for x, y, *rest in points:
            p = rest[0] if rest else None
            if p is None or p.tid is None:
                continue  # BoT-SORT did not confirm this box yet
            key = (self.epoch, p.tid)
            tr = self.by_id.get(key)
            if tr is None:
                tr = Track(self.next_id, x, y, t, facing=_towards_net(y))
                self.next_id += 1
                self.by_id[key] = tr
            else:
                self._move(tr, t, x, y)
            tr.vote(p)
            seen.append((tr, p))
        return seen

    def restart(self) -> None:
        self.tracks.clear()
        self.epoch += 1

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


def body_facing(h: np.ndarray, p: Person, min_m: float = 0.15) -> float | None:
    """Facing from the shoulders: the shoulder line moved down to the feet and mapped onto the
    floor; forward is the left->right shoulder vector turned 90 degrees to the left (from above).
    None when the shoulders are too close together to tell (seen exactly from the side)."""
    if not p.sh or len(p.sh) != 4:
        return None
    lx, ly, rx, ry = p.sh
    drop = p.y - (ly + ry) / 2  # shoulders -> feet height in the picture
    a = to_court(h, lx, ly + drop)
    b = to_court(h, rx, ry + drop)
    if not all(math.isfinite(v) for v in (*a, *b)):
        return None
    vx, vy = b[0] - a[0], b[1] - a[1]
    if math.hypot(vx, vy) < min_m:
        return None
    return heading(-vy, vx)


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

    def around(self, t: float, within: float = 0.6) -> list[tuple[float, list[Seen]]]:
        """The samples just before and just after t (each within `within` seconds), with their times."""
        before = after = None
        for st, seen in self.samples:
            if st <= t and t - st <= within:
                before = (st, seen)
            elif st > t and st - t <= within:
                after = (st, seen)
                break
        return [x for x in (before, after) if x is not None]

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
    # BoT-SORT ids from the scan (reid.py) are better than joining dots by distance
    by_id = any(p.tid is not None for _, people in samples for p in people)
    in_replay = False
    for t, people in sorted(samples, key=lambda s: s[0]):
        if any(a <= t <= b for a, b in replays):
            if not in_replay:
                tracker.restart()  # a replay shows another camera angle: start the tracks again
            in_replay = True
            continue
        in_replay = False
        seen = []
        points = to_court_points(h, people)
        for tr, person in (tracker.follow(t, points) if by_id else tracker.match(t, points)):
            if person is not None and person.sh and (f := body_facing(h, person)) is not None:
                tr.facing = f  # the shoulders say where the body faces, also when standing still
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


@dataclass
class _Span:
    track: int
    t0: float
    t1: float
    first: tuple[float, float]
    last: tuple[float, float]
    n: int


def stitch(tracking: Tracking, prints: dict[int, list[float]] | None = None, max_gap: float = 4.0,
           min_sim: float = 0.6, speed: float = 3.5, slack: float = 1.5) -> int:
    """Join track pieces of one player: a track that ends, and another that starts soon after
    near the same spot on the same half (and, when known, with a similar re-ID fingerprint).

    The later piece takes the id of the earlier one (so the ids are stable between runs and
    the analyst's corrections keep pointing at the same dots); its votes are added. Returns how
    many pieces were joined."""
    import bisect

    spans: dict[int, _Span] = {}
    for t, seen in tracking.samples:
        for s in seen:
            sp = spans.get(s.track)
            if sp is None:
                spans[s.track] = _Span(s.track, t, t, (s.x, s.y), (s.x, s.y), 1)
            else:
                sp.t1, sp.last, sp.n = t, (s.x, s.y), sp.n + 1
    vec = {k: np.asarray(v, dtype=np.float32) for k, v in (prints or {}).items()}

    def cost(a: _Span, b: _Span) -> float | None:
        gap = b.t0 - a.t1
        if gap <= 0 or gap > max_gap:
            return None
        if (a.last[1] < NET_Y) != (b.first[1] < NET_Y):
            return None  # players don't change halves during play
        d = math.hypot(b.first[0] - a.last[0], b.first[1] - a.last[1])
        if d > speed * gap + slack:
            return None
        c = d / (speed * gap + slack) + gap / max_gap
        if a.track in vec and b.track in vec:
            sim = float(vec[a.track] @ vec[b.track])
            if sim < min_sim:
                return None
            c += 2 * (1 - sim)
        return c

    pairs = []
    by_end = sorted(spans.values(), key=lambda s: s.t1)
    ends = [s.t1 for s in by_end]
    for b in spans.values():
        # only pieces that ended shortly before b started
        for a in by_end[bisect.bisect_left(ends, b.t0 - max_gap):bisect.bisect_left(ends, b.t0)]:
            if a.track != b.track and (c := cost(a, b)) is not None:
                pairs.append((c, a.track, b.track))
    pairs.sort()
    succ: dict[int, int] = {}
    pred: dict[int, int] = {}
    for _, a, b in pairs:
        if a in succ or b in pred:
            continue
        succ[a], pred[b] = b, a
    if not pred:
        return 0

    def root(k: int) -> int:
        while k in pred:
            k = pred[k]
        return k

    # each piece has at most one follower and starts after its predecessor ended: chains never overlap
    new = {k: root(k) for k in pred}
    for _, seen in tracking.samples:
        for s in seen:
            if s.track in new:
                s.track = new[s.track]
    for k, r in new.items():
        src, dst = tracking.tracks.pop(k, None), tracking.tracks.get(r)
        if src is not None and dst is not None:
            for n, v in src.votes.items():
                dst.votes[n] = dst.votes.get(n, 0.0) + v
                dst.reads[n] = dst.reads.get(n, 0) + src.reads.get(n, 0)
    tracking.numbers = {k: n for k, tr in tracking.tracks.items() if (n := tr.number()) is not None}
    return len(new)


def smooth(tracking: Tracking, half_window: float = 0.6) -> None:
    """Steadier dots: each position becomes the median of the same track's positions within
    ±half_window seconds (feet points jump with every box: arms up, a jump, a player behind).
    The median keeps real direction changes, unlike an average."""
    by_track: dict[int, list[tuple[float, Seen]]] = {}
    for t, seen in tracking.samples:
        for s in seen:
            by_track.setdefault(s.track, []).append((t, s))
    for pts in by_track.values():
        if len(pts) < 3:
            continue
        raw = [(t, s.x, s.y) for t, s in pts]
        lo = 0
        for i, (t, s) in enumerate(pts):
            while raw[lo][0] < t - half_window:
                lo += 1
            hi = i
            while hi + 1 < len(raw) and raw[hi + 1][0] <= t + half_window:
                hi += 1
            window = raw[lo:hi + 1]
            if len(window) >= 3:
                s.x = round(float(np.median([w[1] for w in window])), 2)
                s.y = round(float(np.median([w[2] for w in window])), 2)
