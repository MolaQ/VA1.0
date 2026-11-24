"""Rallies -> VVA API actions."""

from __future__ import annotations

from .rallies import Rally


def serve_actions(rallies: list[Rally], extra: dict[tuple[int, int], dict] | None = None) -> list[dict]:
    """One serve per rally served by our team, with the server's number when the rotation is known.

    `extra` (from touches.ball_actions): where the opponent received it and, when the pass
    was seen, the serve grade paired with their reception."""
    extra = extra or {}
    out = []
    for r in rallies:
        if r.server != "us" or r.serve_grade is None:
            continue
        more = extra.get((r.set, r.number), {})
        out.append({
            "external_id": f"s1-set{r.set}-r{r.number}-serve",
            "set": r.set,
            "rally": r.number,
            **({"player_number": r.server_number} if r.server_number is not None else {}),
            "skill": "serve",
            "grade": more.get("grade", r.serve_grade),
            **({"to_x": more["to_x"], "to_y": more["to_y"]} if "to_x" in more else {}),
            "video_time": round(r.start, 2) if r.start is not None else None,
            "confidence": round(r.confidence, 2),
            "meta": {
                "stage": 2 if r.server_number is not None else 1,
                "rally_won": r.winner == "us",
                "duration": round(r.duration, 1) if r.duration is not None else None,
                "score_before": list(r.score_before),
                **({"opponent_reception": more["opponent_reception"]} if "opponent_reception" in more else {}),
            },
        })
    return out


def report(rallies: list[Rally]) -> list[dict]:
    """Every rally (both teams) for the JSON report kept next to the video."""
    return [{
        "set": r.set, "rally": r.number, "start": r.start, "end": r.end,
        "server": r.server, "server_number": r.server_number, "winner": r.winner, "score_before": list(r.score_before),
        "serve_side": r.serve_side, "crossings": r.crossings, "serve_grade": r.serve_grade,
        "confidence": r.confidence,
    } for r in rallies]


def rally_outcomes(report_rows: list[dict]) -> list[dict]:
    """Server and winner of every rally (both teams), from `report()` rows, for the VVA rallies endpoint."""
    side = {"us", "them"}
    out = []
    for r in report_rows:
        server = r.get("server") if r.get("server") in side else None
        winner = r.get("winner") if r.get("winner") in side else None
        if server is None and winner is None:
            continue
        start = r.get("start")
        out.append({
            "set": r["set"],
            "rally": r["rally"],
            "server": server,
            "winner": winner,
            "video_time": round(start, 2) if start is not None else None,
        })
    return out
