"""Who is who on our half, from the rotation at the moment of the serve.

At the serve both teams must stand in rotation order (the overlap rule): each front-row
player closer to the net than the back-row player behind them (4-5, 3-6, 2-1), and in each
row left before middle before right (4-3-2, 5-6-1). We know our rotation for every rally
(starting lineup + side-outs, rallies.assign_servers) and so the jersey number in every
zone. Six tracked players on our half are matched to the six zones: of all orders allowed
by the overlap rule, the one closest to the zones' base spots. When we serve, the server
(zone 1) is the one behind the end line and is not bound by the rule.

Every rally gives every visible player one vote for a number; the votes add up with the
jersey OCR votes in the tracker, so a few wrong matches (a setter hiding at the net) are
outvoted over a set.
"""

from __future__ import annotations

import itertools

from .players import Seen, Tracking
from .rallies import Rally, our_half
from .touches import team_frame

# base spots in our frame (x as we face the net, y 0 = end line, 9 = net)
BASE = {1: (7.5, 2.0), 2: (7.5, 7.0), 3: (4.5, 7.0), 4: (1.5, 7.0), 5: (1.5, 2.0), 6: (4.5, 2.0)}
FRONT_BACK = [(4, 5), (3, 6), (2, 1)]
LEFT_RIGHT = [(4, 3), (3, 2), (5, 6), (6, 1)]
VOTE = 0.55  # a rotation match counts a bit more than the weakest accepted OCR read


def zone_numbers(lineup: list[int | None], rotation: int, serving: bool,
                 libero: int | None = None, middles: set[int] | None = None) -> dict[int, int | None]:
    """Jersey number in zones 1..6. `lineup`: numbers in starting positions I..VI.

    The libero replaces a middle blocker in the back row (zone 1 only when we receive)."""
    middles = middles or set()
    out: dict[int, int | None] = {}
    for zone in range(1, 7):
        n = lineup[(zone - 1 + rotation) % 6]
        back = zone in (5, 6) or (zone == 1 and not serving)
        if libero is not None and back and n in middles:
            n = libero
        out[zone] = n
    return out


def _allowed(order: dict[int, tuple[float, float]], serving: bool) -> bool:
    for front, back in FRONT_BACK:
        if serving and back == 1:
            continue
        if order[front][1] <= order[back][1]:
            return False
    for left, right in LEFT_RIGHT:
        if serving and 1 in (left, right):
            continue
        if order[left][0] >= order[right][0]:
            return False
    return True


def match_zones(players: list[tuple[float, float]], serving: bool) -> dict[int, int] | None:
    """Index of the player standing in each zone, or None when no order fits the rule."""
    if len(players) != 6:
        return None
    zones = [1, 2, 3, 4, 5, 6]
    fixed: dict[int, int] = {}
    free = list(range(6))
    if serving:
        server = min(free, key=lambda i: players[i][1])  # the one furthest from the net
        fixed[1] = server
        free.remove(server)
        zones = [2, 3, 4, 5, 6]

    best, best_cost = None, float("inf")
    for perm in itertools.permutations(free):
        assign = dict(zip(zones, perm)) | fixed
        order = {z: players[i] for z, i in assign.items()}
        if not _allowed(order, serving):
            continue
        cost = sum((order[z][0] - BASE[z][0]) ** 2 + (order[z][1] - BASE[z][1]) ** 2 for z in zones)
        if cost < best_cost:
            best, best_cost = assign, cost
    return best


def rotation_votes(rallies: list[Rally], tracking: Tracking, lineups: dict[int, list[int | None]],
                   our_side_set1: str, liberos: dict[int, tuple[int | None, int | None]] | None = None,
                   middles: set[int] | None = None) -> int:
    """Add a vote per visible player and rally; returns how many rallies were matched.

    `liberos[set]` = (reception libero, defence libero) jersey numbers."""
    liberos = liberos or {}
    matched = 0
    for r in rallies:
        lineup = lineups.get(r.set)
        if r.start is None or r.rotation is None or r.server is None or not lineup or len(lineup) != 6:
            continue
        half = our_half(r.set, our_side_set1)
        # our half, including the server behind our end line
        ours: list[Seen] = [s for s in tracking.at(r.start) if (s.y < 9) == (half == "near")]
        serving = r.server == "us"
        if len(ours) != 6:
            continue
        frame = [team_frame(s.x, s.y, half) for s in ours]
        assign = match_zones(frame, serving)
        if assign is None:
            continue
        reception, defence = liberos.get(r.set, (None, None))
        libero = (defence or reception) if serving else (reception or defence)
        numbers = zone_numbers(lineup, r.rotation, serving, libero, middles)
        for zone, i in assign.items():
            n = numbers.get(zone)
            if n is not None:
                tracking.vote(ours[i].track, n, VOTE)
        matched += 1
    return matched
