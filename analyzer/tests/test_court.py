from vva_analyzer.court import NetLine, homography, net_crossings, to_court, zone


def side_view():
    # camera at the side: near half on the left of the image, far half on the right
    corners = [(0.10, 0.80), (0.20, 0.40), (0.80, 0.40), (0.90, 0.80)]  # NL, NR, FR, FL (normalised)
    return homography(corners, 1000, 1000)


def test_homography_maps_corners_to_court_metres():
    h = side_view()
    assert [round(v, 3) for v in to_court(h, 100, 800)] == [0, 0]
    assert [round(v, 3) for v in to_court(h, 800, 400)] == [9, 18]


def test_net_line_splits_halves_also_for_a_ball_in_the_air():
    net = NetLine(side_view())
    assert net.half(250, 600) == "near"
    assert net.half(750, 600) == "far"
    assert net.half(300, 50) == "near"  # high above the near half


def test_crossings_ignore_single_flickers():
    assert net_crossings(["near"] * 4 + ["far"] + ["near"] * 3) == 0
    assert net_crossings(["near"] * 3 + ["far"] * 3 + ["near"] * 3) == 2


def test_zones():
    assert zone(1, 1) == 5 and zone(8, 1) == 1 and zone(4.5, 8) == 3
    assert zone(8, 17) == 5  # far team: their left is the image right
    assert zone(-1, 3) is None
