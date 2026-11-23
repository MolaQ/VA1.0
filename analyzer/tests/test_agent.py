import json

import httpx

from vva_analyzer import agent as ag
from vva_analyzer.api import VvaClient


def test_api_base_accepts_browser_address():
    assert ag.api_base("http://localhost/molak/VVA/public/") == "http://localhost/molak/VVA/public/api/v1"
    assert ag.api_base("vva.example.pl") == "http://vva.example.pl/api/v1"
    assert ag.api_base("https://x.pl/api/v1") == "https://x.pl/api/v1"


def test_config_roundtrip(tmp_path):
    cfg = ag.AgentConfig(server="http://x", token="1|abc", keep_videos=True)
    cfg.save(tmp_path / "c.json")
    back = ag.AgentConfig.load(tmp_path / "c.json")
    assert back.ready and back.token == "1|abc" and back.keep_videos


def _client(handler):
    c = VvaClient("http://vva/api/v1", "t")
    c.http = httpx.Client(base_url="http://vva/api/v1/", transport=httpx.MockTransport(handler))
    return c


def test_claim_returns_none_when_queue_is_empty():
    c = _client(lambda req: httpx.Response(204))
    assert c.claim() is None
    c = _client(lambda req: httpx.Response(200, json={"data": {"id": 4, "youtube_id": "abc"}}))
    assert c.claim()["id"] == 4


def test_agent_waits_for_setup_and_calibration(tmp_path, monkeypatch):
    monkeypatch.setenv("APPDATA", str(tmp_path))
    states = []
    a = ag.Agent(ag.AgentConfig(), lambda st: states.append(st.status))
    assert a.tick() is False and states[-1] == "setup"
    a.cfg.server, a.cfg.token = "http://vva", "t"
    assert a.tick() is False and states[-1] == "calibrate"

    (tmp_path / "VVA Analyzer" / "calibration.json").write_text(json.dumps({
        "score_left": [0.9, 0.0, 0.05, 0.05], "score_right": [0.9, 0.06, 0.05, 0.05],
    }))
    monkeypatch.setattr(ag.Agent, "client", lambda self: _client(lambda req: httpx.Response(204)))
    assert a.tick() is False and states[-1] == "idle"
