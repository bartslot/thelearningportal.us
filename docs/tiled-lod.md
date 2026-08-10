# Tiled LOD for globe overlay layers

The interface three overlay layers are waiting on. Branch `tiled-lod`, module
`resources/js/timemap/tiles/`. Nothing here is wired into `/timemap` — that is deferred for the
whole programme.

## The problem, once

Clouds, ocean and mountain shadow each need a global field, and each was about to bake one as a
single equirectangular texture. A single texture has exactly one level. At 2048 wide it is 19.6 km
per pixel, so one screen width holds 512 of its pixels at z2 and one at z11: past the middle zooms
it is a smooth gradient sitting on top of sharp ground. Nothing errors. It just looks wrong, and
worse the closer you get.

A tile pyramid has a level for every distance. z8 beats a 2048-wide global field by 32×; z12 beats
it by 512×. Those are the numbers this module exists to deliver, and they are asserted in
`__tests__/scheme.test.js` rather than claimed here.

## Modules

| file | job | depends on GL |
|---|---|---|
| `scheme.js` | web-mercator z/x/y, wrap, poles, ground-metres-per-texel | no |
| `selection.js` | screen-space error → which tiles at which level | no |
| `cache.js` | LRU + persistent store + in-flight de-duplication | no |
| `sources.js` | terrarium DEM decode → heights, normals, land mask | no |
| `atlas.js` | `TEXTURE_2D_ARRAY` + slot LRU + page tables | yes |
| `index.js` | `createTilePyramid()` façade, and the GLSL you paste in | yes |

Everything except `atlas.js` and `index.js` is pure, so the interesting arithmetic is testable
without a browser.

## The tile scheme

MapLibre's own: web mercator z/x/y, `2^z` tiles per axis, origin at the north-west corner. Not a
preference — our overlay tiles have to land on exactly the same ground as the imagery underneath,
and a scheme of our own would mean resampling every tile and a permanent half-texel offset.

Two things that bite:

- **The radius.** `planet-mesh.js` uses the earth's mean radius (6371008.8 m) because it draws a
  sphere. Web mercator is defined on the WGS84 semi-major axis (6378137 m). They differ by 0.11%,
  which is 7 km at the equator — thirty z12 tiles. `scheme.js` spells out the mercator figure.
- **The poles.** There are no tiles past ±85.0511°, but `buildSphereMesh` reaches ±89.999° with its
  cap rows. `tileForLngLat` clamps to the edge row **and sets `capped: true`**. Do not ignore that
  flag: without it you stretch one row of texels across the last five degrees of Antarctica and
  never find out why the pole looks smeared.

## Selection

```js
import { selectTiles, pixelsPerTexel } from './tiles/selection.js'

const report = selectTiles(camera, source, { targetPixelsPerTexel: 1, maxTiles: 64 })
// report: { tiles, tileCount, maxLevel, minLevel, worstPixelsPerTexel, starved }
// tiles:  [{ z, x, y, key, pixelsPerTexel }]
```

`camera` is a plain object, not a map — `{ center: {lng, lat}, zoom, canvasWidth, canvasHeight,
projection: 'globe'|'mercator', fov?, altitudeMetres? }`. `index.js` has the adapter that builds one
from a real MapLibre map.

The metric is **screen pixels covered by one texel**. Above 1 the tile is magnified and looks soft;
below 1 you are paying for detail that never reaches the screen. Refinement is greedy: split the
blurriest visible tile until everything is sharp enough, the source runs out of levels, or the
budget is spent. Splitting worst-first is what makes a partial budget degrade gracefully.

Why a metric and not `level = zoom + 1`: that table is right only flat, overhead and centred. At the
limb of the globe a tile is seen nearly edge-on and lands on a fraction of the pixels, and refining
it there buys detail nobody can see. The table is pinned in the tests as the special case it is.

**`starved: true` is the interesting output for clouds.** It means the pyramid has run out of source
levels, and it is the honest replacement for `deckDetailFor`'s guess at when to hand over to
procedural noise. Drive `u_detailAmount` off the real shortfall instead of a hardcoded
`FIELD_METRES_PER_PIXEL`.

## Consuming it in a shader

```js
const pyramid = createTilePyramid({ source: TERRARIUM_NORMALS, budgetBytes: 96 << 20 })

// onAdd
pyramid.onAdd(gl)
// render, before you draw
pyramid.update(cameraFromMap(map))          // selection + streaming, cheap and idempotent
pyramid.bind(gl, uniforms, firstTextureUnit) // binds atlas + page tables, sets uniforms
// onRemove
pyramid.onRemove()
```

Your fragment shader pastes in `pyramid.glsl` (a string) and calls:

```glsl
vec4  tiledSample(vec2 mercatorUV);      // raw texel, cross-faded across LOD levels
vec4  tiledSampleSphere(vec3 unitPos);   // sphere direction → mercator → sample
float tiledShortfall();                  // 0 = pyramid has the detail, 1 = starved

// For the DEM source, whose tiles carry a 16-bit SIGNED height and nothing else:
float tiledHeight(vec2 mercatorUV);      // metres, signed — below zero is sea floor
float tiledDepth(vec2 mercatorUV);       // metres of water, zero on land
vec3  tiledNormal(vec2 mercatorUV);      // +R east, +G SOUTH, +B up
```

**Neither clamp is baked in.** Relief takes `max(h, 0)` because three kilometres of water is opaque
and shading the sea floor turns the Atlantic into a bathymetric chart; the ocean takes the negative
half as depth. Baking either into the tile would leave the other consumer with nothing — and the
ocean's failure would be silent, since relief would still look perfect.

`tiledNormal` already clamps to sea level per tap, before differencing. Clamping after averaging is
how you delete a coastal mountain: half a 2,000 m massif and half a 4,000 m trench averages to
−1,000 m and then to zero.

### Do not decode the normal

`tiledNormal` returns a **unit vector, already decoded** — floats computed in the shader, never
packed into a byte. The convention matches `terrain-normals.js`: +R east, +G SOUTH, +B up.

So do **not** apply `decodeTerrainNormal` (or the conventional `texel * 2 - 1`) to its output. Those
exist for the baked equirectangular normal map, where flat ground lands on byte 128 and the naive
decode reads it back as 0.0039 rather than 0 — a constant tilt on every flat texel, measured at a
full 8-bit level of shading across open ocean. There is no encode step here, so there is nothing to
undo, and undoing it anyway would introduce the very bias it was written to remove.

### The poles

`tiledSampleSphere` maps a sphere direction into mercator, and mercator stops at ±85.0511° while
`buildSphereMesh` deliberately reaches ±89.999°. Past the edge the sample clamps to the last row of
texels and smears it across the final five degrees, with no flag to return.

`tiledSphereCoverage(vec3 unitPos)` is that flag: 1 where the pyramid can speak for the ground,
falling to 0 across the caps. Multiply by it rather than writing a per-layer latitude test, so every
overlay lets go of the pole at the same latitude. It matters most for relief — Antarctica is a
four-kilometre dome and the terminator crosses it for months.

`tiledSample` takes mercator 0..1 — the same space `buildSphereMesh` lays its vertices out in, so
you already have it as `a_pos`. For a raymarch, convert from the unit sphere with
`tiledSampleSphere`.

### Why a texture array, and why it is allowed

One `TEXTURE_2D_ARRAY` holds every resident tile as a layer, so a shader samples any of them with no
bind per tile. The alternative — packing tiles into one big 2D atlas — needs a border of padding
around every tile to stop linear filtering bleeding across neighbours, and gives up per-tile
mipmaps. The array has neither problem.

`sampler2DArray` needs GLSL ES 3.00, and mixing shader versions in one program is not possible, so
this looked blocked. It is not, and the reason is worth writing down because it is not obvious:
**MapLibre's projection prelude carries no `#version` line and uses no `attribute`, `varying` or
`texture2D`** — it is version-agnostic, valid under both ES 1.00 and ES 3.00. And MapLibre v5 asks
for a `webgl2` context first. So a custom layer may emit `#version 300 es` as its first line, paste
the prelude after it, and use texture arrays. Verified against
`node_modules/maplibre-gl/dist/maplibre-gl-dev.js`.

Consequence for you: a layer using the pyramid must be ES 3.00 throughout — `in`/`out` instead of
`attribute`/`varying`, `texture()` instead of `texture2D()`, and its own `out vec4` instead of
`gl_FragColor`. `pyramid.supported` is false on a WebGL1 context; keep your existing global-texture
path behind that flag rather than shipping a blank layer to old hardware.

One more ES 3.00 trap, already paid for: **ES 3.00 gives `float` and `int` a default precision but
not `sampler2DArray`.** Without `precision highp sampler2DArray;` the shader fails to compile with
"No precision specified", the program never links, and the layer draws nothing with no clue why.
`pyramid.glsl` declares it for you — this is only worth knowing if you write your own array sampler
alongside it.

### The page table, which is how the shader finds a tile

A shader cannot look up a hash map. So each frame the pyramid writes a small indirection texture —
the standard virtual-texture page table — over the **visible mercator extent** (not the whole
square, which would need to be 4096² to index z12):

- `u_tm_page` RGBA8 — `r` = atlas slot, `g` = level, `b` = fade, `a` = flags
- `u_tm_pageAncestor` RGBA8 — `r` = ancestor slot, `g` = ancestor level
- `u_tm_pageExtent` vec4 — the mercator rectangle the tables cover

`tiledSample` reads the page, computes the UV within that tile from the level, samples the array,
and mixes with the ancestor by `fade`. That is where cross-level blending happens: a tile fades in
over its resident parent as it arrives, rather than popping. Parent fallback is the same mechanism
with `fade = 0`.

**The page grid is sized per frame to one texel per finest-level tile**, in each axis
independently — 12×8 over the Alps at z9, for example. This is not a detail. A fixed page size is
the obvious choice and it is wrong in a way that reads as someone else's bug: a page texel
straddling two tiles can only name one of them, while the shader still computes the position
*within* a tile from the coordinate itself, so near every boundary it samples the right place in the
wrong tile. On real terrain that is a grid of displaced rectangles that looks exactly like a
tile-seam artefact. Found by rendering the Alps at a uniform z10 and seeing the seams get *worse*
when level mixing was removed. `stats().pageAligned` goes false if the extent ever needs a finer
grid than the cap allows, rather than reintroducing it silently.

## Caching

Two tiers, and the second one is the architecture rather than an optimisation — Google Earth's speed
is mostly never fetching the same thing twice.

- **Memory**: LRU of decoded tiles under a stated byte budget (default 96 MB). Tiles referenced by
  the current frame are pinned and never evicted underneath a draw.
- **Persistent**: the Cache API, keyed by tile URL, surviving reloads. Injectable, so tests run
  against an in-memory store and no test ever touches the network.
- **In-flight de-duplication**: concurrent requests for one tile share a single fetch.

`pyramid.stats()` returns `{ memoryHits, storeHits, networkFetches, hitRate, bytes, evictions,
residentTiles, atlasSlots }`. Measure with it; do not eyeball.

## Data sources

`sources.js` starts with the DEM already in `map-imagery.js`:
`https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png`, maxzoom 12, encoding
`height = (R*256 + G + B/256) - 32768` metres.

There is **one source, `TERRARIUM_TERRAIN`**, not the two the first draft of this document
promised. Mountain shadow and the ocean both want answers derived from the same heights, so one
RGBA tile carries both and is fetched and decoded once:

- **RGB — the surface normal**, at real LOD, with no global asset baked at all. Scaled by each
  tile's own ground resolution: the same height difference is a gentle slope across 611 m of ground
  at z8 and a cliff across 38 m at z12, and forgetting that makes every level look like a different
  planet — invisible until cross-level blending puts two of them on screen together.
- **A — the shoreline**, as a ramp that is linear in metres through ±64 m of sea level rather than
  a hard mask. A binary mask is what makes the current baked one step along every coast: linear
  filtering across a binary edge puts the shoreline at the texel boundary instead of where the land
  actually stops. A ramp interpolates to the true zero crossing, for the same reason the ocean
  layer's distance field had to stay linear rather than take a square root.

`decodeTerrarium` and `terrainTextureFromHeights` are exported separately, so the ocean session can
run its own distance transform on the raw heights if it wants a true SDF rather than this ramp.

**Clouds** has no tiled source yet; this is the open question below.

## Measured

`resources/js/timemap/tiles/__harness__` is a development rig: a real MapLibre globe, a real custom
layer, real DEM tiles over the Alps (7.9°E, 46.0°N). It reads pixels back with `gl.readPixels`
**inside the layer's `render`** — the browser pane forces `preserveDrawingBuffer` off, so reading
the canvas after the frame returns pure black and would report a working layer as a broken one.

The same view is drawn twice: once with the pyramid capped at **z3**, and once uncapped. z3 is not
an arbitrary handicap — eight 256-texel tiles around the equator is 2048 texels, exactly the global
equirectangular field the cloud deck samples today. **The capped run is the status quo, measured.**

*Detail* is the mean absolute neighbour difference in luminance of the shaded relief; a texture
magnified past its resolution is a smooth gradient and scores near zero. *Spread* is the luminance
range, which separates "smooth but real" from "flat fill". Every row was captured at
`completeness: 1`, so nothing was measured mid-load, and the figures are bit-identical across runs.

| zoom | detail, 2048 field | detail, pyramid | gain | level | tiles |
|---:|---:|---:|---:|---:|---:|
| z2  | 6.29 | 6.82 | 1.1× | 3 → 4 | 16 → 19 |
| z8  | 0.091 | 13.84 | **153×** | 3 → 9 | 1 → 62 |
| z11 | 0.018 | 7.46 | **412×** | 3 → 12 | 1 → 62 |
| z14 | 0.002 | 1.27 | **605×** | 3 → 12 | 1 → 4 |

Spread tells the same story: at z11 the global field spans 4 luminance levels against the pyramid's
215, and at z14 it is 1 — a featureless fill — against 61.

**z2 is the control, and it matters.** At globe zoom a 2048-wide field is genuinely enough, and the
pyramid returns essentially the same picture. The fix changes nothing where nothing was broken.

z14 is past the DEM's last level, so both runs are starved. The pyramid still shows real terrain
magnified 8× (`worstPixelsPerTexel: 8`, `shortfall: 0.875`); the global field shows nothing at all.
That is exactly the case `tiledShortfall()` exists for.

The z11 and z14 figures are roughly double an earlier build of this module that baked an 8-bit
normal per tile. Deriving the normal in the shader instead removed the quantisation and the
one-sided edge stencil at once.

### Absolute anchors, because every other positional test is relative

The gains above are all *relative* — pyramid against a z3-capped baseline. So are most of the
positional tests: a tile against its neighbour, a point inside its own tile's bounds, a parent
against its children. **A pyramid displaced by a uniform offset satisfies every one of them.** That
is not hypothetical here: the page-table straddle was a positional error and it was found by
rendering, not by any test.

Two anchors were added against authorities outside this module, and both were checked by breaking
the code on purpose:

- `scheme.test.js` compares against the published slippy-tilenames formula, written out
  independently — it uses `log(tan + sec)` where `scheme.js` goes through `mercatorYFromLat`'s
  `log(tan(π/4 + lat/2))`, so an offset in one shows as a disagreement rather than cancelling. Plus
  the equator and prime meridian, which are exact in web mercator with no rounding to hide in, and
  the world's edge at 85.0511287798066°.
- `atlas.test.js` takes real coordinates, works out independently which tile covers them, then walks
  the page table exactly as the shader does and checks it points at that tile's slot.

Injecting a half-tile offset into `tileForLngLat` fails 4 tests; reinstating the fixed 32×32 page
grid fails 3, including the absolute one. A test that has never been seen to fail is not evidence.

### Cache

| | requests | network | store hits | hit rate |
|---|---:|---:|---:|---:|
| cold, first load | 143 | 128 | 15 | 0.105 |
| warm, after reload | 143 | 0 | 143 | **1.000** |

The warm row is the persistent store doing its job: a full reload of a four-zoom sweep with **zero
network requests**. Within one session, descending z11 → z14 also cost zero fetches, because the
z12 tiles were already resident.

96 MB budget, 128 atlas slots. The sweep held 17.9 MB decoded across 143 tiles and 16.0 MiB of
atlas, with 0 evictions and 0 failures.

### VRAM, which is the real argument

A texture uploaded from an `<img>` is RGBA8 whatever the file said, and mipmaps add a third again.
A global field costs what its resolution costs, everywhere, forever:

| global single texture | resident | tiled pyramid | resident |
|---|---:|---|---:|
| 2048 × 1024 | 10.7 MiB | atlas, 128 slots of RG8 | **16.0 MiB** |
| 4096 × 2048 | 42.7 MiB | decoded tiles in memory | 17.9 MiB |
| 8192 × 4096 | 170.7 MiB | | |
| 16384 × 8192 | 682.7 MiB | | |

The tiled figure **does not change between z2 and z14** — it is bounded by the screen, not by the
world, which is the whole point. The global column doubles with every level of detail you ask for
and is resident whether you are looking at Utrecht or at the Pacific.

Two consequences worth stating plainly:

- An 8192 × 4096 fallback does not fit a 96 MB budget on its own (170.7 MiB). On a WebGL1 machine
  the largest global map that leaves room for anything else is 4096 × 2048 at 42.7 MiB.
- The DEM's tiles are two-channel (`RG8`), because a height needs sixteen bits and no more. That
  halves the atlas against an RGBA one for identical data — 16 MiB rather than 32.

## Tile edges: why there is no apron

The fleet's ruling was to bake a 1-texel apron — 258 × 258 carrying 256 × 256 — after Realistic
Earth measured that a one-sided difference at every tile edge costs p99 8 shading levels at the
terminator, against an 11.1 mean relief effect. That measurement stands and it is the reason the
naive answer is not good enough.

**The apron is not built, because this pyramid does not need one.** An apron is the right fix when
tiles are baked offline and the shader has only the tile it is given. Here the normal is derived in
the shader, and every tap goes through the page table — so a tap that crosses a tile boundary is
routed into the neighbouring tile's slot and lands at the correct place inside it. The difference is
two-sided at tile edges, exactly, with:

- no apron and no border texels,
- **zero extra bytes** (against the apron's 1.6%),
- zero extra requests, same as the apron,
- and no bake step, which matters because our tiles are fetched as published rather than baked.

It costs four texture taps instead of one. Verified by rendering a uniform single level over the
Alps: the tile-edge seams that were visible before are gone.

If tiles are ever baked ahead of time (see compression, below), the apron becomes worth revisiting —
it would let a consumer difference locally without four page lookups.

## Level boundaries: a real artefact, and what was done about it

Where a z9 tile meets a z10 one, the two disagree about the height along their shared edge: they
sampled the same ground at different resolutions. Two things came out of that, in order:

1. **A broad brightness step.** Measuring slope over each tile's *own* texel means a z9 tile's slope
   is measured over twice the ground, and a coarser DEM is genuinely smoother, so the two sides are
   lit differently. Fixed by measuring every level over the same ground baseline — one texel of the
   finest level in view. The coarse side then simply carries less detail, which is the truth,
   instead of different lighting.
2. **A hard dark line.** With a fine baseline the disagreement stops being spread out and
   concentrates into one texel, which is worse: a resampling difference of tens of metres becomes a
   slope over 38 m of ground. Fixed by not differencing across a level change at all — `tm_slope`
   checks the neighbour's level and falls back to a one-sided difference there, dividing by the span
   it actually used rather than by the two-sided baseline.

Ordinary same-level tile boundaries are untouched by that fallback: the field really is continuous
there, and the difference stays two-sided. Only the handful of edges where the level changes lose a
side.

## Where this lands

**Not in the lesson-map bundle.** That bundle just had about 1 MB of glyph chunks and three tilesets
removed for load time, because a class opens it on school wifi.

The measured cost of the module itself is small — six files, no dependencies beyond `planet-mesh.js`
and `terrain-normals.js`, no library — but bundle size is the wrong number to argue from anyway. The
real cost is that a pyramid streams tiles: 128 network requests for a four-zoom sweep, cold. A
lesson map that opens on a fixed view and stays there gains nothing from LOD and pays for it in
requests at exactly the moment thirty children open it at once.

The Time-Map is a teacher research tool where someone zooms from the globe to a city, which is the
case this exists for. That is where it goes.

If it is ever wanted in the lesson map, the argument should come with a bundle-size delta and a
cold-load number measured on that page, not from here.

## Open questions for the PM

All three of the original questions are settled: no tiled cloud source (keep the global field, and
use `magnificationOf` for the honest magnification number), tile edges need no apron here (see
above), and the 96 MB budget stands — the sweep uses 17.9 MB of decoded tiles and 16.0 MiB of atlas.

Still open:

1. **`planet-mesh.js` and `terrain-normals.js` are on this branch verbatim, copied.** Both are owned
   elsewhere in the fleet and are imported here rather than re-derived, which was the point — but
   until the branches merge there are two files that happen to agree. **They must resolve to one
   module each at merge, not two copies.** Flagging rather than resolving unilaterally, since
   neither is mine.
2. **Which regions get baked**, if compression goes ahead. That decision comes before the
   compression work, not after — see below.

## As a plugin: what the interface may and may not assume

This is meant to serve products that are not History Portal, so the public surface was audited
against that. It assumes: WebGL2, a MapLibre v5 custom layer's `render` arguments, and web-mercator
z/x/y tiles. It assumes nothing about a lesson player, a Livewire component, this repo's scene
model, a database, or History Portal at all. `createTilePyramid` / `onAdd` / `update` / `bind` /
`onRemove` take a GL context, a plain camera object and a source descriptor — no framework types
cross the boundary.

Two places where an assumption could still hide, both deliberate:

- **The GLSL paste** names its uniforms `u_tm_*`. That prefix is the only namespace collision risk a
  consumer inherits, and it is documented rather than generated so a layer can grep for it.
- **The camera adapter** (`cameraFromMap`) is the one MapLibre-shaped thing, and it is a separate
  export precisely so a non-MapLibre host can build the plain camera object itself and never import
  it.

### Tiles that vary by date

Not built, and worth flagging before anything forecloses it: tectonic movement and generated
historical cities would mean a tile at the same z/x/y differs by DATE — a 200-million-year-old
coastline is not the modern one. Nothing in the current design prevents that. The tile key is
`z/x/y`, produced by `keyOf` in one place and consumed as an opaque string by the cache, the atlas
and the page table. Adding a date would mean extending that key and the source's URL template, and
nothing else would need to know. The persistent store is keyed by URL, so dated tiles would cache
separately for free. **The one thing that would make it hard is baking the date into the atlas slot
allocator or the page table**, and neither knows what a key means today. Keep it that way.

## Adaptive quality tiers

The detection and the budget live here; the policy does not. What the pyramid already knows or can
report:

- `pyramid.supported` — false without WebGL2 texture arrays.
- `stats().slots` — clamped to the driver's `MAX_ARRAY_TEXTURE_LAYERS`.
- `stats().atlasBytes` — exact VRAM for the atlas, and it does not grow with zoom.
- `stats().bytes` / `budgetBytes` — decoded tiles held, against the ceiling.
- `stats().hitRate`, `networkFetches` — whether the network or the GPU is the constraint.
- `targetPixelsPerTexel` — the cost dial. Measured by the relief consumer: amplitude does not decay
  with magnification, only crispness does, at a clean 1/n. So 2 costs a quarter of the tiles for
  about 60% of the detail, and 4 costs a sixteenth for a third. There is no threshold to clear,
  which makes this a pure quality/cost lever rather than a correctness one.

A tier is therefore a triple of `layers`, `budgetBytes` and `targetPixelsPerTexel`, all already
constructor options. The 96 MB budget becomes a per-tier number rather than one constant.

## GPU texture compression

Costed properly now that a build step is in scope rather than a blocker.

**What it would buy, against today's measured figures.** The atlas is 16.0 MiB (128 slots of
256 × 256 RG8) and decoded tiles in memory are 17.9 MiB. Transfer is one 256 × 256 terrarium PNG per
tile, about 40–60 KB.

| | now | with KTX2/Basis |
|---|---:|---:|
| atlas VRAM, 128 slots | 16.0 MiB | 4.0 MiB (BC5/EAC-RG, 4:1) |
| transfer per tile | 40–60 KB PNG | 32 KB, and no decode |
| main-thread decode | PNG → canvas → repack | transcode only |

**What the build step involves.** Fetch terrarium z0–z12 for the regions we care about, decode,
repack to the fixed-point RG encoding, compress to KTX2 with an RG-capable format, and publish.
Whole-world z12 is 16.7 M tiles and is not on the table; per-region bakes around the places lessons
actually visit are, and that is exactly the "generate only that portion of the world" instinct the
pyramid already implements at runtime.

**What it costs to maintain.** Three things, and the third is the real one:
1. A transcoder in the bundle, roughly 200 KB of wasm, loaded once.
2. A publish pipeline and storage for the baked set, plus a cache-busting version in the tile URL.
3. **A second source of truth.** Baked tiles can drift from the runtime decoder, and a stale baked
   tile behind a corrected decoder is unfalsifiable — the Realistic Earth session hit exactly this
   and had to add a cache version bump to make it falsifiable again. Any bake needs its encoding
   version in the URL from day one, not added later.

**One caution specific to us.** BC5/EAC-RG is a *lossy, block-based* format, and our two channels
are not colour — they are one 16-bit number split across them. Compressing the high and low bytes
independently, lossily, corrupts heights in a way that is not a small error: an error of one in the
high byte is 85 metres. **A naive KTX2 pass on this payload is not merely lower quality, it is
wrong.** The viable routes are a single-channel 16-bit format, or splitting into a coarse channel
plus a residual so lossy compression degrades gracefully. That is a design task, not a flag.

**Recommendation.** Worth doing, not yet. The cheap half is already taken: RG8 rather than RGBA8
halved the atlas for nothing. Compression should follow a decision about which regions get baked,
because the bake is what makes it possible, and it needs the encoding question above answered
first. Nothing about the current design forecloses it — the atlas takes an internal format, and the
cache stores opaque bytes.
