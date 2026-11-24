"""Client for the VVA machine API (Sanctum token with the `analysis:write` ability)."""

from __future__ import annotations

import time

import httpx


class VvaClient:
    def __init__(self, api_base: str, token: str, timeout: float = 30.0):
        self.http = httpx.Client(
            base_url=api_base.rstrip("/") + "/",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            timeout=timeout,
        )

    def _call(self, method: str, url: str, **kw) -> httpx.Response:
        for attempt in range(4):
            try:
                r = self.http.request(method, url, **kw)
                if r.status_code == 429 or r.status_code >= 500:
                    raise httpx.HTTPStatusError("retry", request=r.request, response=r)
                if r.status_code == 401:
                    raise SystemExit(
                        f"Serwer {self.http.base_url} odrzucił token (401).\n"
                        "Token musi pochodzić z tej samej strony VVA, której adres jest w ustawieniach "
                        "(np. utworzony na http://127.0.0.1:8000 nie zadziała na http://localhost/molak/VVA/public).\n"
                        f"VVA at {self.http.base_url} rejected the token (401)."
                    )
                if r.status_code == 403:
                    raise SystemExit(f"Brak uprawnien do {url} (403): token musi nalezec do klubu tego meczu.")
                r.raise_for_status()
                return r
            except (httpx.TransportError, httpx.HTTPStatusError) as e:
                if isinstance(e, httpx.HTTPStatusError) and e.response.status_code < 500 and e.response.status_code != 429:
                    raise
                if attempt == 3:
                    raise
                time.sleep(2 ** attempt)
        raise RuntimeError("unreachable")

    def claim(self) -> dict | None:
        """Next match queued for analysis in this token's club, or None."""
        r = self._call("POST", "agent/claim")
        return None if r.status_code == 204 or not r.content else r.json().get("data")

    def me(self) -> dict:
        return self._call("GET", "me").json()

    def game(self, game_id: int) -> dict:
        return self._call("GET", f"games/{game_id}").json()

    def progress(self, game_id: int, status: str, progress: int | None = None, message: str | None = None) -> None:
        body = {"status": status, "progress": progress, "message": message}
        self._call("PATCH", f"games/{game_id}/analysis", json={k: v for k, v in body.items() if v is not None})

    def purge_ai(self, game_id: int) -> int:
        return int(self._call("DELETE", f"games/{game_id}/actions", params={"source": "ai"}).json().get("deleted", 0))

    def put_set(self, game_id: int, number: int, us: int, them: int, video_start: float | None,
                our_side: str | None = None) -> None:
        body: dict = {"score_us": us, "score_them": them}
        if video_start is not None:
            body["video_start"] = round(video_start, 2)
        if our_side is not None:
            body["our_side"] = our_side  # half of the picture we defend: the 2D court view needs it
        self._call("PUT", f"games/{game_id}/sets/{number}", json=body)

    def post_rallies(self, game_id: int, rallies: list[dict], chunk: int = 500) -> int:
        """Who served and who won each rally: feeds side-out / break-point and rotation stats."""
        saved = 0
        for i in range(0, len(rallies), chunk):
            saved += int(self._call("POST", f"games/{game_id}/rallies", json={"rallies": rallies[i:i + chunk]}).json().get("saved", 0))
        return saved

    def post_frames(self, game_id: int, frames: list[dict], chunk: int = 500) -> int:
        """Player positions for the 2D court view; idempotent by video time."""
        saved = 0
        for i in range(0, len(frames), chunk):
            saved += int(self._call("POST", f"games/{game_id}/frames", json={"frames": frames[i:i + chunk]}).json().get("saved", 0))
        return saved

    def post_actions(self, game_id: int, actions: list[dict], chunk: int = 200) -> dict:
        totals = {"created": 0, "updated": 0, "skipped": []}
        for i in range(0, len(actions), chunk):
            res = self._call("POST", f"games/{game_id}/actions", json={"actions": actions[i:i + chunk]}).json()
            totals["created"] += res.get("created", 0)
            totals["updated"] += res.get("updated", 0)
            totals["skipped"] += res.get("skipped", [])
        return totals
