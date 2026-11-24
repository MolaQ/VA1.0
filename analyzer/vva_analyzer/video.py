"""Getting the recording and reading frames."""

from __future__ import annotations

from collections.abc import Iterator
from pathlib import Path


def ffmpeg_exe() -> str:
    """ffmpeg from PATH, else the copy bundled with the imageio-ffmpeg package."""
    import shutil

    found = shutil.which("ffmpeg")
    if found:
        return found
    try:
        import imageio_ffmpeg

        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception as e:  # pragma: no cover - depends on the machine
        raise SystemExit(
            "ffmpeg nie znaleziony (ffmpeg not found). Uruchom: python -m pip install imageio-ffmpeg"
        ) from e


_last_shown = [-1]


def _print_progress(d: dict) -> None:
    if d.get("status") == "downloading":
        total = d.get("total_bytes") or d.get("total_bytes_estimate") or 0
        if total:
            pct = int(100 * d.get("downloaded_bytes", 0) / total)
            if pct // 5 != _last_shown[0]:
                _last_shown[0] = pct // 5
                print(f"  pobieranie / download: {pct}% z {total / 1e9:.1f} GB", flush=True)
    elif d.get("status") == "finished":
        _last_shown[0] = -1
        print("  pobrane / downloaded", flush=True)


def download(url: str, workdir: Path, max_height: int = 1080, on_progress=None) -> Path:
    """Download a YouTube video (only recordings you own or may analyse) as mp4.

    `on_progress(percent)` is called as the download advances (the agent reports it to VVA).
    """
    from yt_dlp import YoutubeDL

    def hook(d: dict) -> None:
        _print_progress(d)
        total = d.get("total_bytes") or d.get("total_bytes_estimate") or 0
        if on_progress and d.get("status") == "downloading" and total:
            on_progress(int(100 * d.get("downloaded_bytes", 0) / total))

    workdir.mkdir(parents=True, exist_ok=True)
    opts = {
        "format": f"bv*[height<={max_height}][ext=mp4]+ba[ext=m4a]/b[height<={max_height}]/b",
        "merge_output_format": "mp4",
        "outtmpl": str(workdir / "%(id)s.%(ext)s"),
        "quiet": True,
        "noprogress": True,
        "progress_hooks": [hook],
        "ffmpeg_location": ffmpeg_exe(),
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
