from pathlib import Path

import pytest

fastapi = pytest.importorskip("fastapi")
from fastapi.testclient import TestClient  # noqa: E402

from vva_analyzer.server import create_app  # noqa: E402


def client(tmp_path: Path) -> TestClient:
    return TestClient(create_app(tmp_path / "calibration.json", "api-token", "secret", tmp_path, "cpu"))


def test_rejects_wrong_secret(tmp_path: Path):
    r = client(tmp_path).post("/jobs", json={"game_id": 1, "api_base": "http://vva/api/v1", "youtube_url": "u"},
                              headers={"Authorization": "Bearer nope"})
    assert r.status_code == 401


def test_requires_a_video(tmp_path: Path):
    r = client(tmp_path).post("/jobs", json={"game_id": 1, "api_base": "http://vva/api/v1"},
                              headers={"Authorization": "Bearer secret"})
    assert r.status_code == 422


def test_health(tmp_path: Path):
    assert client(tmp_path).get("/health").json()["ok"] is True
