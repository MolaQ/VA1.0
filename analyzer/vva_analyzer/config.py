"""Camera calibration for a fixed camera (one per hall / camera position).

Image points are in pixels of the analysed video (any resolution: they are stored
normalised 0..1, so a 1080p calibration also works for a 720p download).
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from pathlib import Path

Point = tuple[float, float]
Box = tuple[float, float, float, float]  # x, y, w, h (normalised)


@dataclass
class Calibration:
    # Four reference points on the floor, order: A1, A2 on the "near" half (where WE play in
    # set 1), then B2, B1 on the other half (B2 on the same sideline as A2).
    # court_ref = "attack": the ends of the two 3 m attack lines (always in frame from a side stand);
    # court_ref = "corners": the four court corners.
    court: list[Point] = field(default_factory=list)
    court_ref: str = "attack"
    # Scoreboard overlay: where the points of each side are shown.
    score_left: Box | None = None
    score_right: Box | None = None
    # Which overlay side shows OUR points: "left" or "right".
    our_score: str = "left"
    # Which court half our team defends in set 1, seen from the camera: "near" or "far".
    our_side_set1: str = "near"

    def save(self, path: str | Path) -> None:
        Path(path).write_text(json.dumps(asdict(self), indent=2), encoding="utf-8")

    @classmethod
    def load(cls, path: str | Path) -> "Calibration":
        data = json.loads(Path(path).read_text(encoding="utf-8"))
        cal = cls()
        cal.court = [tuple(p) for p in data.get("court", [])]  # type: ignore[misc]
        cal.court_ref = data.get("court_ref", "corners")
        cal.score_left = tuple(data["score_left"]) if data.get("score_left") else None  # type: ignore[assignment]
        cal.score_right = tuple(data["score_right"]) if data.get("score_right") else None  # type: ignore[assignment]
        cal.our_score = data.get("our_score", "left")
        cal.our_side_set1 = data.get("our_side_set1", "near")
        return cal

    @property
    def has_court(self) -> bool:
        return len(self.court) == 4

    @property
    def has_scoreboard(self) -> bool:
        return self.score_left is not None and self.score_right is not None


@dataclass
class Settings:
    """Tunable thresholds; defaults are a starting point for a fixed camera from the stands."""

    sample_fps: float = 12.0  # frames per second fed to the ball detector
    detect_size: int = 960  # YOLO input size (the ball is small: keep it large)
    ball_conf: float = 0.15
    model: str = "yolo11s.pt"
    # Players for the 2D court view (same YOLO pass, COCO class "person")
    players: bool = True
    person_conf: float = 0.35
    positions_every_s: float = 0.5  # one frame of player positions every this many seconds
    jerseys: bool = True  # read jersey numbers (OCR on the torso)
    jersey_every_s: float = 2.0  # how often each visible player's number is tried
    # "botsort": BoT-SORT + OSNet re-ID on every frame (BoxMOT, pip install .[tracking]);
    # "simple": join the dots by distance. Without BoxMOT installed "simple" is used anyway.
    tracker: str = "botsort"
    reid_model: str = "osnet-x0-25-msmt17"
    # Facing from the shoulders (YOLO-pose) instead of the direction of movement
    pose: bool = True
    pose_model: str = "yolo11s-pose.pt"
    pose_every_s: float = 1.0
    # Player crops for our own training set (dataset.py, `train-players`)
    crops: bool = True
    crops_every_s: float = 6.0
    # Ball activity -> rallies
    gap_merge_s: float = 1.2  # detections closer than this belong to one activity segment
    min_segment_s: float = 1.0
    rally_gap_s: float = 3.0  # activity segments closer than this belong to one rally
    max_rally_s: float = 90.0
    score_lag_s: float = 25.0  # the overlay is updated at most this long after the rally ends
    static_ball_s: float = 3.0  # a "ball" that doesn't move for this long is a spare ball on the floor
    # Serve outcome heuristics (stage 1, no player tracking yet)
    ace_max_s: float = 4.0
    serve_error_max_s: float = 2.5
    # Scoreboard OCR
    ocr_every_s: float = 1.0
    ocr_stable_reads: int = 2
    ocr_revert_s: float = 40.0  # a score undone within this time was a misread or a correction
    ocr_max_jump: int = 6  # missed reads: a side may jump forward at most this many points
    set_reset_min: int = 14  # 0:0 is a new set only after one side had at least this many points
    set_points: int = 25
    tiebreak_points: int = 15
