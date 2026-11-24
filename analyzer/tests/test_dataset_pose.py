import csv

import numpy as np
import pytest

from vva_analyzer import dataset, pose
from vva_analyzer.court import homography
from vva_analyzer.players import Person, body_facing, track_players


def _h():
    # a 1x1 image showing the court from straight above: image x -> court x, image y down -> court y down
    return homography([(0.1, 0.9), (0.9, 0.9), (0.9, 0.1), (0.1, 0.1)], 1, 1, "corners")


def test_body_facing_from_shoulders():
    h = _h()
    # right shoulder at larger court x: facing +y (towards the far end line) = 0 degrees
    p = Person(0.5, 0.8, w=0.05, h=0.2, sh=[0.48, 0.65, 0.52, 0.65])
    assert body_facing(h, p) == 0.0
    # mirrored: facing the near end line
    p.sh = [0.52, 0.65, 0.48, 0.65]
    assert body_facing(h, p) == 180.0
    # shoulders on top of each other: cannot tell
    p.sh = [0.5, 0.65, 0.5, 0.65]
    assert body_facing(h, p) is None


def test_track_uses_shoulders_when_standing():
    h = _h()
    s = [(t, [Person(0.5, 0.8, w=0.05, h=0.2, sh=[0.48, 0.65, 0.52, 0.65])]) for t in (0.0, 0.5)]
    tracking = track_players(s, h)
    assert tracking.samples[-1][1][0].f == 0.0
    s = [(t, [Person(0.5, 0.8, w=0.05, h=0.2, sh=[0.52, 0.65, 0.48, 0.65])]) for t in (0.0, 0.5)]
    assert track_players(s, h).samples[-1][1][0].f == 180.0


def test_attach_pose_by_overlap():
    people = [Person(0.3, 0.8, w=0.1, h=0.3), Person(0.7, 0.8, w=0.1, h=0.3)]
    poses = [((0.65, 0.5, 0.75, 0.8), [0.68, 0.55, 0.72, 0.55]), ((0.9, 0.1, 0.95, 0.2), [0.9, 0.1, 0.95, 0.1])]
    assert pose.attach(people, poses) == 1
    assert people[0].sh is None and people[1].sh == [0.68, 0.55, 0.72, 0.55]


def _tracking_with_crops(tmp_path):
    cv2 = pytest.importorskip("cv2")
    h = _h()
    frame = np.full((400, 400, 3), 120, dtype=np.uint8)
    samples = []
    for t in (0.0, 0.5, 1.0):
        people = [Person(0.3, 0.8, 0.9, 0.05, 0.3), Person(0.7, 0.8, 0.9, 0.05, 0.3)]
        assert dataset.save_crops(frame, people, t, tmp_path / "crops", h) == 2
        samples.append((t, people))
    assert cv2.imread(str(tmp_path / "crops" / "0.0_0.jpg")) is not None
    return track_players(samples, h)


def test_export_labels_crops_and_replaces_a_video(tmp_path):
    tracking = _tracking_with_crops(tmp_path)
    a, b = (s.track for s in tracking.samples[0][1])
    out = tmp_path / "dataset"
    assert dataset.export(tracking, {a: 7}, tmp_path / "crops", out, "m1", analyst={a}) == 3
    assert len(list((out / "7").glob("*.jpg"))) == 3
    # the analyst corrects the player: the same video is exported again under the new number
    assert dataset.export(tracking, {a: 8, b: 12}, tmp_path / "crops", out, "m1", analyst=set()) == 6
    assert not list((out / "7").glob("*.jpg"))
    rows = list(csv.reader((out / "manifest.csv").open(encoding="utf-8")))
    assert rows[0] == ["file", "number", "source", "video", "track"]
    assert sorted({r[1] for r in rows[1:]}) == ["12", "8"] and len(rows) == 7


def test_model_votes_for_unnamed_tracks(tmp_path):
    tracking = _tracking_with_crops(tmp_path)
    by_crop = {s.person.crop: s.track for s in tracking.samples[0][1]}
    a, b = by_crop["0.0_0.jpg"], by_crop["0.0_1.jpg"]

    def predict(files):
        return [{7: 0.9, 12: 0.1} if f"_0.jpg" in f else {7: 0.3, 12: 0.4} for f in files]

    assert dataset.model_votes(tracking, tmp_path / "crops", predict) == 1
    assert tracking.tracks[a].votes.get(7, 0) > 0
    assert not tracking.tracks[b].votes  # 0.4 is not sure enough


def test_split_needs_five_crops(tmp_path):
    data = tmp_path / "d"
    for n, k in ((7, 10), (12, 3)):
        (data / str(n)).mkdir(parents=True)
        for i in range(k):
            (data / str(n) / f"{i}.jpg").write_bytes(b"x")
    counts = dataset.split(data, tmp_path / "s")
    assert counts == {"7": 10}
    assert len(list((tmp_path / "s" / "val" / "7").iterdir())) == 1
