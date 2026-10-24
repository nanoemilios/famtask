#!/usr/bin/env python3
"""Erzeugt das komplette FamTask-Icon-Set (Favicon, PWA, iOS).

Quelle der Wahrheit ist die Geometrie in diesem Skript. Die SVG-Dateien werden
in einem Temp-Ordner geschrieben und mit Chrome headless gerendert; daraus
entstehen alle PNG-Größen sowie das ICO.

Aufrufe:
    python3 tools/build_icons.py                     # komplettes Set nach ./icons
    python3 tools/build_icons.py --target ../famtask_landingpage/icons --favicon-only

Voraussetzung: Chrome headless shell unter
~/.local/opt/chrome-headless-shell/chrome-headless-shell (oder CHROME setzen)
und Pillow.
"""

from __future__ import annotations

import argparse
import os
import subprocess
import tempfile
from pathlib import Path

from PIL import Image

# ── Markenfarben ────────────────────────────────────────────────────────────
DARK = "#0D0E1A"
GRAD_FROM = "#FF6B6B"
GRAD_TO = "#FFE66D"

# ── Geometrie (32×32-Raster) ────────────────────────────────────────────────
# F: Stamm + oberer Querbalken + Mittelbalken
STEM_X, STEM_Y, STEM_W, STEM_H, STEM_R = 7.8, 7.2, 4.9, 17.0, 2.45
TOP_W = 11.4
MID_Y, MID_W, MID_H, MID_R = 14.0, 7.4, 4.5, 2.25
# Haken: bewusst nicht mit dem F verbunden
CHECK_D = "M17.8 20.6l2.8 2.8 5.6-6.7"
CHECK_W = 4.1

CHROME = os.environ.get(
    "CHROME",
    str(Path.home() / ".local/opt/chrome-headless-shell/chrome-headless-shell"),
)


def mark(fill: str) -> str:
    """F + Haken als SVG-Gruppe im 32er-Raster."""
    return f"""  <g fill="{fill}">
    <rect x="{STEM_X}" y="{STEM_Y}" width="{STEM_W}" height="{STEM_H}" rx="{STEM_R}"/>
    <rect x="{STEM_X}" y="{STEM_Y}" width="{TOP_W}" height="{STEM_W}" rx="{STEM_R}"/>
    <rect x="{STEM_X}" y="{MID_Y}" width="{MID_W}" height="{MID_H}" rx="{MID_R}"/>
  </g>
  <path d="{CHECK_D}" fill="none" stroke="{fill}" stroke-width="{CHECK_W}"
        stroke-linecap="round" stroke-linejoin="round"/>"""


def svg_icon(rounded: bool, scale: float = 1.0) -> str:
    """Gradient-Kachel mit Marke; rounded=False für maskable / apple-touch."""
    rx = ' rx="7"' if rounded else ""
    transform = (
        f' transform="translate(16 16) scale({scale}) translate(-16 -16)"'
        if scale != 1.0
        else ""
    )
    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="512" height="512" role="img" aria-label="FamTask">
  <title>FamTask</title>
  <defs>
    <linearGradient id="ftGrad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="{GRAD_FROM}"/>
      <stop offset="1" stop-color="{GRAD_TO}"/>
    </linearGradient>
  </defs>
  <rect width="32" height="32"{rx} fill="url(#ftGrad)"/>
  <g{transform}>
{mark(DARK)}
  </g>
</svg>
"""


def svg_pinned_tab() -> str:
    """Einfarbige Silhouette für Safari-Pinned-Tabs (Farbe via link-Attribut)."""
    return f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">
  <title>FamTask</title>
{mark("#000000")}
</svg>
"""


def render(svg_path: Path, png_path: Path, size: int) -> None:
    subprocess.run(
        [
            CHROME,
            "--headless",
            "--disable-gpu",
            "--no-sandbox",
            "--hide-scrollbars",
            "--force-device-scale-factor=1",
            "--default-background-color=00000000",
            f"--window-size={size},{size}",
            f"--screenshot={png_path}",
            f"file://{svg_path}",
        ],
        check=True,
        capture_output=True,
    )


def downscale(master: Image.Image, size: int) -> Image.Image:
    return master.resize((size, size), Image.LANCZOS)


def main() -> int:
    parser = argparse.ArgumentParser(description="FamTask-Icon-Set erzeugen")
    parser.add_argument(
        "--target", default="icons", help="Zielordner (Standard: ./icons)"
    )
    parser.add_argument(
        "--favicon-only",
        action="store_true",
        help="nur Favicon/iOS-Dateien, ohne PWA-Icons (z. B. Landingpage)",
    )
    args = parser.parse_args()

    target = Path(args.target).resolve()
    target.mkdir(parents=True, exist_ok=True)

    sources = {
        "icon.svg": svg_icon(rounded=True),
        "icon-maskable.svg": svg_icon(rounded=False, scale=0.78),
        "icon-ios.svg": svg_icon(rounded=False, scale=0.92),
        "safari-pinned-tab.svg": svg_pinned_tab(),
    }

    with tempfile.TemporaryDirectory() as tmpdir:
        tmp = Path(tmpdir)
        for name, content in sources.items():
            (tmp / name).write_text(content, encoding="utf-8")

        # 512er Master je Variante rendern, danach sauber herunterskalieren.
        masters: dict[str, Image.Image] = {}
        for key in ("icon", "icon-maskable", "icon-ios"):
            png = tmp / f"{key}-512.png"
            render(tmp / f"{key}.svg", png, 512)
            masters[key] = Image.open(png).convert("RGBA")

        png_sizes = {
            "favicon-16x16.png": ("icon", 16),
            "favicon-32x32.png": ("icon", 32),
            "favicon-48x48.png": ("icon", 48),
            "apple-touch-icon.png": ("icon-ios", 180),
        }
        if not args.favicon_only:
            png_sizes.update(
                {
                    "icon-192.png": ("icon", 192),
                    "icon-512.png": ("icon", 512),
                    "icon-maskable-192.png": ("icon-maskable", 192),
                    "icon-maskable-512.png": ("icon-maskable", 512),
                }
            )

        for name, (key, size) in png_sizes.items():
            downscale(masters[key], size).save(target / name, optimize=True)

        # ICO mit mehreren Größen aus dem 256er Master
        downscale(masters["icon"], 256).save(
            target / "favicon.ico",
            format="ICO",
            sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64)],
        )

        # SVG-Quellen, die im Web gebraucht werden
        (target / "favicon.svg").write_text(sources["icon.svg"], encoding="utf-8")
        (target / "safari-pinned-tab.svg").write_text(
            sources["safari-pinned-tab.svg"], encoding="utf-8"
        )

    for f in sorted(target.iterdir()):
        print(f"{f.stat().st_size:>8}  {f.name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
