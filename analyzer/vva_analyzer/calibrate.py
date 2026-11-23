"""Interactive camera calibration (OpenCV window). Done once per hall / camera position."""

from __future__ import annotations

from .config import Calibration

STEPS = [
    "1/4: OUR half in set 1: end of the 3 m attack line on the far sideline",
    "2/4: the other end of that attack line (near sideline)",
    "3/4: OTHER half: end of its attack line on the near sideline",
    "4/4: the other end of that attack line (far sideline)",
]


def run(frame, existing: Calibration | None = None) -> Calibration:
    import cv2

    cal = existing or Calibration()
    h, w = frame.shape[:2]
    points: list[tuple[int, int]] = []
    win = "VVA calibration"

    def on_click(event, x, y, *_):
        if event == cv2.EVENT_LBUTTONDOWN and len(points) < 4:
            points.append((x, y))

    cv2.namedWindow(win, cv2.WINDOW_NORMAL)
    cv2.setMouseCallback(win, on_click)
    while len(points) < 4:
        view = frame.copy()
        for i, p in enumerate(points):
            cv2.circle(view, p, 7, (0, 200, 255), -1)
            cv2.putText(view, str(i + 1), (p[0] + 10, p[1] - 10), cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 200, 255), 2)
        if len(points) > 1:
            cv2.polylines(view, [__import__("numpy").array(points)], False, (0, 200, 255), 2)
        cv2.putText(view, STEPS[len(points)] + "   (Backspace = undo)", (20, 40), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255, 255, 255), 2)
        cv2.imshow(win, view)
        key = cv2.waitKey(30) & 0xFF
        if key == 8 and points:
            points.pop()
        if key == 27:
            break
    if len(points) == 4:
        cal.court = [(x / w, y / h) for x, y in points]
        cal.court_ref = "attack"
    cal.our_side_set1 = "near"  # the half of points 1-2 is "near" by definition

    for attr, label in (("score_left", "points of the FIRST team on the overlay (left or top row)"),
                        ("score_right", "points of the SECOND team on the overlay (right or bottom row)")):
        print(f"Draw a box around the {label}, then Enter (Esc = no overlay)")
        x, y, bw, bh = cv2.selectROI(win, frame, showCrosshair=False)
        if bw and bh:
            setattr(cal, attr, (x / w, y / h, bw / w, bh / h))
    cv2.destroyAllWindows()

    if cal.has_scoreboard:
        answer = input("Is OUR team the first (left/top) or second (right/bottom) on the overlay? [l/r] ").strip().lower()
        cal.our_score = "right" if answer.startswith("r") else "left"
    return cal
