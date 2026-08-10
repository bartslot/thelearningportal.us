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
vec4  tiledSample(vec2 mercatorUV);      // cross-faded across LOD levels
vec4  tiledSampleSphere(vec3 unitPos);   // sphere direction → mercator → sample
float tiledShortfall();                  // 0 = pyramid has the detail, 1 = starved
```

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
| z2  | 6.765 | 6.869 | 1.0× | 3 → 4 | 16 → 19 |
| z8  | 0.111 | 13.689 | **123×** | 3 → 9 | 1 → 62 |
| z11 | 0.022 | 4.959 | **228×** | 3 → 12 | 1 → 62 |
| z14 | 0.000 | 0.398 | flat → real | 3 → 12 | 1 → 4 |

Spread tells the same story: at z11 the global field spans 5 luminance levels against the pyramid's
214, and at z14 it is 0 — a literally featureless fill — against 51.

**z2 is the control, and it matters.** At globe zoom a 2048-wide field is genuinely enough, and the
pyramid returns the same picture. The fix changes nothing where nothing was broken.

z14 is past the DEM's last level, so both runs are starved. The pyramid still shows real terrain
magnified 8× (`worstPixelsPerTexel: 8`, `shortfall: 0.875`); the global field shows nothing at all.
That is exactly the case `tiledShortfall()` exists for.

### Cache

| | requests | network | store hits | hit rate |
|---|---:|---:|---:|---:|
| cold, first load | 143 | 128 | 15 | 0.105 |
| warm, after reload | 143 | 0 | 143 | **1.000** |

The warm row is the persistent store doing its job: a full reload of a four-zoom sweep with **zero
network requests**. Within one session, descending z11 → z14 also cost zero fetches, because the
z12 tiles were already resident.

96 MB budget, 128 atlas slots. The sweep held 35.8 MB decoded across 143 tiles and 32 MB of atlas,
with 0 evictions and 0 failures.

## Known artefact, not yet fixed

At a level boundary — a z9 tile beside a z10 one — there is a soft brightness step. It is not an
indexing bug (that was the page-table alignment, fixed above): a coarser DEM genuinely has gentler
slopes, so its normals are honestly flatter. Rendering a uniform single level makes it vanish
entirely, which is how it was isolated.

The standard fix is to blend toward the parent near boundaries rather than switching at them. It is
small, visible only on shaded relief, and it does not block anyone building against this interface,
so it is written down rather than rushed.

## Open questions for the PM

1. **Clouds have no tiled cloud source.** NASA GIBS publishes cloud fraction as WMTS tiles in the
   same z/x/y scheme, which would drop straight in. Worth confirming that is wanted before I wire
   it, or clouds keep the global field for coverage and use the pyramid only for `starved`.
2. **Normal-map seams at tile edges.** Terrarium tiles have no overlap, so a normal computed at a
   tile's edge is missing one neighbour row. Options are a half-texel seam (19 m at z12, and not
   visible in the renders above), or fetching the 8 neighbours per tile at 8× the requests. I plan
   the seam unless the shadow session says it shows.
3. **Budget.** 96 MB of decoded tiles is my default; the four-zoom sweep used 35.8 MB. If the
   mountain-shadow session wants a lot of z12 resident at once, say so and I will raise it.

## GPU texture compression

KTX2/Basis was asked about. Feasibility, briefly: it would cut atlas VRAM roughly 4–6× and let far
more tiles stay resident at the quality Bart wants. It needs a transcoder in the bundle (~200 KB
wasm) and, more awkwardly, our tiles are **derived** — normals and land masks computed at runtime
from PNG DEM — so there is nothing pre-compressed to fetch. Compressing at runtime is not viable.
The real win would need a build step that bakes and compresses tiles ahead of time, which is a
different piece of work. **Recommendation: not now, and not blocking.** Revisit if VRAM becomes the
limit rather than bandwidth.
