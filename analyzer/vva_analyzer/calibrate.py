"""Interactive camera calibration (OpenCV window). Done once per hall / camera position."""

from __future__ import annotations

from .config import Calibration

STEPS = [
    "1/4: corner of the END LINE on the half where WE play in set 1 (left one)",
    "2/4: the other corner of that end line",
    "3/4: far end line corner, same side as point 2",
    "4/4: far end line corner, same side as point 1",
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
    cal.court = [(x / w, y / h) for x, y in points] if len(points) == 4 else cal.court
    cal.our_side_set1 = "near"  # the half of points 1-2 is "near" by definition

    for attr, label in (("score_left", "LEFT team points on the score overlay"),
                        ("score_right", "RIGHT team points on the score overlay")):
        print(f"Draw a box around the {label}, then Enter (Esc = no overlay)")
        x, y, bw, bh = cv2.selectROI(win, frame, showCrosshair=False)
        if bw and bh:
            setattr(cal, attr, (x / w, y / h, bw / w, bh / h))
    cv2.destroyAllWindows()

    if cal.has_scoreboard:
        answer = input("Are OUR points on the left or right of the overlay? [l/r] ").strip().lower()
        cal.our_score = "right" if answer.startswith("r") else "left"
    return cal
