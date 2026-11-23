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
    a.add_argument("--rescore", action="store_true", help="read the score overlay again (keeps the ball detections)")
    a.add_argument("--workdir", default="work")

    t = sub.add_parser("score-test", help="check the score overlay boxes: saves the crops and prints what OCR reads")
    t.add_argument("--video"), t.add_argument("--url")
    t.add_argument("--calibration", default="calibration.json")
    t.add_argument("--at", type=float, nargs="+", default=[600.0, 1500.0, 3000.0])
    t.add_argument("--workdir", default="work")

    f = sub.add_parser("serve-frames", help="save frames of our serves (material for recognising the server's number)")
    f.add_argument("--video"), f.add_argument("--url")
    f.add_argument("--calibration", default="calibration.json")
    f.add_argument("--count", type=int, default=40)
    f.add_argument("--workdir", default="work")

    g = sub.add_parser("agent", help="take queued matches from VVA and analyse them (no window)")
    g.add_argument("--server", help="VVA address, e.g. http://localhost/molak/VVA/public (saved for next time)")
    g.add_argument("--token", default=os.environ.get("VVA_API_TOKEN"), help="club API token (saved for next time)")
    g.add_argument("--once", action="store_true", help="analyse at most one match and exit")

    sub.add_parser("tray", help="the agent with an icon in the Windows tray")

    s = sub.add_parser("serve", help="run as a service that VVA sends matches to")
    s.add_argument("--host", default="127.0.0.1"), s.add_argument("--port", type=int, default=8765)
    s.add_argument("--calibration", default="calibration.json")
    s.add_argument("--token", default=os.environ.get("VVA_API_TOKEN"), help="club API token for sending results")
    s.add_argument("--secret", default=os.environ.get("VVA_ANALYZER_TOKEN"), help="must equal VVA_ANALYZER_TOKEN in the site's .env")
    s.add_argument("--device"), s.add_argument("--workdir", default="work")

    args = p.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s", datefmt="%H:%M:%S")
    workdir = Path(getattr(args, "workdir", "work"))
    if hasattr(args, "workdir"):
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
        result = Job(cal, settings, workdir, client, args.game, _device(args.device)).run(
            path, reuse=not args.fresh, rescore=args.rescore)
        print(json.dumps({"sets": result["sets"], **result["stats"]}, indent=2))
        return 0

    if args.cmd == "score-test":
        import cv2

        from . import video as vid
        from .scoreboard import OcrReader, crop
        if not Path(args.calibration).exists():
            raise SystemExit(f"Brak pliku {args.calibration}: najpierw `calibrate`.")
        cal = Calibration.load(args.calibration)
        print(f"Pola wyniku w kalibracji: pierwsza={cal.score_left} druga={cal.score_right}, nasza={cal.our_score}")
        if not cal.has_scoreboard:
            raise SystemExit("Kalibracja nie ma zaznaczonych pól wyniku: uruchom `calibrate` jeszcze raz i zaznacz oba prostokąty.")
        path = _video(args, workdir)
        ocr = OcrReader(gpu=_device(None) not in (None, "cpu"))
        for t in args.at:
            frame = vid.frame_at(path, t)
            r = ocr.read(t, frame, cal)
            for name, box in (("first", cal.score_left), ("second", cal.score_right)):
                cv2.imwrite(str(workdir / f"score_{int(t)}_{name}.png"), crop(frame, box))
            print(f"{int(t)} s: pierwsza={r.left} druga={r.right}  (wycinki: {workdir}\\score_{int(t)}_*.png)")
        return 0

    if args.cmd == "serve-frames":
        import cv2

        from . import video as vid
        from .pipeline import Job
        cal = Calibration.load(args.calibration) if Path(args.calibration).exists() else Calibration()
        path = _video(args, workdir)
        job = Job(cal, Settings(), workdir)
        result = job.run(path)
        out = workdir / "serves"
        out.mkdir(exist_ok=True)
        ours = [r for r in result["rallies"] if r["server"] == "us" and r["start"] is not None][: args.count]
        for r in ours:
            first = next((o for o in job.obs if o.t >= r["start"]), None)
            for dt, tag in ((-1.0, "a"), (0.0, "b")):
                frame = vid.frame_at(path, max(0.0, r["start"] + dt))
                name = f"set{r['set']}_r{r['rally']:02d}_{tag}"
                cv2.imwrite(str(out / f"{name}.jpg"), frame, [cv2.IMWRITE_JPEG_QUALITY, 90])
                if first is not None:
                    h, w = frame.shape[:2]
                    x0, x1 = int(max(0, (first.x - 0.06) * w)), int(min(w, (first.x + 0.06) * w))
                    y0, y1 = int(max(0, (first.y - 0.05) * h)), int(min(h, (first.y + 0.25) * h))
                    cv2.imwrite(str(out / f"{name}_crop.png"), frame[y0:y1, x0:x1])
        print(f"Zapisano {len(ours)} zagrywek do {out} (klatka 1 s przed i w chwili zagrywki, plus wycinek wokol pilki).")
        return 0

    if args.cmd == "agent":
        from .agent import Agent, AgentConfig
        cfg = AgentConfig.load()
        if args.server or args.token:
            cfg.server = args.server or cfg.server
            cfg.token = args.token or cfg.token
            cfg.save()
        agent = Agent(cfg)
        if args.once:
            agent.tick()
            return 0
        print("Agent VVA działa (Ctrl+C kończy). / VVA agent running.")
        try:
            agent.run_forever()
        except KeyboardInterrupt:
            pass
        return 0

    if args.cmd == "tray":
        from .tray import main as tray_main
        tray_main()
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
