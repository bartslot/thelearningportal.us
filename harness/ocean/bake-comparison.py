#!/usr/bin/env python3
"""
bake-comparison.py — the baselines the distance field has to beat, from the same source data.

The claim being tested is that the ENCODING was the problem, not the resolution. Testing that needs
both variables separated, so this writes binary masks — 0 for land, 255 for sea, the shape the
parked implementation used — at the parked resolution and at the new one:

    mask-1024.webp   what the parked layer had: a mask, and a coarse one
    mask-8192.webp   a mask at the new resolution: resolution fixed, encoding not

Against public/timemap/ocean-sdf-8192.webp that gives three points, and the difference between the
second and third is the encoding on its own.

No shader changes are needed to read these. A binary mask decoded as a distance field yields -32 km
or +32 km and nothing between, so the shoreline smoothstep collapses to a hard threshold at the
midpoint between two texels — which is precisely how a bilinear-filtered mask behaves, and
precisely the staircase this is meant to expose.

Harness-only. Nothing here ships.

Run:  python3 harness/ocean/bake-comparison.py
"""

import importlib.util
from pathlib import Path

import numpy as np
from PIL import Image

HERE = Path(__file__).resolve().parent
OUT = HERE / '.build'

spec = importlib.util.spec_from_file_location('baker', HERE.parent.parent / 'scripts' / 'build-ocean-sdf.py')
baker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(baker)


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for width in (1024, 8192):
        land = baker.rasterise_land(width, width // 2)
        mask = np.where(land, 0, 255).astype(np.uint8)
        path = OUT / f'mask-{width}.webp'
        Image.fromarray(mask, mode='L').save(path, lossless=True, method=6)
        print(f'{path.name}: {path.stat().st_size / 1024:.0f} KB, '
              f'{360.0 / width * 111.32:.1f} km per texel')


if __name__ == '__main__':
    main()
