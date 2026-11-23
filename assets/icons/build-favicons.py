#!/usr/bin/env python3
"""
Build favicon kit for the newpixel theme.

Generates:
  favicon.svg          TRANSPARENT background, just the purple dot. One
                       file serves both light and dark browser tabs —
                       no @media, no JS, no per-theme variants needed.
  favicon.ico          16+32+48 multi-res, embedded PNGs (Vista+ format)
  favicon-16/32/48.png standalone PNG fallbacks
  apple-touch-icon.png 180x180, opaque LIGHT tile (iOS rejects transparent
                       home icons — shows them on black; we ship a tile)
  icon-192.png         192x192, opaque light tile (Android)
  icon-512.png         512x512, opaque light tile (Android, PWA splash)
  manifest.json        PWA manifest

Design (chosen with the user):
  - Purple dot (#a78bfa) on transparent background for browser tabs.
    The purple is readable on any tab background (white in light mode,
    dark gray in dark mode), so a single file works everywhere — same
    approach Apple, GitHub, Stripe use for their favicons.
  - Dot is r=22 on a 64x64 viewBox (was r=18) — bigger visual presence
    while keeping a small transparent margin.
  - PNG/ICO outputs still use an opaque LIGHT tile because the home
    screen launchers (iOS, Android, Windows pinned sites) don't support
    transparency — a transparent area there shows up as ugly black.

Rasterization uses qlmanage (macOS native, WebKit-backed Quick Look).
ICO assembled by a tiny pure-Python writer with embedded PNG entries.
"""

import struct
import subprocess
import sys
from pathlib import Path

PURPLE   = "#a78bfa"
LIGHT_BG = "#fafaf7"  # used for the opaque PNG/ICO tile only
DOT_R    = 22         # circle radius on a 64x64 viewBox (was 18)

SITE_ROOT = Path.home() / "Local Sites" / "pixelmagazine" / "app" / "public"
ASSETS    = SITE_ROOT / "wp-content" / "themes" / "newpixel" / "assets" / "icons"
TMP       = Path("/tmp/fav-build")

# Files that get mirrored to the site root for legacy clients that hit
# them via the well-known root paths (/favicon.ico, /apple-touch-icon.png).
ROOT_MIRROR = ("favicon.ico", "apple-touch-icon.png")

# Single transparent SVG for browser tabs. No background rect — the
# browser tab's own colour shows through, so this one file works on both
# light and dark tabs without any media query or JS swap.
RESPONSIVE_SVG = (
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
    f'<circle cx="32" cy="32" r="{DOT_R}" fill="{PURPLE}"/>'
    f'</svg>\n'
)

# Opaque LIGHT-tile SVG used only as the raster source for the PNG/ICO
# outputs. Home-screen launchers don't render transparency well, so we
# bake in a light tile and let the launcher round/mask as it sees fit.
STATIC_SVG_LIGHT = (
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
    f'<rect width="64" height="64" fill="{LIGHT_BG}"/>'
    f'<circle cx="32" cy="32" r="{DOT_R}" fill="{PURPLE}"/>'
    f'</svg>\n'
)

MANIFEST_JSON = """{
  "name": "Pixel Magazine",
  "short_name": "Pixel",
  "icons": [
    {
      "src": "icon-192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "icon-512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ],
  "theme_color": "#0a0a0a",
  "background_color": "#fafaf7",
  "display": "standalone",
  "start_url": "/"
}
"""


def rasterize(svg_path: Path, size: int, out_path: Path) -> None:
    """
    qlmanage -t -s <size> -o <dir> <file>  ->  <dir>/<file>.png

    qlmanage writes the PNG at the requested longest-edge size; for our
    square viewBox the output is size x size.
    """
    TMP.mkdir(parents=True, exist_ok=True)
    result = subprocess.run(
        ["qlmanage", "-t", "-s", str(size), "-o", str(TMP), str(svg_path)],
        capture_output=True, text=True,
    )
    src = TMP / f"{svg_path.name}.png"
    if not src.exists():
        raise RuntimeError(
            f"qlmanage failed for {svg_path} @ {size}: "
            f"stdout={result.stdout!r} stderr={result.stderr!r}"
        )
    # Some qlmanage builds emit a slightly different size (e.g. 181 instead
    # of 180) for SVG inputs. Normalize via sips.
    subprocess.run(
        ["sips", "-z", str(size), str(size), str(src), "--out", str(out_path)],
        capture_output=True, check=True,
    )
    src.unlink()


def make_ico(png_paths: list[Path], out: Path) -> None:
    """
    Assemble a Vista+ ICO with embedded PNG payloads.

    Format:
      6-byte ICONDIR header
      16-byte ICONDIRENTRY per image
      raw PNG bytes per image (Vista+ accepts PNG directly inside ICO)
    """
    entries = []
    for p in png_paths:
        # File names look like favicon-32.png -> 32.
        size = int(p.stem.rsplit("-", 1)[1])
        data = p.read_bytes()
        entries.append((size, data))

    num = len(entries)
    header = struct.pack("<HHH", 0, 1, num)  # reserved, type=1 (ico), count
    directory = b""
    offset = 6 + 16 * num
    for size, data in entries:
        # In ICONDIRENTRY width/height fields, 0 means 256.
        w = h = size if size < 256 else 0
        directory += struct.pack(
            "<BBBBHHII",
            w, h,
            0,            # color palette
            0,            # reserved
            1,            # color planes
            32,           # bits per pixel
            len(data),    # bytes in this image
            offset,       # offset to image data from start of file
        )
        offset += len(data)

    out.write_bytes(header + directory + b"".join(d for _, d in entries))


def main() -> int:
    ASSETS.mkdir(parents=True, exist_ok=True)
    TMP.mkdir(parents=True, exist_ok=True)

    # 1. Single transparent SVG for browser tabs.
    (ASSETS / "favicon.svg").write_text(RESPONSIVE_SVG)
    print(f"wrote {ASSETS / 'favicon.svg'}")

    # 1b. Clean up obsolete per-theme variants from earlier iterations.
    for stale in ("favicon-light.svg", "favicon-dark.svg"):
        p = ASSETS / stale
        if p.exists():
            p.unlink()
            print(f"removed (obsolete) {p}")

    # 2. Two raster sources:
    #    - light tile: only for the home-screen launchers (iOS/Android/PWA)
    #      that don't render transparency well
    #    - transparent: for the favicon-16/32/48 PNGs and the ICO that
    #      browsers might fetch from /favicon.ico legacy path
    static_light_path = TMP / "light.svg"
    static_light_path.write_text(STATIC_SVG_LIGHT)
    transparent_path  = TMP / "transparent.svg"
    transparent_path.write_text(RESPONSIVE_SVG)

    # 3a. Tab PNGs + ICO inputs — rasterize from TRANSPARENT source.
    transparent_targets = {
        "favicon-16.png": 16,
        "favicon-32.png": 32,
        "favicon-48.png": 48,
    }
    for name, size in transparent_targets.items():
        out = ASSETS / name
        rasterize(transparent_path, size, out)
        print(f"wrote {out} ({size}x{size}) [transparent]")

    # 3b. Home-screen launcher PNGs — rasterize from LIGHT TILE source.
    opaque_targets = {
        "apple-touch-icon.png": 180,
        "icon-192.png":         192,
        "icon-512.png":         512,
    }
    for name, size in opaque_targets.items():
        out = ASSETS / name
        rasterize(static_light_path, size, out)
        print(f"wrote {out} ({size}x{size}) [opaque light]")

    # 4. Bundle ICO from the small (transparent) PNG set.
    ico_sources = [ASSETS / "favicon-16.png", ASSETS / "favicon-32.png", ASSETS / "favicon-48.png"]
    make_ico(ico_sources, ASSETS / "favicon.ico")
    print(f"wrote {ASSETS / 'favicon.ico'} [transparent]")

    # 4b. Mirror the legacy well-known paths to the site root so RSS
    # readers, crawlers and old user agents that hit /favicon.ico or
    # /apple-touch-icon.png get a real file from nginx (no PHP roundtrip).
    for fname in ROOT_MIRROR:
        src = ASSETS / fname
        dst = SITE_ROOT / fname
        dst.write_bytes(src.read_bytes())
        print(f"mirrored {fname} -> {dst}")

    # 5. PWA manifest.
    (ASSETS / "manifest.json").write_text(MANIFEST_JSON)
    print(f"wrote {ASSETS / 'manifest.json'}")

    print("\nDone. Files in:")
    print(f"  {ASSETS}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
