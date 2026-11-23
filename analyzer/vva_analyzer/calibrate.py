"""Interactive camera calibration (OpenCV window). Done once per hall / camera position."""

from __future__ import annotations

from .config import Calibration

def run(frame, existing: Calibration | None = None) -> Calibration:
    import cv2

    from .autocourt import suggest

    cal = existing or Calibration()
    h, w = frame.shape[:2]
    win = "VVA calibration"

    found = suggest(frame)
    # points in the order left-far, left-near, right-near, right-far (pixels)
    points: list[list[int]] = [[int(x * w), int(y * h)] for x, y in found] if found else []
    ours_left = True
    drag: list[int | None] = [None]

    def on_mouse(event, x, y, *_):
        if event == cv2.EVENT_LBUTTONDOWN:
            near = [i for i, p in enumerate(points) if abs(p[0] - x) < 25 and abs(p[1] - y) < 25]
            if near:
                drag[0] = near[0]
            elif len(points) < 4:
                points.append([x, y])
        elif event == cv2.EVENT_MOUSEMOVE and drag[0] is not None:
            points[drag[0]] = [x, y]
        elif event == cv2.EVENT_LBUTTONUP:
            drag[0] = None

    help_auto = "Lines found. Drag points to correct. S = we play on the other half, Enter = OK, C = clear"
    help_manual = "Click the attack line ends: left far, left near, right near, right far. Enter = OK"

    cv2.namedWindow(win, cv2.WINDOW_NORMAL)
    cv2.setMouseCallback(win, on_mouse)
    while True:
        view = frame.copy()
        if len(points) >= 2:
            cv2.line(view, tuple(points[0]), tuple(points[1]), (0, 200, 255), 2)
        if len(points) == 4:
            cv2.line(view, tuple(points[2]), tuple(points[3]), (0, 200, 255), 2)
            ours = points[:2] if ours_left else points[2:]
            cx = int(sum(p[0] for p in ours) / 2 + (-90 if ours_left else 40))
            cy = int(sum(p[1] for p in ours) / 2)
            cv2.putText(view, "MY / US (set 1)", (cx - 60, cy), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0, 255, 0), 3)
        for i, p in enumerate(points):
            cv2.circle(view, tuple(p), 9, (0, 200, 255), -1)
            cv2.putText(view, str(i + 1), (p[0] + 12, p[1] - 12), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0, 200, 255), 2)
        # help at the bottom: the score overlay is usually at the top
        cv2.rectangle(view, (0, h - 56), (w, h), (0, 0, 0), -1)
        cv2.putText(view, help_auto if found else help_manual, (16, h - 18), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255, 255, 255), 2)
        cv2.imshow(win, view)
        key = cv2.waitKey(20) & 0xFF
        if key in (13, 10) and len(points) == 4:
            break
        if key in (ord("s"), ord("S")):
            ours_left = not ours_left
        if key in (ord("c"), ord("C")):
            points.clear()
            found = None
        if key == 8 and points:
            points.pop()
        if key == 27:
            break

    if len(points) == 4:
        ordered = points if ours_left else [points[3], points[2], points[1], points[0]]
        cal.court = [(x / w, y / h) for x, y in ordered]
        cal.court_ref = "attack"
    cal.our_side_set1 = "near"  # the half of points 1-2 is "near" by definition

    cv2.destroyAllWindows()
    cv2.waitKey(1)
    for attr, label in (("score_left", "Drag a box around the POINTS of the FIRST team (left or top row)"),
                        ("score_right", "Drag a box around the POINTS of the SECOND team (right or bottom row)")):
        box = _select_box(frame, label)
        if box:
            x, y, bw, bh = box
            setattr(cal, attr, (x / w, y / h, bw / w, bh / h))
    cv2.destroyAllWindows()
    print("Pola wyniku zaznaczone." if cal.has_scoreboard else "UWAGA: pola wyniku NIE zostały zaznaczone (bez nich nie będzie setów).")

    if cal.has_scoreboard:
        answer = input("Is OUR team the first (left/top) or second (right/bottom) on the overlay? [l/r] ").strip().lower()
        cal.our_score = "right" if answer.startswith("r") else "left"
    return cal


def _select_box(frame, label: str) -> tuple[int, int, int, int] | None:
    """Drag a rectangle; Enter accepts (only once a box is drawn), Esc skips."""
    import cv2

    win = "VVA score overlay"
    h, w = frame.shape[:2]
    state: dict = {"start": None, "box": None, "down": False}

    def on_mouse(event, x, y, *_):
        if event == cv2.EVENT_LBUTTONDOWN:
            state.update(start=(x, y), down=True, box=None)
        elif event == cv2.EVENT_MOUSEMOVE and state["down"]:
            x0, y0 = state["start"]
            state["box"] = (min(x0, x), min(y0, y), abs(x - x0), abs(y - y0))
        elif event == cv2.EVENT_LBUTTONUP:
            state["down"] = False

    cv2.namedWindow(win, cv2.WINDOW_NORMAL)
    cv2.setMouseCallback(win, on_mouse)
    while True:
        view = frame.copy()
        if state["box"]:
            x, y, bw, bh = state["box"]
            cv2.rectangle(view, (x, y), (x + bw, y + bh), (0, 255, 0), 2)
        cv2.rectangle(view, (0, h - 56), (w, h), (0, 0, 0), -1)
        cv2.putText(view, label + "  |  Enter = OK, Esc = skip", (16, h - 18), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255, 255, 255), 2)
        cv2.imshow(win, view)
        key = cv2.waitKey(20) & 0xFF
        if key in (13, 10) and state["box"] and state["box"][2] > 3 and state["box"][3] > 3:
            return state["box"]
        if key == 27:
            return None
