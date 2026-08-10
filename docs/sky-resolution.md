# How big should the sky panorama be?

The answer for the Time-Map sky is **2048 x 1024, 746 KB**, and the interesting part is that it is
so much smaller than the surrounding assets. This is the working, because the method generalises to
any large baked texture even though the number does not.

Everything below was measured on 10 August 2026 against `milkyway_2020_*.webp`, built by
`scripts/build-sky-assets.mjs` from NASA SVS Deep Star Maps 2020.

## The four numbers

| | |
|---|---|
| `MAX_TEXTURE_SIZE`, this machine | **16384** (M3 Pro, ANGLE Metal) |
| `MAX_TEXTURE_SIZE`, low-end profile | **not measured — no such device here** |
| Resolution at which visible improvement stops | **~2048**, and it is already below the visible threshold there |
| Transfer bytes | 1k 44 KB · 2k 746 KB · 4k 3.6 MB · 8k 20.2 MB |
| Decode, M3 Pro | 1k 5 ms · 2k 51 ms · 4k 235 ms · **8k 1210 ms** |

The second row is a real gap and it should not be papered over. Everything here was measured on a
fast Mac. A low-end Chromebook is commonly 3-5x slower to decode, which puts 8k at four to six
seconds of blocked main thread, and its `MAX_TEXTURE_SIZE` is commonly 4096 or 8192 — above which
a texture does not soften, it **fails to bind at all and the sky is black with no error**. Someone
with the hardware should run the check in `dev/sky-lab.html`.

## Why a sky is not like the ground

Every other baked texture on this map is sampled harder as the camera comes in, so its ceiling is
set by the closest the surface can get. This one is at infinity. Its angular sampling is fixed by
the field of view and the height of the canvas:

    0.023 degrees per screen pixel
    (MapLibre's default 36.87 degree vertical field of view, 800 px pane, device pixel ratio 2)

Map zoom does not enter into it. **The ceiling is set once and never moves**, which is why "measure
it at the surface's maximum zoom" gives a constant here rather than a curve.

For reference, one texel of the panorama spans:

| panorama | degrees per texel | magnification at the screen |
|---|---|---|
| 1k | 0.352 | 15x |
| 2k | 0.176 | 7.6x |
| 4k | 0.088 | 3.8x |
| 8k | 0.044 | 1.9x |
| 16k | 0.022 | 1:1 |

By that table alone you would reach for 16k. The table is the wrong instrument: it describes the
sampling grid, not whether there is anything on the grid worth sampling.

## Where the improvement actually stops

Render the identical frame through each panorama, with the stars and the procedural field switched
off so only the texture varies, and difference the sky pixels. Root-mean-square error, on 0-255:

    against the 8k reference          consecutive steps
    1k   2.04                         1k -> 2k   0.97
    2k   1.71                         2k -> 4k   0.90
    4k   1.34                         4k -> 8k   1.34

    for scale: no panorama at all -> 8k   =   13.83

One to two levels out of 255 is roughly the threshold for seeing a difference in a smooth gradient.
Every resolution is already at or under it, and the whole ladder from 1k to 8k is worth about 15% of
what having a panorama at all is worth.

The decisive detail is that **the steps do not shrink**. Real detail converges: each doubling should
recover less than the last. These do not — 4k to 8k is the largest step of the three. That is the
signature of each resolution carrying a different realisation of the same noise, which is what the
source says too: halve the 8192 source, put it back, and the residual's lag-1 autocorrelation is
0.08 across and -0.16 down through the galactic plane. Uncorrelated speckle, not structure.

Restricting the comparison to the brightest tenth of the sky — the galactic bulge and the dust lanes
through it, the only place structure could hide — moves nothing: 1k is 2.71 from the reference and
2k is 2.00.

Looking, as well as measuring: side by side at the bulge, the 8192 is visibly **grainier** than the
2048, with the same dust lanes in the same places. It is not more detail. It is the speckle the
downscale averaged away.

## Why this asset can afford to be small

Two properties, both specific to a sky, and worth checking for before reusing the number:

1. **The detail is elsewhere.** The bright stars are drawn from the Yale Bright Star Catalogue as
   analytic points, and the faint field is procedural. Both are resolution-free. The panorama only
   has to carry diffuse nebulosity, which is the lowest-frequency content in the whole scene and
   precisely the part that survives being smaller.
2. **The source has no fine structure to lose.** It is a survey map of diffuse galactic light. There
   is no edge in it anywhere.

An asset without those two properties — a relief map, a coastline, imagery — will land somewhere
else entirely. The relief work found 8192 lossless for that class, and that finding stands; this is
not an argument against it.

## Reproducing this

    SKY_ONLY=4096 node scripts/build-sky-assets.mjs     # and 8192, 2048, 1024
    npm run dev -- --port 5183 --strictPort
    # open http://127.0.0.1:5183/dev/sky-lab.html

The bench drives MapLibre's frames by hand and reads pixels inside a custom layer's `render`, which
is the only place `gl.readPixels` works — the browser pane runs with `preserveDrawingBuffer` off, so
a post-frame `drawImage` comes back pure black whatever was on screen.
