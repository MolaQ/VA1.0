"""Windows tray app: the analysis agent with an icon next to the clock.

Start: `pythonw -m vva_analyzer tray` (the installer adds a Start menu and autostart shortcut).
"""

from __future__ import annotations

import logging
import os
import subprocess
import sys
import threading
import webbrowser
from logging.handlers import RotatingFileHandler

from .agent import Agent, AgentConfig, AgentState, api_base, app_dir

log = logging.getLogger("vva")

COLORS = {
    "idle": (34, 197, 94),
    "working": (59, 130, 246),
    "setup": (245, 158, 11),
    "calibrate": (245, 158, 11),
    "offline": (148, 163, 184),
    "error": (239, 68, 68),
}


def icon_image(status: str):
    from PIL import Image, ImageDraw

    size = 64
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.ellipse((2, 2, size - 2, size - 2), fill=COLORS.get(status, COLORS["idle"]))
    # a white "V"
    d.line([(18, 18), (32, 48), (46, 18)], fill=(255, 255, 255), width=7, joint="curve")
    return img


def setup_logging() -> str:
    path = app_dir() / "agent.log"
    handler = RotatingFileHandler(path, maxBytes=2_000_000, backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    root = logging.getLogger()
    root.setLevel(logging.INFO)
    root.addHandler(handler)
    return str(path)


# ----------------------------------------------------------------- dialogs (tkinter)

def clipboard_support(entry, root) -> None:
    """Ctrl+V/C/A by physical key (Tk misses them with CapsLock or some layouts) + right-click menu."""
    import tkinter as tk

    def paste(*_):
        try:
            text = root.clipboard_get().strip()
        except tk.TclError:
            return "break"
        try:
            entry.delete("sel.first", "sel.last")
        except tk.TclError:
            pass
        entry.insert("insert", text)
        return "break"

    def on_ctrl(event):
        # Windows virtual key codes: V=86, C=67, A=65, X=88
        if event.keycode == 86:
            return paste()
        if event.keycode == 65:
            entry.select_range(0, "end")
            return "break"
        if event.keycode in (67, 88):
            entry.event_generate("<<Copy>>" if event.keycode == 67 else "<<Cut>>")
            return "break"
        return None

    entry.bind("<Control-KeyPress>", on_ctrl)
    menu = tk.Menu(entry, tearoff=0)
    menu.add_command(label="Wklej", command=paste)
    menu.add_command(label="Zaznacz wszystko", command=lambda: entry.select_range(0, "end"))
    menu.add_command(label="Wyczyść", command=lambda: entry.delete(0, "end"))
    entry.bind("<Button-3>", lambda e: menu.tk_popup(e.x_root, e.y_root))
    return paste


def settings_dialog(cfg: AgentConfig) -> bool:
    """Server address + token. Returns True when saved."""
    import tkinter as tk
    from tkinter import messagebox

    from .api import VvaClient

    saved = [False]
    root = tk.Tk()
    root.title("VVA Analyzer: ustawienia")
    root.resizable(False, False)
    pad = {"padx": 10, "pady": 4}

    tk.Label(root, text="Adres VVA (jak w przeglądarce):").grid(row=0, column=0, sticky="w", **pad)
    server = tk.Entry(root, width=52)
    server.insert(0, cfg.server or "http://localhost/molak/VVA/public")
    server.grid(row=1, column=0, **pad)
    tk.Label(root, text="Token z VVA (Klub → Integracje AI → Utwórz token):").grid(row=2, column=0, sticky="w", **pad)
    row = tk.Frame(root)
    row.grid(row=3, column=0, sticky="w", **pad)
    token = tk.Entry(row, width=40, show="•")
    token.insert(0, cfg.token)
    token.pack(side="left")
    paste_token = clipboard_support(token, root)
    clipboard_support(server, root)

    def paste_button() -> None:
        token.delete(0, "end")
        paste_token()

    tk.Button(row, text="Wklej ze schowka", command=paste_button).pack(side="left", padx=(6, 0))
    shown = tk.BooleanVar(value=False)
    tk.Checkbutton(row, text="pokaż", variable=shown,
                   command=lambda: token.config(show="" if shown.get() else "•")).pack(side="left", padx=(6, 0))
    gpu = tk.BooleanVar(value=cfg.device != "cpu")
    tk.Checkbutton(root, text="Używaj karty graficznej (zalecane)", variable=gpu).grid(row=4, column=0, sticky="w", **pad)
    keep = tk.BooleanVar(value=cfg.keep_videos)
    tk.Checkbutton(root, text="Zachowuj pobrane nagrania (zajmują kilka GB)", variable=keep).grid(row=5, column=0, sticky="w", **pad)

    def save() -> None:
        s, tok = server.get().strip(), token.get().strip()
        try:
            me = VvaClient(api_base(s), tok, timeout=10).me()
        except SystemExit as e:
            messagebox.showerror("VVA Analyzer", str(e), parent=root)
            return
        except Exception as e:
            messagebox.showerror("VVA Analyzer", f"Nie mogę połączyć się z VVA:\n{e}", parent=root)
            return
        cfg.server, cfg.token = s, tok
        cfg.device = None if gpu.get() else "cpu"
        cfg.keep_videos = keep.get()
        cfg.save()
        clubs = ", ".join(c["name"] for c in me.get("clubs", [])) or "-"
        messagebox.showinfo("VVA Analyzer", f"Połączono jako {me.get('name')}\nKlub: {clubs}", parent=root)
        saved[0] = True
        root.destroy()

    tk.Button(root, text="Zapisz i sprawdź połączenie", command=save).grid(row=6, column=0, pady=10)
    root.mainloop()
    return saved[0]


def ask_url(default: str = "") -> str | None:
    import tkinter as tk
    from tkinter import simpledialog

    root = tk.Tk()
    root.withdraw()
    url = simpledialog.askstring(
        "Kalibracja kamery",
        "Link YouTube do meczu nagranego z tego ustawienia kamery:",
        initialvalue=default, parent=root,
    )
    root.destroy()
    return url.strip() if url else None


def ask_ours_first() -> bool:
    import tkinter as tk
    from tkinter import messagebox

    root = tk.Tk()
    root.withdraw()
    root.attributes("-topmost", True)
    answer = messagebox.askyesno(
        "Kalibracja kamery",
        "Czy NASZA drużyna jest w PIERWSZYM wierszu wyniku (górnym lub lewym)?\n\n"
        "Tak = pierwszy wiersz, Nie = drugi wiersz.",
        parent=root,
    )
    root.destroy()
    return bool(answer)


def info(text: str, error: bool = False) -> None:
    import tkinter as tk
    from tkinter import messagebox

    root = tk.Tk()
    root.withdraw()
    (messagebox.showerror if error else messagebox.showinfo)("VVA Analyzer", text, parent=root)
    root.destroy()


# ----------------------------------------------------------------- app

class TrayApp:
    def __init__(self) -> None:
        self.log_path = setup_logging()
        self.cfg = AgentConfig.load()
        self.agent = Agent(self.cfg, self.changed)
        self.icon = None
        self.dialog = threading.Lock()
        self.gpu = "Karta: sprawdzam..."
        self.gpu_ok: bool | None = None  # None while checking

    def changed(self, st: AgentState) -> None:
        if self.icon is None:
            return
        self.icon.icon = icon_image(st.status)
        pct = f"{st.progress}% · " if st.status == "working" and st.progress else ""
        self.icon.title = f"VVA Analyzer: {pct}{st.message}"[:127]
        self.icon.update_menu()

    # menu actions -------------------------------------------------

    def open_site(self, *_):
        if self.cfg.server:
            webbrowser.open(self.cfg.server)

    def check_now(self, *_):
        self.agent.wake.set()

    def open_log(self, *_):
        if sys.platform == "win32":
            os.startfile(self.log_path)  # noqa: S606
        else:
            subprocess.Popen(["xdg-open", self.log_path])

    def open_config(self, *_):
        path = app_dir() / "config.json"
        if not path.exists():
            self.cfg.save()
        if sys.platform == "win32":
            subprocess.Popen(["notepad.exe", str(path)])
        else:
            subprocess.Popen(["xdg-open", str(path)])

    def settings(self, *_):
        threading.Thread(target=self._settings, daemon=True).start()

    def _settings(self):
        if not self.dialog.acquire(blocking=False):
            return
        try:
            fresh = AgentConfig.load()  # the file may have been edited in Notepad
            self.cfg.server, self.cfg.token = fresh.server or self.cfg.server, fresh.token or self.cfg.token
            if settings_dialog(self.cfg):
                self.agent.wake.set()
        finally:
            self.dialog.release()

    def calibrate(self, *_):
        threading.Thread(target=self._calibrate, daemon=True).start()

    def _calibrate(self):
        from . import calibrate, video as vid
        from .config import Calibration

        if not self.dialog.acquire(blocking=False):
            return
        try:
            url = ask_url()
            if not url:
                return
            with self.agent.busy:  # not while a match is being analysed
                self.agent._set("working", "Kalibracja: pobieranie nagrania")
                path = vid.download(url, self.cfg.work_path,
                                    on_progress=lambda p: self.agent._set("working", f"Kalibracja: pobieranie {p}%"))
                cal_path = self.cfg.calibration_path
                existing = Calibration.load(cal_path) if cal_path.exists() else None
                _, _, _, duration = vid.info(path)
                cal = calibrate.run(vid.frame_at(path, min(600.0, (duration or 1200) / 2)), existing,
                                    ours_first=ask_ours_first)
                cal.save(cal_path)
            if cal.has_scoreboard:
                info("Kalibracja zapisana. Agent zaczyna pobierać mecze do analizy.")
            else:
                info("Kalibracja zapisana, ale bez pól wyniku. Zrób ją jeszcze raz i zaznacz oba prostokąty z wynikiem.", error=True)
        except Exception as e:
            log.exception("calibration failed")
            info(f"Kalibracja nie powiodła się:\n{e}", error=True)
        finally:
            self.dialog.release()
            self.agent.wake.set()

    def check_gpu(self) -> None:
        try:
            import torch

            self.gpu_ok = torch.cuda.is_available()
            if self.gpu_ok:
                self.gpu = f"Karta: {torch.cuda.get_device_name(0)}"
            elif torch.version.cuda:
                self.gpu = "Karta: CUDA zainstalowana, brak sterownika NVIDIA"
            else:
                self.gpu = "Karta: nie używana (PyTorch na procesor)"
        except Exception as e:
            self.gpu_ok = False
            self.gpu = f"Karta: błąd PyTorch ({e.__class__.__name__})"
        log.info(self.gpu)
        if self.icon:
            self.icon.update_menu()

    def reinstall_gpu(self, *_):
        """Re-run the installer script with the CUDA version of PyTorch, then start again."""
        from pathlib import Path

        app = Path(sys.executable).resolve().parents[2]  # ...\VVA Analyzer\venv\Scripts\pythonw.exe
        script = app / "install.ps1"
        if not script.exists():
            threading.Thread(target=info, args=(f"Nie znaleziono {script}. Uruchom instalator ponownie.", True),
                             daemon=True).start()
            return
        subprocess.Popen(
            ["powershell.exe", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", str(script),
             "-AppDir", str(app), "-Gpu", "-StartTray"],
            creationflags=getattr(subprocess, "CREATE_NEW_CONSOLE", 0),
        )
        self.quit()

    def quit(self, *_):
        self.agent.stop.set()
        self.agent.wake.set()
        if self.icon:
            self.icon.stop()

    # ---------------------------------------------------------------

    def run(self) -> None:
        import pystray

        item = pystray.MenuItem
        menu = pystray.Menu(
            item(lambda _: (f"{self.agent.state.progress}% · " if self.agent.state.status == "working" else "")
                 + self.agent.state.message[:80], None, enabled=False),
            item(lambda _: self.gpu[:80], None, enabled=False),
            pystray.Menu.SEPARATOR,
            item("Otwórz VVA", self.open_site, default=True),
            item("Sprawdź teraz", self.check_now),
            item("Kalibruj kamerę…", self.calibrate),
            item("Ustawienia…", self.settings),
            item("Pokaż log", self.open_log),
            item("Otwórz plik ustawień", self.open_config),
            item("Przeinstaluj z GPU (NVIDIA)", self.reinstall_gpu, visible=lambda _: self.gpu_ok is False),
            pystray.Menu.SEPARATOR,
            item("Zakończ", self.quit),
        )
        self.icon = pystray.Icon("vva-analyzer", icon_image("idle"), "VVA Analyzer", menu)
        threading.Thread(target=self.agent.run_forever, daemon=True).start()
        threading.Thread(target=self.check_gpu, daemon=True).start()
        if not self.cfg.ready:
            self.settings()
        self.icon.run()


def main() -> None:
    # pythonw has no console: libraries that write to stdout must not crash
    if sys.stdout is None or sys.stderr is None:
        sink = open(app_dir() / "console.log", "a", encoding="utf-8", buffering=1)  # noqa: SIM115
        sys.stdout = sys.stdout or sink
        sys.stderr = sys.stderr or sink
    os.chdir(app_dir())  # model weights (yolo11s.pt) and caches live next to the settings
    TrayApp().run()
