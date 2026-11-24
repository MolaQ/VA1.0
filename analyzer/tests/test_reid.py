import sys
import types

import numpy as np

from vva_analyzer import reid
from vva_analyzer.court import homography
from vva_analyzer.players import Person, track_players


def _fake_boxmot(monkeypatch, ids):
    """A BoxMOT stand-in: gives the detections fixed ids and a feature per id."""

    class Tr:
        def __init__(self, tid, feat):
            self.id, self.smooth_feat = tid, feat

    class FakeBotSort:
        def __init__(self, config=None, reid=None):
            self.active_tracks = []

        def update(self, dets, frame):
            rows, self.active_tracks = [], []
            for i, d in enumerate(dets):
                tid = ids[i]
                feat = np.zeros(4, dtype=np.float32)
                feat[tid % 4] = 1.0
                self.active_tracks.append(Tr(tid, feat))
                rows.append([*d[:4], tid, d[4], 0, i])
            return np.array(rows, dtype=np.float32)

    mod = types.SimpleNamespace(BotSort=FakeBotSort, BotSortConfig=lambda **k: k, ReIDConfig=lambda **k: k)
    monkeypatch.setitem(sys.modules, "boxmot", mod)


def test_tracker_sets_ids_and_fingerprints(monkeypatch):
    _fake_boxmot(monkeypatch, [7, 9])
    rt = reid.ReidTracker()
    frame = np.zeros((100, 200, 3), dtype=np.uint8)
    people = [Person(0.3, 0.8, 0.9, 0.05, 0.2), Person(0.6, 0.8, 0.9, 0.05, 0.2)]
    rt.update(frame, people, keep=True)
    rt.update(frame, people, keep=False)
    assert [p.tid for p in people] == [7, 9]
    fp = rt.fingerprints()
    assert fp[7]["n"] == 1 and fp[7]["emb"][3] == 1.0
    assert fp[9]["emb"][1] == 1.0


def _h():
    # identity-like calibration: corners of the court in a 1x1 image
    return homography([(0.1, 0.9), (0.9, 0.9), (0.9, 0.1), (0.1, 0.1)], 1, 1, "corners")


def test_track_players_follows_botsort_ids():
    h = _h()
    # two players swap places between samples: by distance they would swap tracks, by id they don't
    a1, b1 = Person(0.4, 0.8, tid=1), Person(0.6, 0.8, tid=2)
    a2, b2 = Person(0.6, 0.8, tid=1), Person(0.4, 0.8, tid=2)
    tracking = track_players([(0.0, [a1, b1]), (0.5, [a2, b2])], h)
    first = {s.person.tid: s.track for s in tracking.samples[0][1]}
    second = {s.person.tid: s.track for s in tracking.samples[1][1]}
    assert first == second


def test_replay_starts_new_tracks_for_the_same_id():
    h = _h()
    samples = [(0.0, [Person(0.5, 0.8, tid=1)]), (5.0, [Person(0.5, 0.8, tid=1)]), (10.0, [Person(0.5, 0.8, tid=1)])]
    tracking = track_players(samples, h, replays=[(4.0, 6.0)])
    assert [seen[0].track for _, seen in tracking.samples] == [1, 2]


def _emb(i, noise=0.0):
    v = np.zeros(8)
    v[i] = 1.0
    v[(i + 1) % 8] = noise
    return (v / np.linalg.norm(v)).tolist()


def test_learn_and_vote_by_fingerprint():
    h = _h()
    samples = [(t, [Person(0.3, 0.8, tid=1), Person(0.5, 0.8, tid=2), Person(0.7, 0.8, tid=3)]) for t in (0.0, 0.5)]
    tracking = track_players(samples, h)
    by_tid = {1: {"emb": _emb(0), "n": 10}, 2: {"emb": _emb(2), "n": 10}, 3: {"emb": _emb(0, 0.2), "n": 10}}
    prints = reid.track_fingerprints(tracking, by_tid)
    tracks = {s.person.tid: s.track for s in tracking.samples[0][1]}
    numbers = {tracks[1]: 7, tracks[2]: 12}
    known = reid.learn(prints, numbers)
    assert set(known) == {7, 12}
    voted = reid.reid_votes(tracking, prints, known)
    # track of tid 3 looks like #7; tracks 1 and 2 have no number in tracking.numbers yet, they vote too
    assert voted == 3
    assert tracking.tracks[tracks[3]].votes.get(7, 0) > 0
    assert 12 not in tracking.tracks[tracks[3]].votes


def test_vote_needs_a_clear_margin():
    h = _h()
    tracking = track_players([(0.0, [Person(0.5, 0.8, tid=1)])], h)
    mid = (np.array(_emb(0)) + np.array(_emb(2))) / np.sqrt(2)
    prints = {1: {"emb": mid.tolist(), "n": 5}}
    known = {7: {"emb": _emb(0), "n": 5}, 12: {"emb": _emb(2), "n": 5}}
    assert reid.reid_votes(tracking, prints, known) == 0


def test_save_load_roundtrip(tmp_path):
    path = tmp_path / "reid.json"
    reid.save(path, {7: {"emb": [1.0, 0.0], "n": 3}})
    assert reid.load(path) == {7: {"emb": [1.0, 0.0], "n": 3}}
    assert reid.load(tmp_path / "missing.json") == {}
