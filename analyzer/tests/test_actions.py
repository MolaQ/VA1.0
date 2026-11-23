from vva_analyzer.actions import serve_actions
from vva_analyzer.rallies import Rally


def test_only_our_serves_become_actions_with_stable_ids():
    rallies = [
        Rally(1, 1, 10.0, 18.0, "us", "us", (0, 0), serve_grade="!"),
        Rally(1, 2, 40.0, 42.0, "them", "us", (1, 0), serve_grade="="),
        Rally(1, 3, 60.0, 70.0, "us", "them", (1, 1), serve_grade="!"),
    ]
    out = serve_actions(rallies)
    assert [a["external_id"] for a in out] == ["s1-set1-r1-serve", "s1-set1-r2-serve"]
    assert out[1]["grade"] == "=" and out[1]["video_time"] == 40.0 and out[1]["meta"]["rally_won"] is False
