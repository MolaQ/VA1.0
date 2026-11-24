"""Recognising a player by how they look, learned from the players we already know.

Teammates wear the same kit, so the shirt says nothing. What differs: shoes (colour),
the head (hair, skin, headband) and height. For a person box we keep a small signature:
a hue histogram of the shoes and of the head (weighted by saturation, so grey floor and
white socks count little) and the height in metres, estimated from the box height and the
court width at the player's depth.

Learning: every track whose number is known (jersey OCR, rotation at the serve, or the
analyst's correction in the 2D view) teaches the look of that number. Tracks without a
number then get a vote for the closest look, when it is clearly closer than the next one.
The looks are kept per team in a small file, so the next match starts already knowing them
and every correction makes it better.
"""

from __future__ import annotations

import json
import math
from pathlib import Path

import numpy as np

from .court import COURT_W, from_court
from .players import Person, Tracking

BINS = 12
VOTE = 0.5  # weaker than OCR or the rotation: looks only decide between close candidates
MAX_DIST = 0.45
MARGIN = 0.12  # the best look must beat the second best by this much
HEIGHT_WEIGHT = 0.15  # metres -> distance units


def _hue_hist(region) -> list[float]:
    import cv2

    if region is None or region.size == 0:
        return [0.0] * BINS
    hsv = cv2.cvtColor(region, cv2.COLOR_BGR2HSV)
    hue = hsv[..., 0].astype(np.float32).ravel()
    weight = (hsv[..., 1].astype(np.float32) * hsv[..., 2].astype(np.float32) / 255.0).ravel()
    hist, _ = np.histogram(hue, bins=BINS, range=(0, 180), weights=weight)
    total = float(hist.sum())
    return [round(float(v) / total, 3) for v in hist] if total > 0 else [0.0] * BINS


def signature(frame, p: Person) -> list[float] | None:
    """Shoes + head hue histograms of a person box (normalised coords), or None if too small."""
    height, width = frame.shape[:2]
    bh, bw = p.h * height, p.w * width
    if bh < 60:
        return None
    cx, bottom = p.x * width, p.y * height
    x0, x1 = int(max(0, cx - bw / 2)), int(min(width, cx + bw / 2))
    shoes = frame[int(max(0, bottom - 0.08 * bh)):int(min(height, bottom)), x0:x1]
    head = frame[int(max(0, bottom - bh)):int(max(0, bottom - 0.85 * bh)), x0:x1]
    return _hue_hist(shoes) + _hue_hist(head)


def height_m(h: np.ndarray, p: Person) -> float | None:
    """Standing height from the box: box height / court width at the feet (both in pixels of a 1x1 image)."""
    from .court import to_court

    x, y = to_court(h, p.x, p.y)
    if not (math.isfinite(x) and math.isfinite(y)):
        return None
    a, b = from_court(h, 0.0, y), from_court(h, COURT_W, y)
    width = math.hypot(a[0] - b[0], a[1] - b[1])
    return p.h * COURT_W / width if width > 1e-6 else None


def _distance(a: dict, b: dict) -> float:
    sa, sb = np.array(a["sig"]), np.array(b["sig"])
    d = float(np.abs(sa - sb).sum()) / 4.0  # two histograms, each sums to 1 -> L1 in 0..4
    if a.get("height") and b.get("height"):
        d += HEIGHT_WEIGHT * abs(a["height"] - b["height"])
    return d


def track_looks(tracking: Tracking, h: np.ndarray) -> dict[int, dict]:
    """Average signature and height of every track that was seen with a signature."""
    sums: dict[int, dict] = {}
    for _, seen in tracking.samples:
        for s in seen:
            p = s.person
            if p is None or not p.sig:
                continue
            acc = sums.setdefault(s.track, {"sig": np.zeros(len(p.sig)), "n": 0, "hs": []})
            acc["sig"] += np.array(p.sig)
            acc["n"] += 1
            if (hm := height_m(h, p)) is not None and 1.4 <= hm <= 2.3:
                acc["hs"].append(hm)
    return {
        k: {"sig": (v["sig"] / v["n"]).tolist(), "height": float(np.median(v["hs"])) if v["hs"] else None, "n": v["n"]}
        for k, v in sums.items()
    }


def learn(looks: dict[int, dict], numbers: dict[int, int], known: dict[int, dict] | None = None) -> dict[int, dict]:
    """Looks per jersey number: the tracks with a number teach it; older knowledge is blended in."""
    out = {int(n): dict(v) for n, v in (known or {}).items()}
    for track, n in numbers.items():
        look = looks.get(track)
        if look is None:
            continue
        old = out.get(n)
        if old is None:
            out[n] = {"sig": look["sig"], "height": look["height"], "n": look["n"]}
            continue
        total = old.get("n", 1) + look["n"]
        w = look["n"] / total
        out[n] = {
            "sig": [round((1 - w) * a + w * b, 4) for a, b in zip(old["sig"], look["sig"])],
            "height": look["height"] if old.get("height") is None else (
                old["height"] if look["height"] is None else round((1 - w) * old["height"] + w * look["height"], 3)),
            "n": min(total, 500),  # keep learning: old matches don't freeze the look
        }
    return out


def appearance_votes(tracking: Tracking, looks: dict[int, dict], known: dict[int, dict],
                     allowed: set[int] | None = None, ours: set[int] | None = None) -> int:
    """Vote the closest known look for our tracks without a number; returns how many got a vote.

    `ours`: track ids of our team (the looks are learned from our players only)."""
    if len(known) < 2:
        return 0
    voted = 0
    for track, look in looks.items():
        if track in tracking.numbers or (ours is not None and track not in ours):
            continue
        scored = sorted((_distance(look, k), n) for n, k in known.items() if allowed is None or n in allowed)
        if len(scored) < 2:
            continue
        (d1, n1), (d2, _) = scored[0], scored[1]
        if d1 <= MAX_DIST and d2 - d1 >= MARGIN:
            # a clear match counts as two reads, so on its own it can name the player
            for _ in range(2 if d1 <= MAX_DIST / 2 else 1):
                tracking.vote(track, n1, VOTE)
            voted += 1
    return voted


def load(path: Path) -> dict[int, dict]:
    try:
        return {int(k): v for k, v in json.loads(path.read_text(encoding="utf-8")).items()}
    except (OSError, ValueError):
        return {}


def save(path: Path, looks: dict[int, dict]) -> None:
    path.write_text(json.dumps({str(k): v for k, v in sorted(looks.items())}), encoding="utf-8")
