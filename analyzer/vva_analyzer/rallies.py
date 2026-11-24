"""Stage 1 logic: ball activity + scoreboard changes -> sets, rallies, server and serve outcome.

Pure Python on plain data, so it is fully unit-tested without a GPU or a video.

Key volleyball rules used:
- every rally ends with exactly one point (rally-point scoring);
- the team that won the previous rally serves the next one;
- a set ends at 25 (15 in the 5th) with a two-point lead; the overlay resets to 0:0.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from .config import Settings
from .court import net_crossings


@dataclass
class BallObs:
    t: float  # seconds in the video
    x: float  # normalised image coords 0..1
    y: float
    conf: float = 1.0
    half: str | None = None  # "near" / "far" court half, when the camera is calibrated


@dataclass
class Activity:
    start: float
    end: float
    obs: list[BallObs] = field(default_factory=list)

    @property
    def duration(self) -> float:
        return self.end - self.start


@dataclass
class ScoreEvent:
    t: float
    us: int
    them: int


@dataclass
class Rally:
    set: int
    number: int  # rally number within the set, from 1
    start: float | None
    end: float | None
    winner: str | None = None  # "us" / "them"
    server: str | None = None
    score_before: tuple[int, int] = (0, 0)
    serve_side: str | None = None  # "near" / "far": where the serve came from (camera view)
    crossings: int | None = None
    serve_grade: str | None = None  # VVA grade of the serve: # ! =
    server_number: int | None = None  # jersey number of our server (from the starting rotation)
    confidence: float = 0.5
    # No play matched (start is None): the rally happened between the previous scoreboard
    # update and this one, so the previous update is where to start looking in the video.
    approx_time: float | None = None

    @property
    def duration(self) -> float | None:
        return None if self.start is None or self.end is None else self.end - self.start


@dataclass
class SetResult:
    number: int
    us: int
    them: int
    video_start: float | None


# ---------------------------------------------------------------- ball activity

def drop_static(obs: list[BallObs], s: Settings) -> list[BallObs]:
    """Remove detections of balls lying still (spare balls, a ball in the corner of the hall)."""
    buckets: dict[tuple[int, int], list[BallObs]] = {}
    for o in obs:
        buckets.setdefault((round(o.x * 100), round(o.y * 100)), []).append(o)

    static: set[int] = set()
    for items in buckets.values():
        if len(items) < 3:
            continue
        span = items[-1].t - items[0].t
        if span >= s.static_ball_s and len(items) >= span * s.sample_fps * 0.3:
            static.update(id(o) for o in items)

    return [o for o in obs if id(o) not in static]


def activity(obs: list[BallObs], s: Settings) -> list[Activity]:
    """Merge ball detections into continuous segments of play."""
    segments: list[Activity] = []
    for o in sorted(obs, key=lambda o: o.t):
        if segments and o.t - segments[-1].end <= s.gap_merge_s:
            segments[-1].end = o.t
            segments[-1].obs.append(o)
        else:
            segments.append(Activity(o.t, o.t, [o]))
    return [a for a in segments if a.duration >= s.min_segment_s]


def rally_candidates(segments: list[Activity], s: Settings) -> list[Activity]:
    """Join activity segments separated by short gaps (ball hidden behind players, out of frame)."""
    out: list[Activity] = []
    for a in segments:
        if out and a.start - out[-1].end <= s.rally_gap_s and a.end - out[-1].start <= s.max_rally_s:
            out[-1].end = a.end
            out[-1].obs.extend(a.obs)
        else:
            out.append(Activity(a.start, a.end, list(a.obs)))
    return out


def serve_side(candidate: Activity) -> str | None:
    """Half the serve came from: the first ball positions, before it goes over the net."""
    halves = [o.half for o in candidate.obs[:5] if o.half is not None]
    if not halves:
        return None
    return max(("near", "far"), key=halves.count) if len(halves) > 1 else halves[0]


def crossings(candidate: Activity) -> int | None:
    halves = [o.half for o in candidate.obs if o.half is not None]
    return net_crossings(halves) if len(halves) >= 2 else None


# ---------------------------------------------------------------- sets & rallies

def our_half(set_number: int, our_side_set1: str) -> str:
    """Teams change ends after every set."""
    other = "far" if our_side_set1 == "near" else "near"
    return our_side_set1 if set_number % 2 == 1 else other


def build(
    candidates: list[Activity],
    scores: list[ScoreEvent],
    s: Settings,
    our_side_set1: str = "near",
    first_serve: dict[int, bool] | None = None,
) -> tuple[list[Rally], list[SetResult]]:
    """Main entry: rallies with winners/servers when the scoreboard was read, else from activity only."""
    if not scores:
        return _from_activity_only(candidates, our_side_set1), []

    rallies: list[Rally] = []
    sets: list[SetResult] = []
    set_no = 1
    prev = ScoreEvent(0.0, 0, 0)
    used: set[int] = set()

    def close_set() -> None:
        if prev.us or prev.them:
            first = next((r.start for r in rallies if r.set == set_no and r.start is not None), None)
            sets.append(SetResult(set_no, prev.us, prev.them, first))

    def advance(ev: ScoreEvent) -> None:
        nonlocal prev
        d_us, d_them = ev.us - prev.us, ev.them - prev.them

        if d_us < 0 or d_them < 0:
            # Operator correction on the overlay: undo the last rally of the side that went down.
            for side, d in (("us", d_us), ("them", d_them)):
                for _ in range(-d if d < 0 else 0):
                    idx = next((i for i in range(len(rallies) - 1, -1, -1) if rallies[i].set == set_no and rallies[i].winner == side), None)
                    if idx is not None:
                        rallies.pop(idx)
            prev = ScoreEvent(ev.t, ev.us, ev.them)
            return

        points = ["us"] * d_us + ["them"] * d_them
        for i, winner in enumerate(points):
            # Only the last point of a jump can be matched with the play that preceded the update.
            cand_idx = _match(candidates, used, prev.t, ev.t, s) if i == len(points) - 1 else None
            cand = candidates[cand_idx] if cand_idx is not None else None
            if cand_idx is not None:
                used.add(cand_idx)

            number = sum(1 for r in rallies if r.set == set_no) + 1
            before = (prev.us + points[:i].count("us"), prev.them + points[:i].count("them"))
            rally = Rally(
                set=set_no,
                number=number,
                start=cand.start if cand else None,
                end=cand.end if cand else None,
                winner=winner,
                score_before=before,
                serve_side=serve_side(cand) if cand else None,
                crossings=crossings(cand) if cand else None,
                confidence=0.8 if cand else 0.4,
                approx_time=None if cand else round(prev.t, 2),
            )
            rally.server = _server(rallies, rally, our_side_set1, first_serve)
            rally.serve_grade = _serve_grade(rally, s)
            rallies.append(rally)

        prev = ScoreEvent(ev.t, ev.us, ev.them)

    for ev in scores:
        if ev.us == 0 and ev.them == 0 and _finished(prev):
            if prev.us or prev.them:
                last = _missing_last_point(prev, set_no, s)
                if last is not None:
                    advance(last)  # the overlay was reset before the winning point was read
                close_set()
                set_no += 1
            prev = ScoreEvent(ev.t, 0, 0)
            continue
        advance(ev)

    close_set()
    return rallies, sets


def assign_servers(rallies: list[Rally], lineups: dict[int, list[int | None]]) -> int:
    """Our server's number from the starting rotation of each set.

    `lineups[set]` lists the jersey numbers in rotation positions I..VI. We rotate every time
    we win a rally the opponent served (side-out), so the k-th rotation brings position k+1
    to serve. Substitutions keep the position, so a substitute shows up as the starter's number.
    Returns how many serves got a number.
    """
    done = 0
    rot: dict[int, int] = {}
    prev: dict[int, Rally] = {}
    for r in rallies:
        lineup = lineups.get(r.set)
        k = rot.setdefault(r.set, 0)
        p = prev.get(r.set)
        if r.server == "us" and p is not None and p.server == "them":
            k = rot[r.set] = k + 1
        prev[r.set] = r
        if r.server == "us" and lineup and len(lineup) == 6 and lineup[k % 6] is not None:
            r.server_number = lineup[k % 6]
            done += 1
    return done


def _missing_last_point(score: ScoreEvent, set_no: int, s: Settings) -> ScoreEvent | None:
    """20:24 followed by 0:0 means the set ended 20:25, the last update was just not read."""
    target = s.tiebreak_points if set_no == 5 else s.set_points
    hi, lo = max(score.us, score.them), min(score.us, score.them)
    if hi >= target and hi - lo >= 2:
        return None
    if hi + 1 >= target and hi + 1 - lo >= 2:
        us_lead = score.us > score.them
        return ScoreEvent(score.t + 1.0, score.us + us_lead, score.them + (not us_lead))
    return None


def _finished(score: ScoreEvent, min_points: int = 15) -> bool:
    """A real set end (25, 21 or 15 point sets, two-point lead). Any other reset is a correction."""
    return max(score.us, score.them) >= min_points and abs(score.us - score.them) >= 2


def _match(candidates: list[Activity], used: set[int], after: float, at: float, s: Settings) -> int | None:
    """The latest unused play that ended before the score update (within the overlay lag)."""
    best = None
    for i, c in enumerate(candidates):
        if i in used or c.end <= after or c.end > at + 2.0 or c.end < at - s.score_lag_s:
            continue
        best = i
    return best


def _server(previous: list[Rally], rally: Rally, our_side_set1: str, first_serve: dict[int, bool] | None = None) -> str | None:
    same_set = [r for r in previous if r.set == rally.set]
    if same_set:
        return same_set[-1].winner
    if first_serve and first_serve.get(rally.set) is not None:
        return "us" if first_serve[rally.set] else "them"
    if rally.serve_side is not None:
        return "us" if rally.serve_side == our_half(rally.set, our_side_set1) else "them"
    return None


def _serve_grade(r: Rally, s: Settings) -> str | None:
    """Stage 1 heuristic: very short rallies are aces or serve errors."""
    if r.server is None or r.winner is None:
        return None
    dur = r.duration
    server_won = r.winner == r.server
    if r.crossings is not None:
        if server_won and r.crossings == 1 and (dur is None or dur <= s.ace_max_s + 2):
            r.confidence = min(r.confidence, 0.7)
            return "#"
        if not server_won and r.crossings <= 1 and (dur is None or dur <= s.ace_max_s + 2):
            # 0 = into the net, 1 = over but out (the receivers never played it back)
            r.confidence = min(r.confidence, 0.7)
            return "="
    elif dur is not None:
        if server_won and dur <= s.ace_max_s:
            r.confidence = min(r.confidence, 0.55)
            return "#"
        if not server_won and dur <= s.serve_error_max_s:
            r.confidence = min(r.confidence, 0.55)
            return "="
    r.confidence = min(r.confidence, 0.4)
    return "!"


def _from_activity_only(candidates: list[Activity], our_side_set1: str) -> list[Rally]:
    """No scoreboard: a rally list with times and (if calibrated) who served, but no winners or sets."""
    out = []
    for i, c in enumerate(candidates, start=1):
        side = serve_side(c)
        out.append(Rally(
            set=1, number=i, start=c.start, end=c.end, serve_side=side, crossings=crossings(c),
            server=None if side is None else ("us" if side == our_half(1, our_side_set1) else "them"),
            confidence=0.3,
        ))
    return out


def trim_with_whistles(candidates: list[Activity], whistles: list[float]) -> list[Activity]:
    """End a play at the referee's whistle (balls rolling after the point keep the detector busy)."""
    for c in candidates:
        after_serve = [w for w in whistles if c.start + 1.0 < w < c.end]
        if after_serve:
            c.end = after_serve[0]
            c.obs = [o for o in c.obs if o.t <= c.end]
    return candidates
