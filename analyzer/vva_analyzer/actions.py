"""Rallies -> VVA API actions."""

from __future__ import annotations

from .rallies import Rally


def serve_actions(rallies: list[Rally]) -> list[dict]:
    """One serve per rally served by our team. The server's number comes in stage 2."""
    out = []
    for r in rallies:
        if r.server != "us" or r.serve_grade is None:
            continue
        out.append({
            "external_id": f"s1-set{r.set}-r{r.number}-serve",
            "set": r.set,
            "rally": r.number,
            "skill": "serve",
            "grade": r.serve_grade,
            "video_time": round(r.start, 2) if r.start is not None else None,
            "confidence": round(r.confidence, 2),
            "meta": {
                "stage": 1,
                "rally_won": r.winner == "us",
                "duration": round(r.duration, 1) if r.duration is not None else None,
                "score_before": list(r.score_before),
            },
        })
    return out


def report(rallies: list[Rally]) -> list[dict]:
    """Every rally (both teams) for the JSON report kept next to the video."""
    return [{
        "set": r.set, "rally": r.number, "start": r.start, "end": r.end,
        "server": r.server, "winner": r.winner, "score_before": list(r.score_before),
        "serve_side": r.serve_side, "crossings": r.crossings, "serve_grade": r.serve_grade,
        "confidence": r.confidence,
    } for r in rallies]
