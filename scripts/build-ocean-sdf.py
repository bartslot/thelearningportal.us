#!/usr/bin/env python3
"""
build-ocean-sdf.py — bake the field that tells the ocean shader where the sea is.

WHY THIS EXISTS. A MapLibre custom layer cannot sample the raster tiles under it, so the water
shader has to carry its own idea of the coastline. The first attempt was a 1024x512 binary mask
lifted from Blue Marble's blue-dominance: 39 km per texel, which past globe zoom magnifies into
visible squares along every shore. Raising the resolution alone does not fix that — it only makes
the squares smaller, and the file bigger — because the failure is the ENCODING, not the sampling
rate. A binary mask holds one bit per texel, so the only thing bilinear filtering can do between
two texels is ramp from land to sea across the whole texel.

A SIGNED DISTANCE FIELD holds, in each texel, how far that texel is from the coast, negative
inland. That is a smooth function, so linear interpolation between two texels reconstructs the
coastline as a straight line THROUGH the texel rather than a step at its edge. The edge stays
sharp at any magnification; what a low resolution costs is the shape of the coast, not the
crispness of it. This is the same trick that keeps SDF text crisp when scaled up.

WHAT ELSE IT BUYS. A distance field is not only a better mask — it is bathymetry's cheap stand-in.
Water within ~100 km of land is shallow and the sea floor scatters light back, which is why real
coasts read turquoise from orbit. That gradient falls straight out of the field, and it is worth
having for a second reason: Blue Marble's relief shading treats the coast as a cliff and casts a
SHADOW onto the water, so its imagery runs darker at the shore than in open ocean (measured: 4.8
luma against 8.9) — exactly backwards. The shelf term is what puts that right.

ENCODING. One 8-bit channel, distance linear over a narrow band:

    stored = 0.5 + 0.5 * clamp(d, -RANGE_KM, RANGE_KM) / RANGE_KM

Two things were tried before this one and both are worth recording, because both look better on
paper. A SQUARE-ROOT coding spends most of its levels near d = 0 and reads as obviously superior —
millimetre precision at the shore. It is worse: bilinear filtering interpolates the STORED value,
and a curved coding makes the reconstructed zero crossing land in the wrong place whenever the
coast does not pass exactly midway between two texels. Measured, that is up to 730 m of coastline
displacement against linear's zero. LOSSY compression is the other one. WebP at q94 is visually
lossless on anything meant for eyes, but a distance field is not for eyes: a two-level error is a
kilometre of coastline, and it measured at 1.05 km mean, 4.1 km at the 99th percentile — several
screen pixels of wobble along every shore. Lossless costs the same bytes here as q98 does, because
the field saturates a short way inland and out to sea and those flat regions crush to nothing.

RANGE_KM is what the file size actually turns on, since only the unsaturated band near the coast
carries any entropy: 16 km costs 591 KB, 32 km costs 1154 KB, 128 km costs 2135 KB. 32 km is
chosen to match the band of water that is genuinely shallow enough to read turquoise from orbit.

SOURCE. Natural Earth 50m land, plus 10m lakes so the Great Lakes and the Caspian glint like the
water they are. 50m carries roughly a vertex every 7 km, which is what actually limits the shape:
past about 8192 texels wide the raster is finer than the polygon it came from, and the extra
pixels record nothing. Swapping in ne_10m_land is a one-line change to LAND_SOURCE.

Run:  python3 scripts/build-ocean-sdf.py
"""

from __future__ import annotations

import json
import math
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw
from scipy import ndimage

REPO = Path(__file__).resolve().parent.parent
# Natural Earth lives outside the worktree, in the main checkout's storage directory.
NE = REPO / 'storage' / 'app' / 'naturalearth'
if not NE.is_dir():
    NE = Path(str(REPO).split('/.claude/worktrees/')[0]) / 'storage' / 'app' / 'naturalearth'

LAND_SOURCE = NE / 'ne_50m_land.geojson'
LAKE_SOURCE = NE / 'ne_10m_lakes.geojson'

OUT_DIR = REPO / 'public' / 'timemap'
MANIFEST = REPO / 'resources' / 'js' / 'timemap' / 'ocean-sdf-manifest.js'

EARTH_RADIUS_KM = 6371.0088

# How far from the coast the field still says something; beyond it, only "open ocean" or "inland".
# This is the width of the shallow-water band the shader paints, and it is also the only real
# driver of file size — see the note above. One 8-bit level is 251 m at this range.
RANGE_KM = 32.0

# Two bakes. 8192 is the one that matches the source data's own detail; 4096 exists because
# MAX_TEXTURE_SIZE is still 4096 on low-end Android, where the large one would fail to upload and
# leave the sea unpainted with no error to explain it.
WIDTHS = (8192, 4096)

# A lake has to cover a few texels before it is a lake rather than a dot. Below this the rasteriser
# catches the odd pixel centre and leaves single-texel puddles inland, which the distance field then
# blooms into round blue spots.
MIN_LAKE_TEXELS = 4.0


def rings_of(geometry):
    """Every (exterior, holes) pair in a Polygon or MultiPolygon, in drawing order."""
    kind = geometry['type']
    if kind == 'Polygon':
        return [(geometry['coordinates'][0], geometry['coordinates'][1:])]
    if kind == 'MultiPolygon':
        return [(poly[0], poly[1:]) for poly in geometry['coordinates']]
    return []


def ring_area_km2(ring):
    """Shoelace area on the sphere, near enough for a size filter."""
    if len(ring) < 3:
        return 0.0
    lat0 = sum(p[1] for p in ring) / len(ring)
    scale = math.cos(math.radians(lat0))
    total = 0.0
    for i in range(len(ring)):
        x1, y1 = ring[i][0] * scale, ring[i][1]
        x2, y2 = ring[(i + 1) % len(ring)][0] * scale, ring[(i + 1) % len(ring)][1]
        total += x1 * y2 - x2 * y1
    return abs(total) * 0.5 * (111.32 ** 2)


def draw_rings(draw, rings, width, height, colour):
    """Paint rings into the equirect grid. x = 0 is 180°W, y = 0 is 90°N."""
    for ring in rings:
        pixels = [
            ((lng + 180.0) / 360.0 * width, (90.0 - lat) / 180.0 * height)
            for lng, lat in ((p[0], p[1]) for p in ring)
        ]
        if len(pixels) >= 3:
            draw.polygon(pixels, fill=colour)


def rasterise_land(width, height):
    """
    A boolean grid, True where there is land.

    Features are drawn one at a time, exterior then holes, because a hole in one feature can be
    filled again by another feature's exterior — an island in a lake in an island. Doing all the
    exteriors first and all the holes afterwards would punch those islands back out.
    """
    land = Image.new('L', (width, height), 0)
    draw = ImageDraw.Draw(land)
    features = json.loads(LAND_SOURCE.read_text())['features']
    for feature in features:
        for exterior, holes in rings_of(feature['geometry']):
            draw_rings(draw, [exterior], width, height, 255)
            draw_rings(draw, holes, width, height, 0)

    texel_km2 = (360.0 / width * 111.32) * (180.0 / height * 111.32)
    floor_km2 = MIN_LAKE_TEXELS * texel_km2
    lakes = json.loads(LAKE_SOURCE.read_text())['features']
    kept = 0
    for feature in lakes:
        for exterior, holes in rings_of(feature['geometry']):
            if ring_area_km2(exterior) < floor_km2:
                continue
            kept += 1
            draw_rings(draw, [exterior], width, height, 0)   # lake water
            draw_rings(draw, holes, width, height, 255)      # island in the lake

    print(f'  land: {len(features)} features, {kept}/{len(lakes)} lakes above {floor_km2:.0f} km²')
    return np.asarray(land, dtype=np.uint8) > 127


def true_distance_km(indices, height, width, rows_per_chunk=256):
    """
    Great-circle kilometres from each texel to the texel the transform picked as nearest.

    scipy measures in grid steps, and an equirectangular grid step is not a fixed distance: a column
    is 4.9 km apart at the equator and 2.4 km apart at 60°. Converting the grid distance with one
    scale would stretch every high-latitude shelf. Taking the nearest texel's own coordinates and
    measuring the real arc costs one pass and removes the question.

    The transform still CHOSE that neighbour in grid metric, so above about 60° it can settle on a
    texel that is nearer in grid steps than in kilometres. The result is then an overestimate,
    bounded by 1/cos(latitude), and it lands only on the width of the shelf gradient — never on
    the zero crossing, where both distances are zero by construction.
    """
    out = np.empty((height, width), dtype=np.float32)
    lat_of_row = np.deg2rad(90.0 - (np.arange(height, dtype=np.float64) + 0.5) * 180.0 / height)
    lon_of_col = np.deg2rad(-180.0 + (np.arange(width, dtype=np.float64) + 0.5) * 360.0 / width)

    for start in range(0, height, rows_per_chunk):
        stop = min(start + rows_per_chunk, height)
        near_lat = lat_of_row[indices[0][start:stop]]
        near_lon = lon_of_col[indices[1][start:stop]]
        here_lat = lat_of_row[start:stop, None]
        here_lon = lon_of_col[None, :]

        sin_dlat = np.sin((near_lat - here_lat) * 0.5)
        sin_dlon = np.sin((near_lon - here_lon) * 0.5)
        h = sin_dlat ** 2 + np.cos(here_lat) * np.cos(near_lat) * sin_dlon ** 2
        out[start:stop] = (2.0 * EARTH_RADIUS_KM * np.arcsin(np.sqrt(np.clip(h, 0.0, 1.0)))).astype(np.float32)
    return out


def signed_distance_km(land):
    """
    Kilometres to the coast, positive at sea.

    Both halves are measured the same way and then subtracted, which is what puts the zero crossing
    on the cell boundary rather than on a pixel centre. A sea texel touching land reads +1 step and
    the land texel touching it reads -1, so interpolating between the two crosses zero exactly
    halfway — on the rasterised coastline itself. Taking only the seaward distance would put the
    coast half a texel out to sea everywhere, and no amount of resolution would recover it.
    """
    height, width = land.shape

    _, sea_idx = ndimage.distance_transform_edt(~land, return_distances=True, return_indices=True)
    sea_km = true_distance_km(sea_idx, height, width)
    del sea_idx

    _, land_idx = ndimage.distance_transform_edt(land, return_distances=True, return_indices=True)
    land_km = true_distance_km(land_idx, height, width)
    del land_idx

    return sea_km - land_km


def encode(distance_km):
    """
    Signed linear coding into 8 bits. The shader's decode is the exact inverse.

    Linear specifically so that bilinear filtering reconstructs the coastline where it really is.
    Interpolation happens on the STORED value, so any curve in the coding bends the zero crossing;
    a straight one carries it through untouched, whatever the two texels either side happen to say.
    """
    unit = np.clip(distance_km, -RANGE_KM, RANGE_KM) / RANGE_KM
    return np.rint((0.5 + 0.5 * unit) * 255.0).astype(np.uint8)


def bake(width):
    height = width // 2
    print(f'{width}x{height} — {360.0 / width * 111.32:.2f} km per texel at the equator')
    land = rasterise_land(width, height)
    print(f'  sea fraction {1.0 - float(land.mean()):.4f}')
    distance = signed_distance_km(land)
    stored = encode(distance)

    path = OUT_DIR / f'ocean-sdf-{width}.webp'
    Image.fromarray(stored, mode='L').save(path, lossless=True, method=6)
    print(f'  wrote {path.relative_to(REPO)} — {path.stat().st_size / 1024:.0f} KB')

    # Lossless is a claim, so check it. A silent drift here is a coastline that has moved, and
    # nothing downstream would ever report it.
    drift = np.abs(np.asarray(Image.open(path).convert('L'), dtype=np.int16) - stored.astype(np.int16))
    if drift.max() != 0:
        sys.exit(f'  encoder was not lossless: drifted up to {drift.max()} levels')
    print(f'  round trip exact; quantisation {RANGE_KM / 255 * 1000:.0f} m against a '
          f'{360.0 / width * 111.32 * 1000:.0f} m texel')
    return {'width': width, 'height': height, 'bytes': path.stat().st_size}


def write_manifest(bakes):
    """
    The encoding, as a module both the layer and the tests import.

    Generated rather than hand-kept: RANGE_KM appears in the baker and in the shader's decode, and
    the two silently disagreeing would shift every coastline with nothing to show for it.
    """
    entries = ',\n'.join(
        f"  {{ width: {b['width']}, height: {b['height']}, "
        f"url: '/timemap/ocean-sdf-{b['width']}.webp' }}"
        for b in bakes
    )
    MANIFEST.write_text(f'''/**
 * ocean-sdf-manifest.js — GENERATED by scripts/build-ocean-sdf.py. Do not edit.
 *
 * The contract between the baked distance field and the shader that reads it. It lives in one
 * generated file because the range and the coding appear on both sides, and a silent disagreement
 * between them moves every coastline on the planet with nothing on screen to say why.
 */

/**
 * Kilometres at which the field saturates. Beyond this it only says "open ocean" or "inland", and
 * it is also the width of the shallow-water band the shader can paint.
 */
export const OCEAN_SDF_RANGE_KM = {RANGE_KM}

/** Largest first: the layer takes the widest one the GPU will accept. */
export const OCEAN_SDF_SOURCES = [
{entries},
]

/**
 * Decode, in GLSL — kilometres from the coast, negative inland.
 *
 * Linear, and it has to be. Bilinear filtering interpolates the STORED value, so a straight coding
 * carries the zero crossing through to exactly where the coastline runs between two texels, while
 * any curve bends it — measured at up to 730 m of displacement for a square-root coding.
 */
export const OCEAN_SDF_DECODE_GLSL = /* glsl */`
  float oceanDistanceKm(float stored, float rangeKm) {{
    return (stored * 2.0 - 1.0) * rangeKm;
  }}
`

/** The same decode in JS, for tests and tooling. Generated alongside so the two cannot drift. */
export const oceanDistanceKm = (stored, rangeKm = OCEAN_SDF_RANGE_KM) => (stored * 2 - 1) * rangeKm

/** The baker's encode, in JS: kilometres to a 0..1 stored value. The inverse of the above. */
export const storeOceanDistanceKm = (km, rangeKm = OCEAN_SDF_RANGE_KM) =>
  0.5 + 0.5 * Math.max(-rangeKm, Math.min(rangeKm, km)) / rangeKm
''')
    print(f'wrote {MANIFEST.relative_to(REPO)}')


def main():
    for source in (LAND_SOURCE, LAKE_SOURCE):
        if not source.is_file():
            sys.exit(f'missing {source}')
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    write_manifest([bake(width) for width in WIDTHS])


if __name__ == '__main__':
    main()
