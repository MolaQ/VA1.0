from vva_analyzer.config import Calibration
from vva_analyzer.court import from_court, homography
from vva_analyzer.jersey import best_number, torso_box
from vva_analyzer.players import Person, track_players
from vva_analyzer.rallies import BallObs, Rally
from vva_analyzer.touches import ball_actions, find_touches, reception_grade

CAL = Calibration(court=[(0.20, 0.85), (0.80, 0.85), (0.70, 0.35), (0.30, 0.35)], court_ref="corners")
H = homography(CAL.court, 1, 1, CAL.court_ref)


def person(x, y, num=None, conf=0.0):
    ix, iy = from_court(H, x, y)
    return Person(ix, iy, 0.9, 0.04, 0.15, num, conf)


def bounce(t, x, y, half):
    """Ball falling to (x, y) at time t and going up again."""
    return [BallObs(t - 0.2, x, y - 0.1, 0.9, half), BallObs(t, x, y, 0.9, half), BallObs(t + 0.2, x, y - 0.1, 0.9, half)]


def test_reception_grades_from_the_pass_point():
    assert reception_grade(6.0, 8.2) == "#"
    assert reception_grade(4.0, 6.5) == "+"
    assert reception_grade(4.0, 3.0) == "!"
    assert reception_grade(-1.0, 3.0) == "-"
    assert reception_grade(5.0, 9.5) == "/"


def test_touch_is_where_the_ball_stops_falling():
    obs = bounce(5.0, 0.5, 0.6, "far") + [BallObs(4.0, 0.5, 0.2, 0.9, "near")]
    touches = find_touches(obs)
    assert [round(t.t, 1) for t in touches] == [5.0]


def test_our_reception_gets_player_points_and_grade_and_their_serve_does_not_count():
    # we defend the far half; the opponent serves from the near half
    receiver = (7.5, 16.5)  # our frame (1.5, 1.5): deep zone 5
    setter = (3.0, 10.0)  # our frame (6, 8): the pass target
    samples = [(10 + i / 2, [person(*receiver, 12, 0.9), person(*setter)]) for i in range(10)]
    tracking = track_players(samples, H)

    rx, ry = from_court(H, *receiver)
    sx, sy = from_court(H, *setter)
    obs = [BallObs(10.0, 0.5, 0.8, 0.9, "near"), BallObs(10.2, 0.5, 0.7, 0.9, "near")]
    obs += bounce(11.5, rx, ry - 0.05, "far") + bounce(12.8, sx, sy - 0.17, "far")

    rally = Rally(set=1, number=3, start=10.0, end=15.0, server="them", serve_side="near")
    receptions, serve_extra = ball_actions([rally], obs, tracking, our_side_set1="far")

    assert serve_extra == {}
    (rec,) = receptions
    assert rec["skill"] == "reception" and rec["grade"] == "#" and rec["player_number"] == 12
    assert (rec["from_x"], rec["from_y"]) == (1.5, 1.5)
    assert (round(rec["to_x"], 1), round(rec["to_y"], 1)) == (6.0, 8.0)


def test_our_serve_gets_the_landing_point_and_the_paired_grade():
    receiver = (2.0, 16.0)  # opponent, far half
    pass_to = (8.0, 12.0)  # their frame (1, 6): poor-ish, behind... in front of the 3 m line far from target
    samples = [(10 + i / 2, [person(*receiver), person(*pass_to)]) for i in range(10)]
    tracking = track_players(samples, H)
    rx, ry = from_court(H, *receiver)
    px, py = from_court(H, *pass_to)
    obs = [BallObs(10.0, 0.5, 0.8, 0.9, "near")] + bounce(11.5, rx, ry - 0.05, "far") + bounce(12.6, px, py - 0.17, "far")
    rally = Rally(set=1, number=1, start=10.0, end=15.0, server="us", serve_side="near", serve_grade="!")

    receptions, serve_extra = ball_actions([rally], obs, tracking, our_side_set1="near")
    assert receptions == []
    extra = serve_extra[(1, 1)]
    assert (round(extra["to_x"], 1), round(extra["to_y"], 1)) == (2.0, 16.0)
    assert extra["opponent_reception"] == "!" and extra["grade"] == "!"


def test_jersey_helpers():
    assert best_number([(None, "7", 0.4), (None, "17", 0.8), (None, "123", 0.99)]) == (17, 0.8)
    assert best_number([(None, "0", 0.9)]) == (None, 0.0)
    assert torso_box(Person(0.5, 0.9, 1, 0.05, 0.02), 1920, 1080) is None  # too small to read
    assert torso_box(Person(0.5, 0.9, 1, 0.05, 0.2), 1920, 1080) == (926, 788, 993, 864)


def test_toucher_uses_the_sample_after_the_touch_too():
    from vva_analyzer.players import Person, Seen, Tracking
    from vva_analyzer.touches import Touch, toucher

    far = Person(0.2, 0.8, w=0.04, h=0.2)
    near = Person(0.5, 0.8, w=0.04, h=0.2)
    tr = Tracking([(10.0, [Seen(1, 1, 1, None, far)]), (10.5, [Seen(2, 4, 3, None, near)])], {})
    # the touch is closer in time to the first sample, but only the player of the second holds the ball
    assert toucher(Touch(10.2, 0.51, 0.7, "near"), tr).track == 2
    assert toucher(Touch(10.2, 0.9, 0.7, "near"), tr) is None
