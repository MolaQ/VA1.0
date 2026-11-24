import numpy as np

from vva_analyzer.whistle import detect


def test_detects_a_whistle_in_crowd_noise():
    rate = 16000
    rng = np.random.default_rng(1)
    signal = rng.normal(0, 0.05, rate * 6).astype(np.float32)
    t = np.arange(int(rate * 0.6)) / rate
    signal[rate * 3: rate * 3 + len(t)] += 0.5 * np.sin(2 * np.pi * 3200 * t)
    hits = detect(signal, rate)
    assert len(hits) == 1 and 2.9 <= hits[0] <= 3.1


def test_noise_alone_is_not_a_whistle():
    rate = 16000
    signal = np.random.default_rng(2).normal(0, 0.2, rate * 5).astype(np.float32)
    assert detect(signal, rate) == []
