import numpy as np
import pytest

cv2 = pytest.importorskip("cv2")

from vva_analyzer.autocourt import suggest  # noqa: E402


def side_view_frame():
    """A 1920x1080 side-stand view: grey hall, orange court, white lines, blue net post, dashed attack line extensions."""
    img = np.full((1080, 1920, 3), (90, 90, 90), np.uint8)
    img[:400] = (200, 200, 200)  # stands
    far_y, near_y = 540, 810

    def x_at(top_x, y):  # lines across the court lean outwards towards the camera
        return int(top_x + (top_x - 950) * 0.45 * (y - far_y) / (near_y - far_y))

    court = np.array([[330, far_y], [1570, far_y], [x_at(1570, near_y), near_y], [x_at(330, near_y), near_y]])
    cv2.fillPoly(img, [court], (60, 120, 230))
    white = (245, 245, 245)
    cv2.line(img, (0, far_y), (1919, far_y), white, 4)
    cv2.line(img, (0, near_y), (1919, near_y), white, 4)
    for top in (330, 740, 1160, 1570):
        cv2.line(img, (top, far_y), (x_at(top, near_y), near_y), white, 4)
    for top in (740, 1160):  # dashed extensions beyond both sidelines
        for y in range(near_y + 20, 1000, 40):
            cv2.line(img, (x_at(top, y), y), (x_at(top, y + 20), y + 20), white, 4)
    cv2.rectangle(img, (940, 380), (962, 880), (200, 80, 20), -1)  # net post
    return img


def test_finds_the_attack_line_ends():
    pts = suggest(side_view_frame())
    assert pts is not None
    got = [(round(x * 1920), round(y * 1080)) for x, y in pts]
    lean = lambda top: int(top + (top - 950) * 0.45)  # noqa: E731
    want = [(740, 540), (lean(740), 810), (lean(1160), 810), (1160, 540)]
    for (gx, gy), (wx, wy) in zip(got, want):
        assert abs(gx - wx) < 12 and abs(gy - wy) < 12, (got, want)
