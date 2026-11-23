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
    assert (sets[0].us, sets[0].them) == (3, 1)


def test_stabilise_needs_consistent_reads():
    cal = Calibration(our_score="right")
    reads = [Reading(t, l, r) for t, l, r in [
        (0, 0, 0), (1, 0, 1), (2, 0, 1), (3, 0, 7), (4, 0, 1),  # a single misread 7 is ignored
        (5, 1, 1), (6, 1, 1), (7, None, None), (8, 1, 2), (9, 1, 2),
    ]]
    events = stabilise(reads, cal, S)
    assert [(e.t, e.us, e.them) for e in events] == [(1, 1, 0), (5, 1, 1), (8, 2, 1)]
