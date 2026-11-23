"""Command line: `python -m vva_analyzer <calibrate|analyze|serve> ...`."""

from __future__ import annotations

import argparse
import json
import logging
import os
from pathlib import Path

from .config import Calibration, Settings


def _device(arg: str | None) -> str | None:
    if arg:
        return arg
    try:
        import torch
        return "0" if torch.cuda.is_available() else "cpu"
    except ImportError:
        return None


def _video(args, workdir: Path) -> Path:
    from . import video as vid

    if args.video:
        return Path(args.video)
    if args.url:
        print("Downloading the recording...")
        return vid.download(args.url, workdir)
    raise SystemExit("Give --video PATH or --url YOUTUBE_URL")


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="vva-analyzer", description="VVA automatic volleyball video analysis")
    sub = p.add_subparsers(dest="cmd", required=True)

    c = sub.add_parser("calibrate", help="mark the court and the score overlay (once per camera position)")
    c.add_argument("--video"), c.add_argument("--url")
    c.add_argument("--at", type=float, default=120.0, help="second of the video to show")
    c.add_argument("--out", default="calibration.json")

    a = sub.add_parser("analyze", help="analyse one match")
    a.add_argument("--video"), a.add_argument("--url")
    a.add_argument("--calibration", default="calibration.json")
    a.add_argument("--game", type=int, help="VVA match id (from the match URL), to send the results")
    a.add_argument("--api", default=os.environ.get("VVA_API", "http://localhost:8000/api/v1"))
    a.add_argument("--token", default=os.environ.get("VVA_API_TOKEN"), help="club API token (Club > AI integrations)")
    a.add_argument("--device", help="0 = first GPU, cpu = processor (default: GPU when available)")
    a.add_argument("--fps", type=float, help="frames per second to analyse (default 12)")
    a.add_argument("--model", help="YOLO weights (default yolo11s.pt, downloaded automatically)")
    a.add_argument("--fresh", action="store_true", help="ignore saved detections and scan the video again")
    a.add_argument("--workdir", default="work")

    s = sub.add_parser("serve", help="run as a service that VVA sends matches to")
    s.add_argument("--host", default="127.0.0.1"), s.add_argument("--port", type=int, default=8765)
    s.add_argument("--calibration", default="calibration.json")
    s.add_argument("--token", default=os.environ.get("VVA_API_TOKEN"), help="club API token for sending results")
    s.add_argument("--secret", default=os.environ.get("VVA_ANALYZER_TOKEN"), help="must equal VVA_ANALYZER_TOKEN in the site's .env")
    s.add_argument("--device"), s.add_argument("--workdir", default="work")

    args = p.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s", datefmt="%H:%M:%S")
    workdir = Path(getattr(args, "workdir", "work"))
    workdir.mkdir(parents=True, exist_ok=True)

    if args.cmd == "calibrate":
        from . import calibrate, video as vid
        path = _video(args, workdir)
        existing = Calibration.load(args.out) if Path(args.out).exists() else None
        cal = calibrate.run(vid.frame_at(path, args.at), existing)
        cal.save(args.out)
        print(f"Saved {args.out}")
        return 0

    if args.cmd == "analyze":
        from .api import VvaClient
        from .pipeline import Job

        settings = Settings()
        if args.fps:
            settings.sample_fps = args.fps
        if args.model:
            settings.model = args.model
        cal = Calibration.load(args.calibration) if Path(args.calibration).exists() else Calibration()
        if not cal.has_court:
            print("No court calibration: serve side and net crossings will be unknown (run `calibrate`).")
        client = None
        if args.game:
            if not args.token:
                raise SystemExit("--token (or VVA_API_TOKEN) is needed to send results to VVA")
            client = VvaClient(args.api, args.token)
            client.purge_ai(args.game)
        path = _video(args, workdir)
        result = Job(cal, settings, workdir, client, args.game, _device(args.device)).run(path, reuse=not args.fresh)
        print(json.dumps({"sets": result["sets"], **result["stats"]}, indent=2))
        return 0

    if args.cmd == "serve":
        import uvicorn

        from .server import create_app
        if not args.token:
            raise SystemExit("--token (or VVA_API_TOKEN) is needed")
        uvicorn.run(create_app(Path(args.calibration), args.token, args.secret, workdir, _device(args.device)),
                    host=args.host, port=args.port)
        return 0
    return 1
