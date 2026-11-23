"""Referee whistle detection in the audio track: a loud, narrow tone around 2.5 to 4.5 kHz.

Used to confirm rally ends (the first whistle after play) when there is no scoreboard.
"""

from __future__ import annotations

import numpy as np


def detect(samples: np.ndarray, rate: int, band: tuple[float, float] = (2500.0, 4500.0),
           min_ratio: float = 6.0, min_duration: float = 0.15) -> list[float]:
    """Start times (s) of whistle blasts in a mono float signal."""
    win = int(rate * 0.05)
    hop = win // 2
    if len(samples) < win:
        return []

    freqs = np.fft.rfftfreq(win, 1 / rate)
    in_band = (freqs >= band[0]) & (freqs <= band[1])
    window = np.hanning(win)

    hits = []
    for i in range(0, len(samples) - win, hop):
        spec = np.abs(np.fft.rfft(samples[i:i + win] * window))
        band_spec = spec[in_band]
        total = spec.sum() + 1e-9
        # tonal: one strong peak in the band that carries much of the frame's energy
        peak = band_spec.max() if band_spec.size else 0.0
        ratio = peak / (np.median(spec) + 1e-9)
        hits.append(ratio >= min_ratio * 10 and band_spec.sum() / total > 0.35)

    times = []
    run_start = None
    frame_s = hop / rate
    for idx, hit in enumerate(hits + [False]):
        if hit and run_start is None:
            run_start = idx
        elif not hit and run_start is not None:
            if (idx - run_start) * frame_s >= min_duration:
                times.append(run_start * frame_s)
            run_start = None
    return times


def load_audio(path: str, rate: int = 16000) -> np.ndarray:
    """Mono float32 audio via ffmpeg (must be on PATH)."""
    import subprocess

    raw = subprocess.run(
        ["ffmpeg", "-v", "error", "-i", path, "-ac", "1", "-ar", str(rate), "-f", "s16le", "-"],
        check=True, capture_output=True,
    ).stdout
    return np.frombuffer(raw, dtype=np.int16).astype(np.float32) / 32768.0
