import numpy as np

from vva_analyzer.appearance import appearance_votes, learn, signature, track_looks
from vva_analyzer.config import Calibration
from vva_analyzer.court import homography
from vva_analyzer.players import Person, Seen, Track, Tracking

H = homography(Calibration(court=[(0.20, 0.85), (0.80, 0.85), (0.70, 0.35), (0.30, 0.35)]).court, 1, 1, "corners")


def look(red: float, blue: float) -> list[float]:
    shoes = [0.0] * 12
    shoes[0], shoes[8] = red, blue
    head = [0.0] * 12
    head[1] = 1.0
    return shoes + head


def seen(track: int, sig: list[float]) -> Seen:
    return Seen(track, 4.0, 3.0, 0.0, Person(0.5, 0.8, 0.9, 0.04, 0.15, sig=sig))


def test_unnamed_teammate_is_named_by_his_shoes():
    samples = [(float(t), [seen(1, look(1, 0)), seen(2, look(0, 1)), seen(3, look(0.95, 0.05))]) for t in range(5)]
    tracking = Tracking(samples, {1: 7, 2: 13}, {k: Track(k, 0, 0, 0) for k in (1, 2, 3)})
    looks = track_looks(tracking, H)
    known = learn(looks, {1: 7, 2: 13})
    assert appearance_votes(tracking, looks, known, ours={1, 2, 3}) == 1
    assert tracking.numbers[3] == 7  # red shoes like #7


def test_opponents_and_unclear_looks_get_no_number():
    samples = [(0.0, [seen(1, look(1, 0)), seen(2, look(0, 1)), seen(3, look(0.5, 0.5)), seen(4, look(1, 0))])]
    tracking = Tracking(samples, {1: 7, 2: 13}, {k: Track(k, 0, 0, 0) for k in (1, 2, 3, 4)})
    looks = track_looks(tracking, H)
    known = learn(looks, {1: 7, 2: 13})
    assert appearance_votes(tracking, looks, known, ours={1, 2, 3}) == 0  # 3: halfway; 4: opponent
    assert 3 not in tracking.numbers and 4 not in tracking.numbers


def test_learning_blends_old_and_new_looks():
    old = {7: {"sig": look(1, 0), "height": 1.9, "n": 10}}
    new = learn({5: {"sig": look(0, 1), "height": 1.8, "n": 10}}, {5: 7}, old)
    assert new[7]["sig"][0] == 0.5 and new[7]["height"] == 1.85 and new[7]["n"] == 20


def test_signature_of_a_frame():
    frame = np.zeros((1080, 1920, 3), np.uint8)
    frame[900:1000, 900:1000] = (0, 0, 255)  # red (BGR) at the feet
    p = Person(950 / 1920, 1000 / 1080, 0.9, 80 / 1920, 300 / 1080)
    sig = signature(frame, p)
    assert sig is not None and len(sig) == 24 and sig[0] == 1.0
    assert signature(frame, Person(0.5, 0.5, 0.9, 0.01, 0.02)) is None
