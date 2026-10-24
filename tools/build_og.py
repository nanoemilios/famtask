#!/usr/bin/env python3
"""Erzeugt das Share-Bild (Open Graph / Twitter Card) 1200x630.

Marke, Schrift und Farben kommen aus denselben Quellen wie die Icons
(``build_icons.py``) und die selbst gehosteten Fonts. Gerendert wird mit
Chrome headless, damit Wortmarke und Icon exakt wie in der App aussehen.

Aufrufe:
    python3 tools/build_og.py
    python3 tools/build_og.py --target ../famtask_landingpage/icons

Voraussetzung: Chrome headless shell unter
~/.local/opt/chrome-headless-shell/chrome-headless-shell (oder CHROME setzen).
"""

from __future__ import annotations

import argparse
import base64
import os
import subprocess
import tempfile
from pathlib import Path

from build_icons import CHROME, DARK, GRAD_FROM, GRAD_TO, mark

WIDTH, HEIGHT = 1200, 630
ROOT = Path(__file__).resolve().parent.parent
FONTS = ROOT / "vendor" / "fonts"


def _b64(path: Path) -> str:
    return base64.b64encode(path.read_bytes()).decode("ascii")


def _font_face(family: str, weight: int, path: Path) -> str:
    return (
        "@font-face{"
        f"font-family:'{family}';font-style:normal;font-weight:{weight};"
        f"src:url(data:font/woff2;base64,{_b64(path)}) format('woff2');"
        "}"
    )


def build_html() -> str:
    icon = f"""<svg viewBox="0 0 32 32" width="200" height="200" aria-hidden="true">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{GRAD_FROM}"/>
      <stop offset="1" stop-color="{GRAD_TO}"/>
    </linearGradient>
  </defs>
  <rect width="32" height="32" rx="7" fill="url(#g)"/>
  <g transform="translate(0 0)">
{mark(DARK).replace(chr(10), chr(10) + "  ")}
  </g>
</svg>"""
    fonts = "\n".join(
        [
            _font_face("Fredoka One", 400, FONTS / "fredoka-one-400.woff2"),
            _font_face("Nunito", 700, FONTS / "nunito-latin.woff2"),
            _font_face("Nunito", 900, FONTS / "nunito-latin.woff2"),
        ]
    )
    return f"""<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<style>
{fonts}
*{{box-sizing:border-box;margin:0;padding:0}}
html,body{{width:{WIDTH}px;height:{HEIGHT}px;overflow:hidden}}
body{{
  background:{DARK};
  font-family:'Nunito',sans-serif;color:#fff;
  display:flex;align-items:center;
  padding:0 96px;gap:64px;
  position:relative;
}}
body::before{{
  content:"";position:absolute;inset:0;
  background:
    radial-gradient(560px 420px at 12% 0%, rgba(255,107,107,0.22), transparent 70%),
    radial-gradient(620px 460px at 96% 108%, rgba(255,230,109,0.16), transparent 70%);
}}
.mark{{position:relative;flex:0 0 auto;filter:drop-shadow(0 18px 44px rgba(255,107,107,0.28))}}
.copy{{position:relative;display:flex;flex-direction:column;gap:14px}}
.wordmark{{
  font-family:'Fredoka One',sans-serif;font-size:104px;line-height:1;
  background:linear-gradient(135deg,{GRAD_FROM},{GRAD_TO});
  -webkit-background-clip:text;background-clip:text;
  -webkit-text-fill-color:transparent;color:transparent;
}}
.tagline{{font-size:31px;line-height:1.35;color:rgba(255,255,255,0.78);font-weight:700;max-width:600px}}
.pills{{display:flex;gap:10px;margin-top:14px}}
.pill{{
  font-size:18px;font-weight:900;letter-spacing:.2px;
  padding:8px 16px;border-radius:999px;
  background:rgba(255,255,255,0.07);
  border:1.5px solid rgba(255,255,255,0.14);
  color:rgba(255,255,255,0.85);
}}
</style>
</head>
<body>
  <div class="mark">{icon}</div>
  <div class="copy">
    <div class="wordmark">FamTask</div>
    <div class="tagline">Familienaufgaben, Medienzeit und Kalender &ndash; spielend einfach verwaltet.</div>
    <div class="pills">
      <span class="pill">Aufgaben</span>
      <span class="pill">Lernspiele</span>
      <span class="pill">Medienzeit</span>
      <span class="pill">Kalender</span>
    </div>
  </div>
</body>
</html>
"""


def render(target_dir: Path) -> Path:
    target_dir.mkdir(parents=True, exist_ok=True)
    out = target_dir / "og-image.png"
    with tempfile.TemporaryDirectory(prefix="famtask-og-") as tmp:
        html = Path(tmp) / "og.html"
        html.write_text(build_html(), encoding="utf-8")
        subprocess.run(
            [
                CHROME,
                "--headless",
                "--disable-gpu",
                "--no-sandbox",
                "--hide-scrollbars",
                "--force-device-scale-factor=1",
                f"--window-size={WIDTH},{HEIGHT}",
                f"--screenshot={out}",
                html.as_uri(),
            ],
            check=True,
            capture_output=True,
        )
    return out


def main() -> None:
    parser = argparse.ArgumentParser(description="FamTask Share-Bild erzeugen")
    parser.add_argument(
        "--target",
        default=str(ROOT / "icons"),
        help="Zielordner (Standard: ./icons)",
    )
    args = parser.parse_args()
    out = render(Path(args.target))
    print(f"{out} ({out.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
