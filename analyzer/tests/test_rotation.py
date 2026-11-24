from vva_analyzer.players import Seen, Track, Tracking
from vva_analyzer.rallies import Rally
from vva_analyzer.rotation import match_zones, rotation_votes, zone_numbers

LINEUP = [7, 13, 4, 10, 2, 9]  # starting positions I..VI


def test_zone_numbers_follow_the_rotation_and_the_libero():
    assert zone_numbers(LINEUP, 0, serving=True) == {1: 7, 2: 13, 3: 4, 4: 10, 5: 2, 6: 9}
    assert zone_numbers(LINEUP, 1, serving=False) == {1: 13, 2: 4, 3: 10, 4: 2, 5: 9, 6: 7}
    # 4 and 9 are middles: the libero takes the back-row one, the front one stays
    assert zone_numbers(LINEUP, 0, serving=True, libero=1, middles={4, 9})[6] == 1
    assert zone_numbers(LINEUP, 0, serving=True, libero=1, middles={4, 9})[3] == 4
    # the middle in zone 1 serves himself, but is replaced when we receive
    assert zone_numbers(LINEUP, 2, serving=True, libero=1, middles={4, 9})[1] == 4
    assert zone_numbers(LINEUP, 2, serving=False, libero=1, middles={4, 9})[1] == 1


def test_overlap_rule_picks_the_order():
    # receiving formation: setter (zone 1) close behind zone 2, everyone shifted
    players = [(7.0, 4.5), (7.8, 7.5), (4.0, 7.8), (1.0, 6.8), (2.0, 2.5), (4.8, 3.0)]
    assert match_zones(players, serving=False) == {1: 0, 2: 1, 3: 2, 4: 3, 5: 4, 6: 5}
    # serving: the server behind the end line is zone 1 whatever x
    players = [(1.0, -1.0), (7.8, 7.5), (4.0, 7.8), (1.0, 6.8), (2.0, 2.5), (6.0, 3.0)]
    assert match_zones(players, serving=True)[1] == 0
    assert match_zones(players[:5], serving=True) is None


def test_rotation_votes_name_the_tracks():
    spots = {1: (7.5, -1.0), 2: (7.5, 7.0), 3: (4.5, 7.0), 4: (1.5, 7.0), 5: (1.5, 2.0), 6: (4.5, 2.0)}
    seen = [Seen(10 + z, x, y, 0.0, None) for z, (x, y) in spots.items()]
    tracking = Tracking([(10.0, seen), (40.0, seen)], {}, {10 + z: Track(10 + z, 0, 0, 0) for z in spots})
    rallies = [Rally(set=1, number=1, start=10.0, end=15.0, server="us", rotation=0),
               Rally(set=1, number=2, start=40.0, end=45.0, server="us", rotation=0)]
    assert rotation_votes(rallies, tracking, {1: LINEUP}, "near") == 2
    assert tracking.numbers == {11: 7, 12: 13, 13: 4, 14: 10, 15: 2, 16: 9}
