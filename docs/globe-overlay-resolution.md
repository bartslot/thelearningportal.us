# What resolution a globe overlay actually needs

Measured input for the tiled/LOD overlay workstream, from the ocean layer. Every number here was
measured, either off the source data or off the framebuffer with `gl.readPixels` — see
`harness/ocean/`.

## The headline: sharpness and resolution are not the same requirement

The cloud deck, a normal map and a coastline get grouped together as "one global texture, magnified
until it falls apart". For continuous-tone data — cloud cover, a normal map — that is exactly right:
magnify it and you get blur, and there is no way to invent the missing detail.

**A coastline is not that case, because it is a boundary rather than a picture.** Encoded as a
signed distance field, the edge position is reconstructed *inside* the texel by the interpolation
the GPU is doing anyway, so magnification does not produce a staircase. This is the same reason SDF
fonts stay crisp when scaled up.

Measured on the Cyrenaican coast at z9, where one texel of the 8192 field spans **38 device
pixels**:

| | staircase tread | transition width |
|---|---|---|
| binary mask, 8192 | 14.5 px | 1 px (hard step) |
| distance field, 8192 | **2.7 px** | 9 px (controlled ramp) |

Same resolution, same source polygon. That factor is the encoding alone.

What resolution still buys is **shape**, not crispness. Against the coastline visible in the
imagery, mean position error:

| | mean error |
|---|---|
| binary mask, 1024 (39 km texel) | 61.3 km |
| binary mask, 8192 (4.9 km texel) | 24.9 km |
| distance field, 8192 | 24.6 km |

The distance field bought **0.3 km** of position accuracy and everything else came from resolution.
So the two axes are cleanly separable, and the tiling system should be sized by the shape axis.

## What a 1:1 texel would cost, per zoom

Device pixels at DPR 2, at the equator. `m/px = 156543.03 × cos(lat) / 2^z / 2`.

| zoom | m per device px | global equirect width for texel = 1 px | R8 memory |
|---|---|---|---|
| z2 (globe) | 9,784 | 4,096 | 8 MB |
| z5 | 1,223 | 32,768 | 537 MB |
| z8 | 306 | 131,072 | 8.6 GB |
| z11 | 38.2 | 1,048,576 | 550 GB |
| z14 | 4.8 | 8,388,608 | 35 TB |

A single global texture stops being possible somewhere around **z5**, and `MAX_TEXTURE_SIZE` is
16,384 on desktop and still 4,096 on low-end Android. That is the case for tiling, and it stands.

**But an SDF does not need texel = 1 px.** It was measured still sharp at 38 px per texel, so the
requirement is set by the smallest coastal feature that should be *visible*, not by the pixel. Taking
a feature that spans ~8 device pixels as the smallest that reads as a shape rather than a speck,
the requirement is texel ≤ 4 device px:

| zoom | texel needed | global width | practical source |
|---|---|---|---|
| z8 | 1.2 km | 32,768 | Natural Earth 10m |
| z11 | 153 m | 262,144 | OSM coastline |
| z14 | 19 m | 2,097,152 | OSM coastline |

That is **two to three fewer LOD levels** than a plain mask would need for the same apparent
sharpness. If the tiling system carries distance fields rather than masks, it can be
correspondingly shallower.

## The source is the real ceiling

Measured over all 59,247 coastline segments in `ne_50m_land`, 597,490 km of coast:

| percentile | segment length |
|---|---|
| p10 | 3.01 km |
| p25 | 4.77 km |
| **p50** | **7.63 km** |
| p75 | 12.54 km |
| p90 | 19.78 km |
| p99 | 43.24 km |

A segment shorter than a texel is detail the raster cannot represent, so this is where a bake stops
recording anything new:

| bake width | texel | detail lost |
|---|---|---|
| 4,096 | 9.78 km | 63% of segments |
| 8,192 | 4.89 km | 26% |
| 16,384 | 2.45 km | 6% |
| 32,768 | 1.22 km | 1% |

**This corrects an earlier claim of mine that 8192 sits at the data's limit — it does not.** At 8192
a quarter of Natural Earth 50m's own detail is still being thrown away, and 16,384 would recover
most of it. Under "quality first" that is the indicated bake, at the cost of 134 MB of VRAM and
sitting exactly on the desktop `MAX_TEXTURE_SIZE` ceiling — which is itself an argument for tiles.

And the measured 12 km median position error against the imagery **is Natural Earth 50m's own
error**. No amount of tiling a 50m-derived field improves it. Ranked by what actually moves the
coastline:

1. Better source data — NE 10m, then OSM coastline. This dominates everything else.
2. A finer bake, up to where the source saturates.
3. The encoding — already done, and it is what decoupled sharpness from both of the above.

## The direct answer, for tile sizing

Asked: what metres-per-pixel does a coastline need to stay sharp at z8, z11, z14? The question has
two answers because it contains two requirements, and for an SDF they are far apart.

Screen resolution, device pixels at DPR 2, equator:

| zoom | m per device pixel |
|---|---|
| z8 | 305.7 |
| z11 | 38.2 |
| z14 | 4.8 |

**For sharpness alone, the requirement is nearly nothing.** Measured sharp at 38 device pixels per
texel, and that was not the breaking point — it is just the coarsest ratio tested. Sharpness is
essentially free with this encoding.

**Shape is what binds.** Taking a coastal feature spanning ~8 device pixels as the smallest that
reads as a shape rather than a speck, the requirement is texel ≤ 4 device pixels:

| map zoom | texel needed | global-equivalent width | 512² tiles at that level |
|---|---|---|---|
| z8 | 1.22 km | 32,768 | 64 × 32 = 2,048 |
| z11 | 153 m | 262,144 | 512 × 256 = 131,072 |
| z14 | 19 m | 2,097,152 | 4,096 × 2,048 = 8.4M |

So the coastline pyramid runs about **two levels shallower than the map zoom it serves** — an SDF
tile at level *n* looks as sharp as a mask tile at level *n+2*. A 512² single-channel tile is 256 KB
raw and compresses to roughly 20–60 KB lossless, since most tiles are entirely land or entirely
open sea and saturate flat.

Sampling in the shader goes through exactly two functions, `coastDistanceKm` and `shelfFraction`,
which are the only places that touch a texture — enforced by a test. Swapping the baked field for
streamed tiles is a change to those two and to nothing else, and `shelfFraction` is also where real
bathymetry replaces the distance proxy if that reverses.

## Cost

Re-baking at any resolution is `python3 scripts/build-ocean-sdf.py`, about 16 seconds. Nothing here
is expensive to change, which is why the encoding was worth settling first and the resolution can
wait for the tiling design.
