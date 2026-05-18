#!/usr/bin/env python3
"""
Remove the Blender grey background from animation thumbnail webps.
Samples background color from the four corners, flood-fills with a
tolerance-based mask, feathers the edges, and saves RGBA transparent webp.

Only touches files under public/avatars/animation-library/*/webp/
Never touches .glb / .fbx files.

Usage:
    python3 scripts/process-animation-thumbs.py [--dry-run] [--tolerance 30] [--feather 12]
"""

import argparse
import pathlib
import sys

import numpy as np
from PIL import Image

ROOT = pathlib.Path(__file__).parent.parent / "public" / "avatars" / "animation-library"

SAMPLE_RADIUS = 4  # pixels from each corner to average background color


def sample_bg_color(arr: np.ndarray) -> np.ndarray:
    """Average the four corner patches to estimate the background color."""
    h, w = arr.shape[:2]
    r = min(SAMPLE_RADIUS, h // 4, w // 4)
    patches = [
        arr[:r, :r, :3],
        arr[:r, -r:, :3],
        arr[-r:, :r, :3],
        arr[-r:, -r:, :3],
    ]
    combined = np.concatenate([p.reshape(-1, 3) for p in patches], axis=0)
    return combined.mean(axis=0)


def color_distance(arr: np.ndarray, bg: np.ndarray) -> np.ndarray:
    """Euclidean distance from bg color in RGB space, shape (H, W)."""
    return np.sqrt(((arr[:, :, :3].astype(float) - bg) ** 2).sum(axis=2))


def make_alpha_mask(dist: np.ndarray, tolerance: float, feather: float) -> np.ndarray:
    """
    tolerance  — pixels within this distance of bg → fully transparent
    feather    — transition zone above tolerance → partial alpha
    Returns float mask 0.0 (transparent) … 1.0 (opaque)
    """
    mask = np.ones(dist.shape, dtype=float)
    # fully transparent
    mask[dist <= tolerance] = 0.0
    # feather zone
    zone = (dist > tolerance) & (dist <= tolerance + feather)
    mask[zone] = (dist[zone] - tolerance) / feather
    return mask


def process_file(path: pathlib.Path, tolerance: float, feather: float, dry_run: bool) -> bool:
    img = Image.open(path).convert("RGBA")
    arr = np.array(img)

    bg = sample_bg_color(arr)
    dist = color_distance(arr, bg)
    alpha_mask = make_alpha_mask(dist, tolerance, feather)

    # Blend existing alpha with new mask
    new_alpha = (alpha_mask * arr[:, :, 3]).astype(np.uint8)

    result = arr.copy()
    result[:, :, 3] = new_alpha

    if dry_run:
        # Report % transparent pixels
        transparent_pct = (new_alpha == 0).sum() / new_alpha.size * 100
        bg_hex = "#{:02x}{:02x}{:02x}".format(int(bg[0]), int(bg[1]), int(bg[2]))
        print(f"  DRY {path.name:50s}  bg={bg_hex}  {transparent_pct:.0f}% transparent")
        return True

    out = Image.fromarray(result, "RGBA")
    out.save(path, "WEBP", lossless=True)
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run", action="store_true", help="Print stats, do not write files")
    parser.add_argument("--tolerance", type=float, default=30.0, help="Color distance threshold (default 30)")
    parser.add_argument("--feather", type=float, default=15.0, help="Feather zone width (default 15)")
    args = parser.parse_args()

    if not ROOT.is_dir():
        print(f"ERROR: directory not found: {ROOT}", file=sys.stderr)
        sys.exit(1)

    webp_files = sorted(ROOT.rglob("*.webp"))
    if not webp_files:
        print("No webp files found.", file=sys.stderr)
        sys.exit(1)

    print(f"Processing {len(webp_files)} webp files  (tolerance={args.tolerance}, feather={args.feather})")
    if args.dry_run:
        print("DRY RUN — no files will be written\n")

    ok = fail = 0
    for p in webp_files:
        try:
            process_file(p, args.tolerance, args.feather, args.dry_run)
            ok += 1
            if not args.dry_run:
                print(f"  OK  {p.relative_to(ROOT)}")
        except Exception as exc:
            print(f"  ERR {p.relative_to(ROOT)}: {exc}", file=sys.stderr)
            fail += 1

    print(f"\nDone — {ok} processed, {fail} errors.")


if __name__ == "__main__":
    main()
