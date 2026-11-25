"""Players followed on every frame (BoT-SORT) and recognised by a re-ID fingerprint (OSNet).

The simple tracker in players.py joins dots twice a second by distance alone, so two players
crossing at the net often swap. BoT-SORT (from the open-source BoxMOT package) runs on every
analysed frame, predicts motion with a Kalman filter, compensates for camera movement and
compares how the boxes look with a small re-ID network (OSNet). Each box then carries a
stable track id (`Person.tid`) and players.track_players follows those ids.

The same network gives a fingerprint of a player: a 512-number vector of how they look.
Teammates wear the same kit, but OSNet still sees build, hair, shoes and the way the shirt
sits. Fingerprints are averaged per track; tracks whose number is known (OCR, rotation, the
analyst's correction) teach the fingerprint of that number, which is kept per team between
matches like the colour looks in appearance.py. Tracks without a number get a vote for the
closest fingerprint when it is clearly closer than the next one.

BoxMOT is optional (`pip install .[tracking]`): without it the simple tracker is used.
"""

from __future__ import annotations

import json
import logging
import math
from pathlib import Path

import numpy as np

from .players import Person, Tracking, on_court

log = logging.getLogger("vva")

VOTE = 0.6  # a bit more than the colour looks: the network sees more than two histograms
MIN_SIM = 0.70  # cosine similarity a fingerprint needs to count at all
MARGIN = 0.06  # the best number must beat the second best by this much
CLEAR_SIM = 0.82  # this close counts as two reads: on its own it can name the player


class ReidTracker:
    """BoT-SORT with OSNet re-ID over the frames of one video. Sets `tid` on the people it follows
    and keeps the mean fingerprint of every track id."""

    def __init__(self, device: str | None = None, model: str = "osnet-x0-25-msmt17", fps: float = 12.0):
        from boxmot import BotSort, BotSortConfig, ReIDConfig

        gpu = device not in (None, "cpu")
        self.tracker = BotSort(
            config=BotSortConfig(
                max_lost_frames=int(5 * fps),  # a player hidden for up to ~5 s keeps the id
                # the defaults (~0.62) never start a track for a small, far-side player (YOLO conf 0.35-0.6)
                track_high_thresh=0.45, new_track_thresh=0.45, track_low_thresh=0.1,
            ),
            reid=ReIDConfig(model=model, device=device or "cpu", precision="fp16" if gpu else "fp32"),
        )
        self.sums: dict[int, np.ndarray] = {}
        self.counts: dict[int, int] = {}

    def update(self, frame, people: list[Person], h: np.ndarray | None = None, keep: bool = False) -> None:
        """Follow the people of one frame. `h`: court homography for a 1x1 image, people off the
        court (bench, referees, crowd) are left out. `keep`: also add their fingerprints."""
        height, width = frame.shape[:2]
        chosen: list[Person] = []
        rows = []
        for p in people:
            if h is not None:
                from .court import to_court

                x, y = to_court(h, p.x, p.y)
                if not (math.isfinite(x) and math.isfinite(y) and on_court(x, y)):
                    continue
            chosen.append(p)
            cx, bottom, bw, bh = p.x * width, p.y * height, p.w * width, p.h * height
            rows.append([cx - bw / 2, bottom - bh, cx + bw / 2, bottom, p.conf, 0])
        dets = np.array(rows, dtype=np.float32) if rows else np.empty((0, 6), dtype=np.float32)
        out = self.tracker.update(dets, frame)
        if out is None or len(out) == 0:
            return
        feats = {}
        if keep:
            for tr in getattr(self.tracker, "active_tracks", []):
                feat = getattr(tr, "smooth_feat", None)
                if feat is None:
                    feat = getattr(tr, "curr_feat", None)
                if feat is not None:
                    feats[int(getattr(tr, "id", getattr(tr, "track_id", -1)))] = feat
        for row in out:
            tid, det = int(row[4]), int(row[7]) if len(row) > 7 else -1
            if not 0 <= det < len(chosen):
                continue
            chosen[det].tid = tid
            if tid in feats:
                self.add(tid, feats[tid])

    def add(self, tid: int, feat) -> None:
        v = _unit(np.asarray(feat, dtype=np.float32))
        if v is None:
            return
        self.sums[tid] = self.sums.get(tid, np.zeros_like(v)) + v
        self.counts[tid] = self.counts.get(tid, 0) + 1

    def fingerprints(self) -> dict[int, dict]:
        """Mean fingerprint per track id, rounded for the cache file."""
        return {tid: {"emb": [round(float(x), 3) for x in _unit(s)], "n": self.counts[tid]}
                for tid, s in self.sums.items() if _unit(s) is not None}


def _unit(v: np.ndarray) -> np.ndarray | None:
    n = float(np.linalg.norm(v))
    return v / n if n > 1e-9 else None


def track_fingerprints(tracking: Tracking, by_tid: dict[int, dict]) -> dict[int, dict]:
    """Fingerprint of every track of `tracking`, from the BoT-SORT ids its people carried."""
    seen: dict[int, dict[int, int]] = {}
    for _, players in tracking.samples:
        for s in players:
            tid = s.person.tid if s.person is not None else None
            if tid is not None and tid in by_tid:
                c = seen.setdefault(s.track, {})
                c[tid] = c.get(tid, 0) + 1
    out = {}
    for track, tids in seen.items():
        acc, n = None, 0
        for tid in tids:
            fp = by_tid[tid]
            v = np.array(fp["emb"], dtype=np.float32) * fp.get("n", 1)
            acc = v if acc is None else acc + v
            n += fp.get("n", 1)
        if acc is not None and (u := _unit(acc)) is not None:
            out[track] = {"emb": u.tolist(), "n": n}
    return out


def learn(prints: dict[int, dict], numbers: dict[int, int], known: dict[int, dict] | None = None) -> dict[int, dict]:
    """Fingerprint per jersey number: the tracks with a number teach it, older knowledge is blended in."""
    out = {int(n): dict(v) for n, v in (known or {}).items()}
    for track, n in numbers.items():
        fp = prints.get(track)
        if fp is None:
            continue
        old = out.get(n)
        if old is None:
            out[n] = {"emb": [round(x, 4) for x in fp["emb"]], "n": fp["n"]}
            continue
        total = old.get("n", 1) + fp["n"]
        w = fp["n"] / total
        mixed = _unit((1 - w) * np.array(old["emb"]) + w * np.array(fp["emb"]))
        if mixed is not None:
            out[n] = {"emb": [round(float(x), 4) for x in mixed], "n": min(total, 2000)}
    return out


def reid_votes(tracking: Tracking, prints: dict[int, dict], known: dict[int, dict],
               allowed: set[int] | None = None, ours: set[int] | None = None) -> int:
    """Vote the closest known fingerprint for our tracks without a number; returns how many got one."""
    if len(known) < 2:
        return 0
    gallery = [(n, np.array(k["emb"], dtype=np.float32)) for n, k in known.items() if allowed is None or n in allowed]
    voted = 0
    for track, fp in prints.items():
        if track in tracking.numbers or (ours is not None and track not in ours):
            continue
        v = np.array(fp["emb"], dtype=np.float32)
        scored = sorted(((float(v @ g), n) for n, g in gallery), reverse=True)
        if len(scored) < 2:
            continue
        (s1, n1), (s2, _) = scored[0], scored[1]
        if s1 >= MIN_SIM and s1 - s2 >= MARGIN:
            for _ in range(2 if s1 >= CLEAR_SIM else 1):
                tracking.vote(track, n1, VOTE)
            voted += 1
    return voted


def available() -> bool:
    try:
        import boxmot  # noqa: F401
    except Exception:
        return False
    return True


def load(path: Path) -> dict[int, dict]:
    try:
        return {int(k): v for k, v in json.loads(path.read_text(encoding="utf-8")).items()}
    except (OSError, ValueError):
        return {}


def save(path: Path, prints: dict[int, dict]) -> None:
    path.write_text(json.dumps({str(k): v for k, v in sorted(prints.items())}), encoding="utf-8")
