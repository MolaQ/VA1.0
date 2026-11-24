from vva_analyzer.config import Calibration, Settings
from vva_analyzer.rallies import Activity, BallObs, ScoreEvent, activity, build, drop_static, rally_candidates, trim_with_whistles
from vva_analyzer.scoreboard import Reading, stabilise

S = Settings()


def play(start, end, first_half="near", cross_at=None, fps=12.0):
    """Synthetic ball track: starts on `first_half`, flips half at the given times."""
    obs, t, half = [], start, first_half
    flips = list(cross_at or [])
    while t <= end:
        if flips and t >= flips[0]:
            half = "far" if half == "near" else "near"
            flips.pop(0)
        obs.append(BallObs(t, 0.5, 0.4, 0.6, half))
        t += 1 / fps
    return obs


def test_activity_merges_close_detections_and_drops_short_blips():
    obs = play(10, 14) + play(14.8, 18) + [BallObs(30, 0.1, 0.1)]
    segs = activity(obs, S)
    assert len(segs) == 1
    assert segs[0].start == 10 and round(segs[0].end, 1) == 18.0


def test_static_spare_ball_is_ignored():
    spare = [BallObs(t / 12, 0.9, 0.9) for t in range(0, 12 * 20)]
    moving = [BallObs(5 + t / 12, 0.3 + t * 0.002, 0.4) for t in range(36)]
    kept = drop_static(spare + moving, S)
    assert all(o.x != 0.9 for o in kept)
    assert len(kept) == len(moving)


def test_rally_candidates_join_short_gaps_only():
    segs = [Activity(0, 5), Activity(6.5, 9), Activity(20, 25)]
    c = rally_candidates(segs, S)
    assert [(a.start, a.end) for a in c] == [(0, 9), (20, 25)]


def test_whistle_ends_the_play():
    c = [Activity(0, 15, play(0, 15))]
    trim_with_whistles(c, [9.0])
    assert c[0].end == 9.0 and c[0].obs[-1].t <= 9.0


def test_score_changes_give_winner_server_and_sets():
    # set 1: we serve first (near half), win, then lose; set 2 starts after the reset
    cands = [
        Activity(10, 18, play(10, 18, "near", [11.5, 13, 15])),  # long rally, we win
        Activity(40, 42, play(40, 42, "near", [41])),             # our serve, receivers can't return -> ace
        Activity(70, 71.5, play(70, 71.5, "near")),               # into the net -> serve error, they score
        Activity(300, 310, play(300, 310, "far", [302, 305])),    # set 2, they serve from the far half... we play near->far
    ]
    scores = [
        ScoreEvent(22, 1, 0),
        ScoreEvent(46, 2, 0),
        ScoreEvent(75, 2, 1),
        ScoreEvent(120, 25, 1),  # (rest of the set read in one jump)
        ScoreEvent(200, 0, 0),  # overlay reset: set 2
        ScoreEvent(314, 0, 1),
    ]
    rallies, sets = build(cands, scores, S, our_side_set1="near")

    set1 = [r for r in rallies if r.set == 1]
    set2 = [r for r in rallies if r.set == 2]
    assert [r.winner for r in set1[:3]] == ["us", "us", "them"] and len(set1) == 26
    assert [r.server for r in set1[:3]] == ["us", "us", "us"]
    assert [(r.number, r.winner, r.server) for r in set2] == [(1, "them", "us")]  # set 2: we now defend the far half
    assert rallies[1].serve_grade == "#"
    assert rallies[2].serve_grade == "="
    assert rallies[0].serve_grade == "!"
    assert [(st.number, st.us, st.them) for st in sets] == [(1, 25, 1), (2, 0, 1)]
    assert sets[0].video_start == 10


def test_next_server_is_previous_winner_even_without_court_calibration():
    cands = [Activity(10, 20, [BallObs(10, .5, .5)]), Activity(40, 48, [BallObs(40, .5, .5)])]
    rallies, _ = build(cands, [ScoreEvent(25, 0, 1), ScoreEvent(52, 1, 1)], S)
    assert rallies[0].server is None  # first serve unknown without calibration
    assert rallies[1].server == "them"


def test_overlay_correction_removes_the_last_point():
    cands = [Activity(10, 20, play(10, 20))]
    rallies, sets = build(cands, [ScoreEvent(25, 1, 0), ScoreEvent(30, 0, 0), ScoreEvent(31, 0, 1)], S)
    # 1:0 -> 0:0 is not a finished set, so it is an operator correction, not a new set
    assert [(r.set, r.winner) for r in rallies] == [(1, "them")]
    assert [(st.number, st.us, st.them) for st in sets] == [(1, 0, 1)]


def test_missed_reads_still_count_every_point():
    rallies, sets = build([], [ScoreEvent(30, 3, 1)], S)
    assert len(rallies) == 4 and all(r.start is None for r in rallies)
    # still a place to look in the video: after the previous scoreboard update
    assert all(r.approx_time == 0.0 for r in rallies)
    assert (sets[0].us, sets[0].them) == (3, 1)


def test_stabilise_needs_consistent_reads():
    cal = Calibration(our_score="right")
    reads = [Reading(t, l, r) for t, l, r in [
        (0, 0, 0), (1, 0, 1), (2, 0, 1), (3, 0, 7), (4, 0, 1),  # a single misread 7 is ignored
        (5, 1, 1), (6, 1, 1), (7, None, None), (8, 1, 2), (9, 1, 2),
    ]]
    events = stabilise(reads, cal, S)
    assert [(e.t, e.us, e.them) for e in events] == [(1, 1, 0), (5, 1, 1), (8, 2, 1)]


def _reads(seq, every=1.0, repeat=3):
    """Each (left, right) held for `repeat` one-second reads."""
    out, t = [], 0.0
    for left, right in seq:
        for _ in range(repeat):
            out.append(Reading(t, left, right))
            t += every
    return out


def test_stabilise_decodes_typical_ocr_mistakes():
    cal = Calibration(our_score="left")
    # 11:9 -> 72 means 12, 51 means 5 (border), 3 means 13 (lost tens digit)
    seq = [(0, 0)] + [(i, i // 3) for i in range(1, 12)] + [(72, 4), (12, 51), (3, 5)]
    events = stabilise(_reads(seq, repeat=8), cal, S)
    assert [(e.us, e.them) for e in events][-3:] == [(12, 4), (12, 5), (13, 5)]


def test_stabilise_drops_quick_reverts_and_warmup_flicker():
    cal = Calibration(our_score="left")
    seq = [(0, 0), (1, 0), (0, 0), (1, 0), (0, 0)] + [(0, 0)] * 20 + [(1, 0), (1, 1)]
    events = stabilise(_reads(seq, repeat=5), cal, S)
    assert [(e.us, e.them) for e in events] == [(1, 0), (1, 1)]


def test_stabilise_new_set_with_stray_five():
    cal = Calibration(our_score="left")
    seq = [(i, i) for i in range(0, 25)] + [(25, 23), (50, 0), (50, 1), (52, 1)]
    events = stabilise(_reads(seq, repeat=8), cal, S)
    assert [(e.us, e.them) for e in events][-4:] == [(25, 23), (0, 0), (0, 1), (2, 1)]


def test_missing_last_point_is_inferred():
    scores = [ScoreEvent(1.0, 20, 0)] + [ScoreEvent(5.0 + 10 * i, 20, i) for i in range(1, 25)] + [ScoreEvent(400.0, 0, 0)]
    _, sets = build([], scores, S)
    assert (sets[0].us, sets[0].them) == (20, 25)


def test_server_numbers_follow_the_rotation():
    # set 1: we receive first; points: them, us (side-out -> pos II serves), us, them, us (side-out -> pos III)
    winners = ["them", "us", "us", "them", "us"]
    us = them = 0
    scores = []
    for i, w in enumerate(winners):
        us, them = (us + 1, them) if w == "us" else (us, them + 1)
        scores.append(ScoreEvent(30.0 * (i + 1), us, them))
    rallies, _ = build([], scores, S, first_serve={1: False})
    from vva_analyzer.rallies import assign_servers
    n = assign_servers(rallies, {1: [21, 30, 7, 55, 8, 14]})
    assert [r.server for r in rallies] == ["them", "them", "us", "us", "them"]
    assert [r.server_number for r in rallies] == [None, None, 30, 30, None]
    assert n == 2
    # one more side-out brings position III
    scores.append(ScoreEvent(200.0, us + 1, them))
    rallies, _ = build([], scores, S, first_serve={1: False})
    assign_servers(rallies, {1: [21, 30, 7, 55, 8, 14]})
    assert rallies[-1].server == "us" and rallies[-1].server_number == 7
