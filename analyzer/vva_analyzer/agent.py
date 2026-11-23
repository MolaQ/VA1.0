"""Analysis agent: takes queued matches from VVA, analyses them on this computer, sends results.

One agent per club computer with a graphics card. It asks the server for work
(POST /api/v1/agent/claim) every `poll_s` seconds, so no ports have to be opened at home:
the agent only makes outgoing requests, like a browser.
"""

from __future__ import annotations

import json
import logging
import os
import threading
import time
from collections.abc import Callable
from dataclasses import asdict, dataclass, field
from pathlib import Path

from .api import VvaClient
from .config import Calibration, Settings

log = logging.getLogger("vva")


def app_dir() -> Path:
    """%APPDATA%\\VVA Analyzer on Windows, ~/.config/vva-analyzer elsewhere."""
    base = os.environ.get("APPDATA")
    path = Path(base) / "VVA Analyzer" if base else Path.home() / ".config" / "vva-analyzer"
    path.mkdir(parents=True, exist_ok=True)
    return path


def api_base(server: str) -> str:
    """Accepts the site address as shown in the browser, with or without /api/v1."""
    server = server.strip().rstrip("/")
    if not server.startswith(("http://", "https://")):
        server = "http://" + server
    return server if server.endswith("/api/v1") else server + "/api/v1"


@dataclass
class AgentConfig:
    server: str = ""  # e.g. http://localhost/molak/VVA/public
    token: str = ""
    device: str | None = None  # None = GPU when available
    poll_s: float = 20.0
    keep_videos: bool = False  # delete a recording after a successful analysis (they take GBs)
    workdir: str = ""
    calibration: str = ""

    @property
    def ready(self) -> bool:
        return bool(self.server and self.token)

    @property
    def work_path(self) -> Path:
        return Path(self.workdir) if self.workdir else app_dir() / "work"

    @property
    def calibration_path(self) -> Path:
        return Path(self.calibration) if self.calibration else app_dir() / "calibration.json"

    @classmethod
    def load(cls, path: Path | None = None) -> "AgentConfig":
        path = path or app_dir() / "config.json"
        if not path.exists():
            return cls()
        data = json.loads(path.read_text(encoding="utf-8"))
        return cls(**{k: v for k, v in data.items() if k in cls.__dataclass_fields__})

    def save(self, path: Path | None = None) -> None:
        path = path or app_dir() / "config.json"
        path.write_text(json.dumps(asdict(self), indent=2), encoding="utf-8")


@dataclass
class AgentState:
    status: str = "idle"  # idle / working / setup / calibrate / error / offline
    message: str = "Czekam na mecze"
    game_id: int | None = None
    progress: int = 0
    done: list[dict] = field(default_factory=list)


class Agent:
    def __init__(self, cfg: AgentConfig, on_change: Callable[[AgentState], None] | None = None):
        self.cfg = cfg
        self.state = AgentState()
        self.on_change = on_change or (lambda st: None)
        self.wake = threading.Event()
        self.stop = threading.Event()
        self.busy = threading.Lock()  # one analysis at a time (and not during calibration)

    # ------------------------------------------------------------ state

    def _set(self, status: str, message: str, progress: int | None = None) -> None:
        self.state.status, self.state.message = status, message
        if progress is not None:
            self.state.progress = progress
        log.info("[%s] %s", status, message)
        try:
            self.on_change(self.state)
        except Exception:  # the tray must never stop the agent
            log.exception("status callback failed")

    def client(self) -> VvaClient:
        return VvaClient(api_base(self.cfg.server), self.cfg.token)

    # ------------------------------------------------------------ loop

    def run_forever(self) -> None:
        while not self.stop.is_set():
            try:
                worked = self.tick()
            except Exception as e:
                log.exception("agent tick failed")
                self._set("offline", f"Brak połączenia z VVA: {e}")
                worked = False
            if not worked:
                self.wake.wait(self.cfg.poll_s)
                self.wake.clear()

    def tick(self) -> bool:
        """Take and analyse one match. True when there was work."""
        if not self.cfg.ready:
            self._set("setup", "Uzupełnij ustawienia: adres VVA i token")
            return False
        cal_path = self.cfg.calibration_path
        if not cal_path.exists() or not Calibration.load(cal_path).has_scoreboard:
            self._set("calibrate", "Skalibruj kamerę (menu: Kalibruj kamerę)")
            return False
        with self.busy:
            game = self.client().claim()
            if game is None:
                self._set("idle", "Czekam na mecze", 0)
                return False
            self.process(game, Calibration.load(cal_path))
        return True

    def process(self, game: dict, cal: Calibration) -> None:
        from . import video as vid
        from .pipeline import Job

        gid = int(game["id"])
        client = self.client()
        self.state.game_id = gid
        title = f"mecz z {game.get('opponent') or gid}"
        url = game.get("youtube_url") or f"https://youtu.be/{game['youtube_id']}"
        workdir = self.cfg.work_path
        workdir.mkdir(parents=True, exist_ok=True)

        last = [-1]

        def dl(pct: int) -> None:
            if pct // 10 != last[0]:
                last[0] = pct // 10
                self._set("working", f"{title}: pobieranie {pct}%", pct // 20)
                try:
                    client.progress(gid, "processing", max(1, pct // 20), f"Pobieram nagranie: {pct}%")
                except Exception:
                    pass

        def prog(pct: int, msg: str) -> None:
            self._set("working", f"{title}: {msg}", pct)

        try:
            self._set("working", f"{title}: pobieranie", 0)
            path = vid.download(url, workdir, on_progress=dl)
            client.purge_ai(gid)
            job = Job(cal, Settings(), workdir, client, gid, self.cfg.device, on_progress=prog)
            result = job.run(path)
            sets = ", ".join(f"{x['us']}:{x['them']}" for x in result["sets"]) or "brak wyniku"
            self.state.done.append({"game_id": gid, "ok": True, "sets": sets, "at": time.time()})
            self._set("idle", f"Gotowe: {title} ({sets})", 100)
            if not self.cfg.keep_videos:
                path.unlink(missing_ok=True)
        except Exception as e:
            log.exception("analysis of game %s failed", gid)
            self.state.done.append({"game_id": gid, "ok": False, "error": str(e), "at": time.time()})
            self._set("error", f"Błąd: {title}: {e}")
            try:
                client.progress(gid, "failed", None, f"Analiza nie powiodła się: {e}")
            except Exception:
                pass
        finally:
            self.state.game_id = None
