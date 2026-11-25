from pathlib import Path

from vva_analyzer.config import Calibration, Settings
from vva_analyzer.court import from_court, homography
from vva_analyzer.pipeline import Job
from vva_analyzer.players import Person, Tracker, build_frames, heading, team_of, to_court_points, track_players

CAL = Calibration(court=[(0.20, 0.85), (0.80, 0.85), (0.70, 0.35), (0.30, 0.35)], court_ref="corners")


def _h():
    return homography(CAL.court, 1, 1, CAL.court_ref)


def _person(h, x, y):
    ix, iy = from_court(h, x, y)
    return Person(ix, iy, 0.9)


def test_heading_degrees():
    assert heading(0, 1) == 0  # towards the far end line
    assert heading(1, 0) == 90
    assert heading(0, -1) == 180
    assert heading(-1, 0) == 270


def test_feet_points_map_to_metres_and_drop_people_off_court():
    h = _h()
    people = [_person(h, 4.5, 3.0), _person(h, 2.0, 14.0), _person(h, -5.0, 9.0)]  # last: referee stand
    pts = to_court_points(h, people)
    assert len(pts) == 2
    assert any(abs(x - 4.5) < 0.01 and abs(y - 3.0) < 0.01 for x, y, _ in pts)


def test_tracker_keeps_ids_and_faces_the_movement():
    tr = Tracker()
    a = tr.update(0.0, [(2.0, 3.0), (6.0, 14.0)])
    ids = {round(t.y): t.id for t in a}
    b = tr.update(0.5, [(2.0, 4.0), (6.0, 13.6)])
    assert {round(t.y): t.id for t in b} == {4: ids[3], 14: ids[14]}
    near = next(t for t in b if t.id == ids[3])
    assert near.facing == 0  # running towards the net (+y)
    far = next(t for t in b if t.id == ids[14])
    assert far.facing == 180  # standing-ish far player keeps facing the net


def test_team_follows_set_sides():
    starts = [(1, 0.0), (2, 1000.0)]
    assert team_of(3.0, 10.0, starts, "near") == "us"
    assert team_of(3.0, 1200.0, starts, "near") == "them"  # sides swapped in set 2
    assert team_of(15.0, 1200.0, starts, "near") == "us"


def test_build_frames_skips_replays():
    h = _h()
    samples = [(t / 2, [_person(h, 3.0, 4.0), _person(h, 5.0, 12.0)]) for t in range(10)]
    frames = build_frames(samples, h, [(1, 0.0)], "near", replays=[(2.0, 3.0)])
    assert [f["t"] for f in frames] == [0.0, 0.5, 1.0, 1.5, 3.5, 4.0, 4.5]
    teams = sorted(p["team"] for p in frames[0]["players"])
    assert teams == ["them", "us"]
    assert all(p["k"] for p in frames[0]["players"])


def test_people_go_into_the_cache_and_the_report(tmp_path: Path):
    h = _h()
    job = Job(CAL, Settings(), tmp_path)
    job.people = [(1.0, [_person(h, 3.0, 4.0)])]
    job._save(tmp_path / "c.json")
    other = Job(CAL, Settings(), tmp_path)
    other._load(tmp_path / "c.json")
    assert len(other.people) == 1
    frames = other.analyse()["frames"]
    assert len(frames) == 1 and abs(frames[0]["players"][0]["y"] - 4.0) < 0.05


def test_referee_at_the_post_is_not_a_player():
    h = _h()
    assert to_court_points(h, [_person(h, -0.8, 9.2)]) == []


def test_jersey_numbers_are_agreed_per_track():
    h = _h()

    def person(x, y, num=None, conf=0.0):
        p = _person(h, x, y)
        p.num, p.num_conf = num, conf
        return p

    samples = [
        (0.0, [person(3.0, 4.0, 7, 0.9), person(5.0, 12.0, 11, 0.4)]),
        (0.5, [person(3.1, 4.1, 1, 0.6), person(5.0, 12.1)]),
        (1.0, [person(3.2, 4.2, 7, 0.8), person(5.1, 12.1, 11, 0.45)]),
    ]
    tracking = track_players(samples, h)
    frames = build_frames(samples, h, [(1, 0.0)], tracking=tracking)
    numbers = {round(p["y"]): p["n"] for p in frames[-1]["players"]}
    assert numbers == {4: 7, 12: None}  # #11 was only read with low confidence


def _tracking(pieces):
    """pieces: track id -> list of (t, x, y)."""
    from vva_analyzer.players import Seen, Track, Tracking

    by_t: dict = {}
    for k, pts in pieces.items():
        for t, x, y in pts:
            by_t.setdefault(t, []).append(Seen(k, x, y, None, None))
    tracks = {k: Track(k, pts[-1][1], pts[-1][2], pts[-1][0]) for k, pts in pieces.items()}
    return Tracking(sorted(by_t.items()), {}, tracks)


def test_stitch_joins_a_piece_after_a_short_gap():
    from vva_analyzer.players import stitch

    tr = _tracking({1: [(0.0, 2, 3), (0.5, 2.2, 3)], 2: [(2.0, 2.5, 3.2), (2.5, 2.6, 3.2)],
                    3: [(0.0, 6, 3), (2.5, 6, 3)]})
    tr.tracks[1].votes, tr.tracks[1].reads = {7: 0.6}, {7: 1}
    tr.tracks[2].votes, tr.tracks[2].reads = {7: 0.6}, {7: 1}
    assert stitch(tr) == 1
    assert [s.track for _, seen in tr.samples for s in seen if s.x < 4] == [1, 1, 1, 1]
    assert 2 not in tr.tracks and tr.numbers == {1: 7}  # two reads together name the player


def test_stitch_keeps_apart_far_pieces_other_half_and_other_looks():
    from vva_analyzer.players import stitch

    # too far for the gap
    assert stitch(_tracking({1: [(0.0, 1, 3)], 2: [(1.0, 8, 3)]})) == 0
    # other half of the court
    assert stitch(_tracking({1: [(0.0, 4, 8.5)], 2: [(1.0, 4, 9.5)]})) == 0
    # close, but the fingerprints differ
    assert stitch(_tracking({1: [(0.0, 4, 3)], 2: [(1.0, 4, 3)]}), {1: [1.0, 0.0], 2: [0.0, 1.0]}) == 0
    assert stitch(_tracking({1: [(0.0, 4, 3)], 2: [(1.0, 4, 3)]}), {1: [1.0, 0.0], 2: [0.9, 0.436]}) == 1


def test_stitch_chains_and_picks_the_closest():
    from vva_analyzer.players import stitch

    tr = _tracking({1: [(0.0, 4, 3)], 2: [(1.0, 4.2, 3)], 3: [(2.0, 4.3, 3)], 4: [(1.0, 5.5, 3)]})
    assert stitch(tr) == 2  # 1 -> 2 -> 3; 4 started next to 2 but 2 is closer to 1
    assert {s.track for _, seen in tr.samples for s in seen} == {1, 4}


def test_smooth_removes_a_jump_of_one_sample():
    from vva_analyzer.players import smooth

    tr = _tracking({1: [(0.0, 2, 3), (0.5, 2, 3), (1.0, 5, 6), (1.5, 2, 3), (2.0, 2, 3)]})
    smooth(tr)
    assert [(s.x, s.y) for _, seen in tr.samples for s in seen] == [(2, 3)] * 5
