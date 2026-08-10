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

## What the ocean needs from a depth channel

Answering the tiled-LOD session's two questions with measurements rather than preference.

### The range is 0 to −200 m, and that is the whole of it

Beer-Lambert saturates. How far a given depth sits from open-ocean colour, on its strongest
channel, in 8-bit levels:

| depth | distance from open ocean | sensitivity |
|---|---|---|
| 0 m | 102 levels | 91.8 levels/m |
| 5 m | 45.5 | 5.5 levels/m |
| 20 m | 19.5 | 0.90 levels/m |
| 50 m | 5.9 | 0.24 levels/m |
| 100 m | 0.79 | 0.03 levels/m |
| 200 m | **0.015** | ~0 |
| 1000 m | 1e-16 | 0 |

Past 200 m every depth renders as the same colour, so storing range beyond it buys nothing. More
than nine tenths of the signal lives in the first 50 m. **Do not spread precision evenly to 11 km** —
spend it all in the shallows.

### 8 bits linear will not do it

Worst-case banding across the encoding's own step, over 0 to −200 m:

| encoding | worst banding | where |
|---|---|---|
| 8-bit linear | **72 levels** | at the surface |
| 8-bit sqrt | 4.8 levels | 0.5 m |
| 16-bit linear | **0.28 levels** | at the surface |

Banding on a smooth sea floor reads as contour rings, and the shallows where it is worst are the
part anyone looks at. **16 bits over 0..−200 m is the ask.** If only 8 are available, square-root
code them and expect a visible ring in the first metre or two — the shoreline fade masks some of it,
but not all.

### What the depth ramp needs from `targetPixelsPerTexel`: nothing extra

Relief measured that it needs texel ≤ 2 device pixels; the coastline field stays sharp at 38. Nobody
had measured the depth ramp, and the worry was banding — a colour gradient rendered in steps reads
as contour rings.

Measured against real bathymetry, z12 terrarium, colour change across ONE texel in 8-bit levels:

| site | p95 | p99 | max |
|---|---|---|---|
| North Sea | 0.02 | 0.03 | 0.04 |
| Dogger Bank | 0.02 | 0.03 | 0.04 |
| Hawaii drop-off | 5.54 | 23.9 | 341 |
| Florida Keys reef | 1.97 | 45.4 | 1367 |
| Bahama shelf edge | 56.0 | 185 | 334 |

The distribution is bimodal and that is the whole answer. **Most shallow water by area is dead flat**
— a z12 texel moves the colour by four hundredths of a level, so depth would tolerate being five
zoom levels coarser before anything banded. The steep sites are not banding at all: at a reef edge
the sea really does go from turquoise to navy in a few tens of metres, and a coarse texel there
loses genuine detail rather than inventing a step. Blurring is not banding, and only banding is a
defect.

So: **the depth ramp imposes no constraint tighter than relief's.** Do not raise the tile budget on
its account — 1 or 2 pixels per texel are both comfortable, and since depth rides in the same RGBA
tile as the normals it gets whatever relief asks for anyway. Where detail is genuinely lost is at
drop-offs, and there the binding limit is terrarium ending at z12, not the selection metric.

### GPU block compression is not safe for this channel

BC/ETC/ASTC share an endpoint pair across a 4×4 block, which is exactly the operation that turns a
smooth gradient into steps. At 92 levels per metre of sensitivity at the surface, that is guaranteed
contour rings. There is precedent in this very layer: lossy WebP on the coastline distance field —
visually lossless on anything meant for eyes — displaced the coastline by 1.05 km mean, 4.1 km at
p99. A field is not a picture, and codecs tuned for pictures damage fields in ways that look fine.

### Is 33 cm per step enough? Below 10 m, no — but more bits are not the fix

Fixed point at 3 steps per metre, measured against the ramp's own steepness:

| depth | colour step across one 33 cm bucket |
|---|---|
| 0 m | **30.6 levels** |
| 1 m | 12.4 |
| 2 m | 5.1 |
| 5 m | 1.8 |
| **10 m** | **1.0** |
| 20 m | 0.30 |
| 50 m | 0.08 |

So quantisation is invisible deeper than 10 m and increasingly visible above it, reaching thirty
levels at the waterline. That is real, and it lands on tidal flats, lagoons, reef tops and the
Wadden — water people look at.

**Do not re-scale on this account, though.** The encoding shares one 16-bit number with relief, so
the shallow half cannot be given a finer step without splitting the payload, and the binding limit
above 10 m is almost certainly the source's own vertical accuracy rather than the coding —
terrarium's shallow bathymetry comes from a global compilation with metre-scale error, so finer
buckets would quantise noise. The mitigation belongs on this side: soften the ramp's steepest
couple of metres, where the shoreline coverage is already partial. To be measured against real
tiles rather than guessed, using `stats().completeness` so it is not measured on a half-loaded frame.

### No, depth cannot retire the coastline field

Asked three times now, so here it is as an area rather than as anecdotes. Sampling a grid over the
Netherlands and keeping only the points the coastline field calls LAND, then asking terrarium for
their height:

> **37.2% of Dutch land reads below zero** — 294 of 790 land points.

That is what a height-zero shoreline would flood. Not an edge case, not a rounding error: more than
a third of the country, including the province of Flevoland (−5.7 m) and the Zuidplaspolder
(−7.4 m). The Dead Sea shore reads −414.9 m for the same reason.

Unclamped signed height makes this **more** clear-cut, not less: the zero crossing of `tiledHeight`
is exactly elevation = 0, which is precisely the contour that produces that 37.2%. A DEM's zero
contour is not a coastline; it is a level set that happens to coincide with one wherever nobody has
built a dyke.

This also condemns the alpha shoreline ramp for a **second, independent reason** to the one that
retired it. It was dropped because ±64 m saturates too early for a depth ramp — true — but being
derived from HEIGHT at all is the deeper problem: a polder at −7.4 m sits well inside that ramp and
comes out as sea. If a height-derived water mask is ever reintroduced for another consumer, it will
carry the same flaw.

**So no separate coastline path is needed in the pyramid.** The existing global distance field
stays, and the reason it can is the one this document opens with: a boundary encoded as a distance
field does not degrade with magnification the way a continuous-tone field does — measured still
sharp at 38 device pixels per texel. The argument for tiling clouds and normals genuinely does not
apply to it. Its residual error is source-limited (Natural Earth 50m, 12 km median), which tiling
cannot fix and only better coastline data can.

## Two notes for the sessions this touches

**The shared loader.** `equirect-texture.js` is the right home for this layer's texture and a third
loader is not. One change is needed first: it uploads RGBA, which for the 8192 field is 134 MB of
VRAM against 33 MB single-channel, for one byte of real data per texel. An optional format argument
covers it. A second objection — that `new Image()` lets the browser colour-manage what are numbers
rather than colours — was **measured and does not hold**: both decode paths were read back through a
framebuffer and agreed on all 131,072 texels tested, these files carrying no ICC profile. Worth
keeping the explicit flag as insurance against a tagged file, not worth blocking on.

**Depth is a colour ramp, not terrain.** Confirmed independently from two directions: the terrarium
DEM carries bathymetry and had to be clamped to zero before a relief pass would leave open water
flat, and shading the sea floor turns the Atlantic into a chart. `shelfFraction` may sample depth
and may never differentiate it — there is a test asserting no derivative appears in it. The likely
source is that same terrarium DEM, whose negative elevations at sea mean depth costs no new data
pipeline, just the values clamped the other way round.

## Cost

Re-baking at any resolution is `python3 scripts/build-ocean-sdf.py`, about 16 seconds. Nothing here
is expensive to change, which is why the encoding was worth settling first and the resolution can
wait for the tiling design.
