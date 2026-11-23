"""Getting the recording and reading frames."""

from __future__ import annotations

from collections.abc import Iterator
from pathlib import Path


def download(url: str, workdir: Path, max_height: int = 1080) -> Path:
    """Download a YouTube video (only recordings you own or may analyse) as mp4."""
    import shutil

    from yt_dlp import YoutubeDL

    if shutil.which("ffmpeg") is None:
        raise SystemExit(
            "ffmpeg nie jest widoczny w tym terminalu (ffmpeg not found on PATH).\n"
            "Zainstaluj go (README: Instalacja, krok 2), otwórz NOWY terminal i sprawdź `ffmpeg -version`."
        )

    workdir.mkdir(parents=True, exist_ok=True)
    opts = {
        "format": f"bv*[height<={max_height}][ext=mp4]+ba[ext=m4a]/b[height<={max_height}]/b",
        "merge_output_format": "mp4",
        "outtmpl": str(workdir / "%(id)s.%(ext)s"),
        "quiet": True,
        "noprogress": True,
    }
    with YoutubeDL(opts) as ydl:
        info = ydl.extract_info(url, download=True)
        path = Path(ydl.prepare_filename(info)).with_suffix(".mp4")
    return path


def frames(path: Path, sample_fps: float, start: float = 0.0) -> Iterator[tuple[float, "object"]]:
    """(time in seconds, BGR frame) at roughly `sample_fps`."""
    import cv2

    cap = cv2.VideoCapture(str(path))
    if not cap.isOpened():
        raise RuntimeError(f"Cannot open video {path}")
    fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
    step = max(1, round(fps / sample_fps))
    if start:
        cap.set(cv2.CAP_PROP_POS_MSEC, start * 1000)
    index = int(start * fps)
    try:
        while True:
            ok = cap.grab()
            if not ok:
                break
            if index % step == 0:
                ok, frame = cap.retrieve()
                if ok:
                    yield index / fps, frame
            index += 1
    finally:
        cap.release()


def info(path: Path) -> tuple[int, int, float, float]:
    """width, height, fps, duration (s)."""
    import cv2

    cap = cv2.VideoCapture(str(path))
    w = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    h = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
    n = cap.get(cv2.CAP_PROP_FRAME_COUNT)
    cap.release()
    return w, h, fps, (n / fps if n else 0.0)


def frame_at(path: Path, t: float):
    import cv2

    cap = cv2.VideoCapture(str(path))
    cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000)
    ok, frame = cap.read()
    cap.release()
    if not ok:
        raise RuntimeError(f"No frame at {t}s")
    return frame
