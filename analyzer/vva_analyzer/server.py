"""HTTP endpoint for VVA: set VVA_ANALYZER_URL=http://<this machine>:8765/jobs in the site's .env.

VVA posts {game_id, youtube_url, roster, api_base, ...} (see app/Jobs/RequestVideoAnalysis.php);
jobs run one at a time on the GPU, results are pushed back to the VVA API.
"""


import logging
import queue
import threading
from pathlib import Path

from .api import VvaClient
from .config import Calibration, Settings
from .pipeline import Job

log = logging.getLogger("vva")


def create_app(calibration: Path, api_token: str, secret: str | None, workdir: Path, device: str | None):
    from fastapi import FastAPI, Header, HTTPException
    from pydantic import BaseModel

    app = FastAPI(title="VVA analyzer")
    jobs: queue.Queue = queue.Queue()
    state: dict = {"current": None, "done": []}

    class JobIn(BaseModel):
        game_id: int
        youtube_url: str | None = None
        youtube_id: str | None = None
        api_base: str
        team: str | None = None
        opponent: str | None = None
        roster: list[dict] = []

    def worker():
        while True:
            payload = jobs.get()
            state["current"] = payload["game_id"]
            client = VvaClient(payload["api_base"], api_token)
            try:
                from . import video as vid
                client.progress(payload["game_id"], "processing", 1, "Pobieram nagranie")
                path = vid.download(payload["youtube_url"] or f"https://youtu.be/{payload['youtube_id']}", workdir)
                client.purge_ai(payload["game_id"])
                Job(Calibration.load(calibration), Settings(), workdir, client, payload["game_id"], device).run(path)
                state["done"].append({"game_id": payload["game_id"], "ok": True})
            except Exception as e:
                log.exception("job failed")
                state["done"].append({"game_id": payload["game_id"], "ok": False, "error": str(e)})
                try:
                    client.progress(payload["game_id"], "failed", None, f"Analiza nie powiodła się: {e}")
                except Exception:
                    pass
            finally:
                state["current"] = None
                jobs.task_done()

    threading.Thread(target=worker, daemon=True).start()

    @app.get("/health")
    def health():
        return {"ok": True, "queued": jobs.qsize(), "current": state["current"], "recent": state["done"][-10:]}

    @app.post("/jobs", status_code=202)
    def create(job: JobIn, authorization: str | None = Header(default=None)):
        if secret and authorization != f"Bearer {secret}":
            raise HTTPException(401, "bad token")
        if not (job.youtube_url or job.youtube_id):
            raise HTTPException(422, "youtube_url required")
        jobs.put(job.model_dump())
        return {"queued": True, "position": jobs.qsize()}

    return app
