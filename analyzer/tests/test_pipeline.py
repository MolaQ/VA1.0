from pathlib import Path

from vva_analyzer.config import Calibration, Settings
from vva_analyzer.pipeline import Job
from vva_analyzer.rallies import BallObs
from vva_analyzer.scoreboard import Reading


class FakeClient:
    def __init__(self):
        self.calls = []

    def progress(self, game_id, status, progress=None, message=None):
        self.calls.append(("progress", status, progress))

    def put_set(self, game_id, number, us, them, video_start):
        self.calls.append(("set", number, us, them))

    def post_actions(self, game_id, actions):
        self.calls.append(("actions", len(actions)))


def test_publish_sends_sets_actions_and_completion(tmp_path: Path):
    client = FakeClient()
    job = Job(Calibration(score_left=(0, 0, .1, .1), score_right=(.1, 0, .1, .1)), Settings(), tmp_path, client, 7)
    job.obs = [BallObs(10 + i / 12, 0.3 + i * 0.003, 0.4, 0.5, "near") for i in range(60)]
    job.readings = [Reading(t, 1 if t >= 20 else 0, 0) for t in range(0, 30)]

    result = job.analyse()
    job.publish(result, final=True)

    assert result["stats"]["rallies"] == 1
    assert ("set", 1, 1, 0) in client.calls
    assert ("actions", 1) in client.calls  # first serve of the set came from our (near) half
    assert client.calls[-1][:2] == ("progress", "completed")


def test_detection_cache_round_trip(tmp_path: Path):
    job = Job(Calibration(), Settings(), tmp_path)
    job.obs = [BallObs(1.0, 0.1, 0.2, 0.3, "far")]
    job.readings = [Reading(1.0, 3, None)]
    job._save(tmp_path / "c.json")
    other = Job(Calibration(), Settings(), tmp_path)
    other._load(tmp_path / "c.json")
    assert other.obs[0].half == "far" and other.readings[0].left == 3


def test_court_halves_follow_the_current_calibration(tmp_path: Path):
    cal = Calibration(court=[(0.30, 0.40), (0.25, 0.80), (0.75, 0.80), (0.70, 0.40)], court_ref="attack")
    job = Job(cal, Settings(), tmp_path)
    job.obs = [BallObs(1.0, 0.2, 0.6, 0.5, "far"), BallObs(2.0, 0.8, 0.6, 0.5, None)]
    job._rehalf()
    assert [o.half for o in job.obs] == ["near", "far"]
