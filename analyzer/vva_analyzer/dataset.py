"""Our own training material: player crops labelled with jersey numbers, and a model taught on them.

During the scan, every on-court player large enough in the picture is cut out now and then
(`Settings.crops_every_s`) into `work/crops/<video>/`. After the analysis the tracks know
their numbers (the analyst's corrections in the 2D view, rotations, OCR, looks), so each crop
gets a label: `work/dataset-team<id>/<number>/<video>_<t>_<i>.jpg`, plus `manifest.csv` with
the source of the label (`analyst` = corrected or confirmed by a person, `auto` = AI votes).

From that folder a small image classifier (YOLO classification, same ultralytics package as the
ball detector) learns OUR players: face, hair, build, shoes and the number, everything at once.
`vva-analyzer train-players` teaches it; the next analysis uses `work/players-team<id>.pt`
to vote for tracks without a number. Nothing leaves the computer and nothing costs money.
"""

from __future__ import annotations

import csv
import logging
import math
import random
import shutil
from pathlib import Path

import numpy as np

from .players import Person, Tracking, on_court

log = logging.getLogger("vva")

MIN_CROP_PX = 70  # smaller people (far side) teach nothing
CROP_H = 192  # crops are stored at most this tall (small files, enough for the model)
VOTE = 0.6
MIN_PROB = 0.6  # mean probability a track needs for its best number
PER_TRACK = 12  # crops per track that the model looks at


def save_crops(frame, people: list[Person], t: float, folder: Path, h: np.ndarray | None = None) -> int:
    """Cut out the on-court people of one frame; sets `p.crop` to the file name. Returns how many."""
    import cv2

    from .court import to_court

    height, width = frame.shape[:2]
    folder.mkdir(parents=True, exist_ok=True)
    n = 0
    for i, p in enumerate(people):
        bw, bh = p.w * width, p.h * height
        if bh < MIN_CROP_PX or bw <= 4:
            continue
        if h is not None:
            x, y = to_court(h, p.x, p.y)
            if not (math.isfinite(x) and math.isfinite(y) and on_court(x, y)):
                continue
        cx, bottom = p.x * width, p.y * height
        x0, x1 = int(max(0, cx - bw / 2)), int(min(width, cx + bw / 2))
        y0, y1 = int(max(0, bottom - bh)), int(min(height, bottom))
        img = frame[y0:y1, x0:x1]
        if img.size == 0:
            continue
        if img.shape[0] > CROP_H:
            img = cv2.resize(img, (max(1, int(img.shape[1] * CROP_H / img.shape[0])), CROP_H))
        name = f"{t:.1f}_{i}.jpg"
        if cv2.imwrite(str(folder / name), img, [cv2.IMWRITE_JPEG_QUALITY, 85]):
            p.crop = name
            n += 1
    return n


def track_crops(tracking: Tracking) -> dict[int, list[str]]:
    """Crop file names per track."""
    out: dict[int, list[str]] = {}
    for _, seen in tracking.samples:
        for s in seen:
            if s.person is not None and s.person.crop:
                out.setdefault(s.track, []).append(s.person.crop)
    return out


def export(tracking: Tracking, numbers: dict[int, int], crops: Path, out: Path, video: str, analyst: set[int],
           allowed: set[int] | None = None, ours: set[int] | None = None) -> int:
    """Copy the crops of every numbered track of ours into `out/<number>/`; returns how many were added.

    `numbers`: track -> number from the analyst, rotations and OCR only, not from the looks or
    the model itself, so the model is not taught its own guesses."""
    out.mkdir(parents=True, exist_ok=True)
    manifest = out / "manifest.csv"
    header = ["file", "number", "source", "video", "track"]
    rows: list[list[str]] = []
    if manifest.exists():
        with manifest.open(newline="", encoding="utf-8") as f:
            rows = [r for r in csv.reader(f)][1:]
    # this video again (new corrections): its old crops may carry numbers that were wrong
    for r in rows:
        if len(r) > 3 and r[3] == video:
            (out / r[0]).unlink(missing_ok=True)
    rows = [r for r in rows if len(r) > 3 and r[3] != video]
    added = 0
    for track, names in track_crops(tracking).items():
        n = numbers.get(track)
        if n is None or (ours is not None and track not in ours) or (allowed and n not in allowed):
            continue
        folder = out / str(n)
        folder.mkdir(exist_ok=True)
        for name in names:
            src = crops / name
            dst = folder / f"{video}_{name}"
            if not src.exists() or dst.exists():
                continue
            shutil.copyfile(src, dst)
            rows.append([f"{n}/{dst.name}", str(n), "analyst" if track in analyst else "auto", video, str(track)])
            added += 1
    with manifest.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)
    return added


def split(dataset: Path, target: Path, val: float = 0.15, seed: int = 1) -> dict[str, int]:
    """Lay the dataset out as ultralytics wants it: target/train/<n>/, target/val/<n>/."""
    rnd = random.Random(seed)
    counts: dict[str, int] = {}
    if target.exists():
        shutil.rmtree(target)
    for folder in sorted(p for p in dataset.iterdir() if p.is_dir() and p.name.isdigit()):
        files = sorted(folder.glob("*.jpg"))
        if len(files) < 5:
            continue  # too few to learn from
        rnd.shuffle(files)
        k = max(1, int(len(files) * val))
        for part, chunk in (("val", files[:k]), ("train", files[k:])):
            d = target / part / folder.name
            d.mkdir(parents=True, exist_ok=True)
            for src in chunk:
                shutil.copyfile(src, d / src.name)
        counts[folder.name] = len(files)
    return counts


def train(dataset: Path, model_out: Path, epochs: int = 30, device: str | None = None) -> dict[str, int]:
    """Teach the player classifier on the dataset; saves the best weights to `model_out`."""
    from ultralytics import YOLO

    work = dataset.parent / f"{dataset.name}-split"
    counts = split(dataset, work)
    if len(counts) < 2:
        raise SystemExit(f"Za mało materiału w {dataset}: potrzeba co najmniej 2 numerów po 5 wycinków.")
    model = YOLO("yolo11n-cls.pt")
    res = model.train(data=str(work), epochs=epochs, imgsz=128, device=device, project=str(dataset.parent),
                      name=f"{dataset.name}-train", exist_ok=True, verbose=False)
    best = Path(getattr(res, "save_dir", dataset.parent / f"{dataset.name}-train")) / "weights" / "best.pt"
    shutil.copyfile(best, model_out)
    return counts


class PlayerModel:
    """The trained classifier: number probabilities for crops."""

    def __init__(self, path: Path, device: str | None = None):
        from ultralytics import YOLO

        self.model = YOLO(str(path))
        self.device = device
        self.names = {int(k): int(v) for k, v in self.model.names.items() if str(v).isdigit()}

    def probs(self, images: list) -> list[dict[int, float]]:
        out = []
        for r in self.model.predict(images, imgsz=128, device=self.device, verbose=False):
            p = r.probs.data.tolist() if r.probs is not None else []
            out.append({self.names[i]: float(v) for i, v in enumerate(p) if i in self.names})
        return out


def model_votes(tracking: Tracking, crops: Path, predict, ours: set[int] | None = None,
                allowed: set[int] | None = None) -> int:
    """Vote the number the player model sees for our tracks without a number.

    `predict(list of image paths) -> list of {number: probability}`."""
    voted = 0
    for track, names in track_crops(tracking).items():
        if track in tracking.numbers or (ours is not None and track not in ours):
            continue
        step = max(1, len(names) // PER_TRACK)
        files = [str(crops / n) for n in names[::step][:PER_TRACK] if (crops / n).exists()]
        if not files:
            continue
        total: dict[int, float] = {}
        for probs in predict(files):
            for n, v in probs.items():
                if allowed is None or n in allowed:
                    total[n] = total.get(n, 0.0) + v / len(files)
        if not total:
            continue
        best = max(total, key=total.get)
        if total[best] >= MIN_PROB:
            for _ in range(2 if total[best] >= 0.8 else 1):
                tracking.vote(track, best, VOTE)
            voted += 1
    return voted
