"""Shoulders from YOLO-pose, for the facing of the players (the triangle on the dot).

The direction of movement says nothing when a player stands still or shuffles sideways in
defence. A pose model (yolo11s-pose, the same ultralytics package, COCO keypoints) finds the
left and right shoulder of every person; players.body_facing turns that into degrees on the
court. It runs only on the frames that become position samples (twice a second).
"""

from __future__ import annotations

from .players import Person

L_SHOULDER, R_SHOULDER = 5, 6
MIN_KP_CONF = 0.5
MIN_IOU = 0.5


def _iou(a, b) -> float:
    x0, y0, x1, y1 = max(a[0], b[0]), max(a[1], b[1]), min(a[2], b[2]), min(a[3], b[3])
    inter = max(0.0, x1 - x0) * max(0.0, y1 - y0)
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def person_box(p: Person) -> tuple[float, float, float, float]:
    return p.x - p.w / 2, p.y - p.h, p.x + p.w / 2, p.y


def attach(people: list[Person], poses: list[tuple[tuple[float, float, float, float], list[float] | None]]) -> int:
    """Give each person the shoulders of the pose box overlapping it most. Returns how many got them.

    `poses`: (box x0, y0, x1, y1 normalised, shoulders [lx, ly, rx, ry] or None)."""
    pairs = sorted(((_iou(person_box(p), box), i, j) for i, p in enumerate(people)
                    for j, (box, _) in enumerate(poses)), reverse=True)
    used_p: set[int] = set()
    used_k: set[int] = set()
    n = 0
    for iou, i, j in pairs:
        if iou < MIN_IOU:
            break
        if i in used_p or j in used_k:
            continue
        used_p.add(i)
        used_k.add(j)
        sh = poses[j][1]
        if sh is not None:
            people[i].sh = [round(v, 4) for v in sh]
            n += 1
    return n


class PoseReader:
    def __init__(self, model: str = "yolo11s-pose.pt", device: str | None = None, imgsz: int = 960, conf: float = 0.35):
        from ultralytics import YOLO

        self.model = YOLO(model)
        self.device, self.imgsz, self.conf = device, imgsz, conf

    def read(self, frame, people: list[Person]) -> int:
        if not people:
            return 0
        r = self.model.predict(frame, imgsz=self.imgsz, conf=self.conf, device=self.device, verbose=False)[0]
        if r.boxes is None or r.keypoints is None or len(r.boxes) == 0:
            return 0
        boxes = r.boxes.xyxyn.tolist()
        kps = r.keypoints.xyn.tolist()
        confs = r.keypoints.conf.tolist() if r.keypoints.conf is not None else [[1.0] * 17] * len(kps)
        poses = []
        for box, kp, kc in zip(boxes, kps, confs):
            sh = None
            if kc[L_SHOULDER] >= MIN_KP_CONF and kc[R_SHOULDER] >= MIN_KP_CONF:
                sh = [*kp[L_SHOULDER], *kp[R_SHOULDER]]
            poses.append((tuple(box), sh))
        return attach(people, poses)
