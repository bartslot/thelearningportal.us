/**
 * atlas.js — where resident tiles live on the GPU, and how a shader finds one.
 *
 * A shader cannot hold a hash map, and it cannot have a texture bound per tile. So this is the
 * standard virtual-texture arrangement, in two parts:
 *
 *  - ONE `TEXTURE_2D_ARRAY` holding every resident tile as a layer. One bind, any tile.
 *  - A PAGE TABLE — a small indirection texture over the visible ground, where each texel names the
 *    array layer covering it and the level that layer is at. The shader reads the page, works out
 *    where it is inside that tile, and samples the array.
 *
 * WHY AN ARRAY AND NOT A PACKED 2D ATLAS. Packing tiles into a grid inside one big texture works,
 * but every tile then needs a border of duplicated texels or linear filtering bleeds its
 * neighbour's ground across the join, and per-tile mipmaps become impossible because the mip chain
 * would blend across tile boundaries. The array has neither problem: each layer is its own texture
 * as far as filtering is concerned.
 *
 * WHY THIS IS ALLOWED AT ALL. `sampler2DArray` needs GLSL ES 3.00, and a program cannot mix shader
 * versions — which appeared to rule it out for a MapLibre custom layer. It does not: MapLibre's
 * projection prelude carries no `#version` line and uses no `attribute`, `varying` or `texture2D`,
 * so it compiles unchanged under ES 3.00, and MapLibre v5 asks the canvas for `webgl2` first. A
 * layer using this must therefore be ES 3.00 throughout — `in`/`out`, `texture()`, its own
 * `out vec4` — and must check `supported` before assuming any of it.
 *
 * SECOND PAGE TABLE. Tiles arrive at different times and at different levels, and swapping one for
 * another between two frames is a pop. So a page names the tile it wants AND the coarser resident
 * tile it is replacing, plus how far the change has got. The shader mixes the two. Parent fallback
 * — a child that has not arrived yet — is the same mechanism with the fade at zero, which is why
 * there is one code path rather than two.
 */

import { keyOf, tileMercatorBounds } from './scheme.js'

/** Slot indices travel through a byte of the page table, so this is the ceiling on residency. */
const MAX_SLOTS = 255

/**
 * @param {WebGL2RenderingContext} gl
 * @param {object} [opts]
 * @param {number} [opts.tileSize]        texels along a tile's edge
 * @param {number} [opts.layers]          how many tiles stay resident on the GPU
 * @param {number} [opts.pageResolution]  CAP on the page grid; the real size is chosen per build
 * @param {number} [opts.fadeMs]          how long a tile takes to replace its ancestor
 * @param {Function} [opts.now]           clock, injected so the fade is testable
 */
export const createTileAtlas = (gl, {
  tileSize = 256,
  layers = 128,
  pageResolution = 32,
  fadeMs = 260,
  now = () => performance.now(),
} = {}) => {
  // WebGL1 has no texture arrays and no way to fake one. Callers keep their global-texture path
  // behind this flag rather than shipping a blank layer to old hardware.
  const supported = typeof gl?.texStorage3D === 'function'

  const slotCount = supported
    ? Math.min(layers, MAX_SLOTS, gl.getParameter(gl.MAX_ARRAY_TEXTURE_LAYERS))
    : 0

  /** key -> {slot, z, x, y, uploadedAt}. Insertion order is LRU order, as in cache.js. */
  const resident = new Map()
  const freeSlots = []
  for (let i = slotCount - 1; i >= 0; i--) freeSlots.push(i)
  let retained = new Set()
  let extent = [0, 0, 1, 1]
  let shortfall = 0
  let disposed = false

  let tiles = null
  let pageTexture = null
  let ancestorTexture = null

  /**
   * The page grid is sized per build to match the finest tile grid across the extent EXACTLY, one
   * page texel per finest-level tile. Coarser tiles then cover a whole number of page texels, so
   * every level lines up too.
   *
   * A fixed page size is the obvious choice and it is wrong in a way that is easy to misread. If a
   * page texel straddles two tiles it can only name one of them, while the shader still computes
   * the position WITHIN a tile from the coordinate itself — so near every boundary it samples the
   * right place in the wrong tile, and the picture breaks into displaced rectangles. Measured at a
   * uniform z10 over the Alps with a 32x32 page: a visible grid of offset blocks, which reads as a
   * tile-seam artefact and is really an indexing one.
   */
  let pageWidth = 1
  let pageHeight = 1
  let finePage = new Uint8Array(4)
  let ancestorPage = new Uint8Array(4)
  let pageAligned = true

  const resizePages = (width, height) => {
    if (width === pageWidth && height === pageHeight) return
    pageWidth = width
    pageHeight = height
    finePage = new Uint8Array(width * height * 4)
    ancestorPage = new Uint8Array(width * height * 4)
  }

  if (supported) {
    tiles = gl.createTexture()
    gl.bindTexture(gl.TEXTURE_2D_ARRAY, tiles)
    gl.texStorage3D(gl.TEXTURE_2D_ARRAY, 1, gl.RGBA8, tileSize, tileSize, slotCount)
    // CLAMP, not REPEAT: a tile's own edge is the end of it. Wrapping would fetch the far side of
    // the same tile, which is ground thousands of kilometres away.
    gl.texParameteri(gl.TEXTURE_2D_ARRAY, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D_ARRAY, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D_ARRAY, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
    gl.texParameteri(gl.TEXTURE_2D_ARRAY, gl.TEXTURE_MAG_FILTER, gl.LINEAR)

    pageTexture = createPageTexture()
    ancestorTexture = createPageTexture()
  }

  /**
   * A page table is a lookup, so it is filtered with NEAREST.
   *
   * This is the one line that is easy to leave at the default and impossible to diagnose
   * afterwards: interpolating between slot 7 and slot 9 samples slot 8, which is some other piece
   * of the planet entirely, and it only shows as a thin wrong-looking line along page boundaries.
   */
  function createPageTexture() {
    const texture = gl.createTexture()
    gl.bindTexture(gl.TEXTURE_2D, texture)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
    return texture
  }

  /** The least recently used slot that this frame is not drawing with, or -1 if there is none. */
  const reclaimSlot = () => {
    for (const [key, entry] of resident) {
      if (retained.has(key)) continue
      resident.delete(key)
      return entry.slot
    }
    return -1
  }

  /**
   * Put a tile's pixels on the GPU. Returns the layer it landed in, or -1 when every slot is
   * spoken for by the frame being drawn — which is a reason to skip the tile, not to evict.
   */
  const upload = (tile, pixels) => {
    if (!supported || disposed) return -1
    const key = tile.key ?? keyOf(tile)

    const existing = resident.get(key)
    if (existing) {
      resident.delete(key)
      resident.set(key, existing)
      return existing.slot
    }

    const slot = freeSlots.length ? freeSlots.pop() : reclaimSlot()
    if (slot < 0) return -1

    gl.bindTexture(gl.TEXTURE_2D_ARRAY, tiles)
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)
    gl.texSubImage3D(
      gl.TEXTURE_2D_ARRAY, 0, 0, 0, slot,
      tileSize, tileSize, 1, gl.RGBA, gl.UNSIGNED_BYTE, pixels,
    )
    resident.set(key, { slot, z: tile.z, x: tile.x, y: tile.y, uploadedAt: now() })
    return slot
  }

  const slotOf = (key) => resident.get(key)?.slot ?? -1

  /** How far a tile has got at replacing whatever was underneath it. */
  const fadeOf = (entry) => (fadeMs <= 0 ? 1 : Math.min(1, (now() - entry.uploadedAt) / fadeMs))

  const writePage = (page, index, slot, level, fade) => {
    const p = index * 4
    page[p] = slot
    page[p + 1] = level
    page[p + 2] = Math.round(fade * 255)
    page[p + 3] = 255
  }

  /**
   * Rebuild both page tables for a selection.
   *
   * Tiles are written coarsest first, and whatever a texel already held is pushed down into the
   * ancestor table as it is overwritten. That single pass gives both answers at once: the finest
   * resident tile covering each texel, and the next-coarsest one under it to fade from.
   */
  const buildPages = (selection) => {
    if (!supported || disposed) return

    const drawable = selection
      .filter((tile) => resident.has(tile.key ?? keyOf(tile)))
      .sort((a, b) => a.z - b.z)

    extent = mercatorExtentOf(drawable.length ? drawable : selection)
    const [x0, y0, invWidth, invHeight] = extent

    // One page texel per finest-level tile, in each axis independently — the extent is a box of
    // whole tiles, so both counts are integers and the grids line up exactly.
    const finest = drawable.reduce((deepest, tile) => Math.max(deepest, tile.z), 0)
    const across = Math.max(1, Math.round((1 / invWidth) * 2 ** finest))
    const down = Math.max(1, Math.round((1 / invHeight) * 2 ** finest))
    pageAligned = across <= pageResolution && down <= pageResolution
    resizePages(Math.min(across, pageResolution), Math.min(down, pageResolution))

    finePage.fill(0)
    ancestorPage.fill(0)
    const written = new Uint8Array(pageWidth * pageHeight)

    for (const tile of drawable) {
      const entry = resident.get(tile.key ?? keyOf(tile))
      const bounds = tileMercatorBounds(tile)
      // The page texels this tile covers, in page space.
      const i0 = Math.max(0, Math.floor((bounds.x0 - x0) * invWidth * pageWidth))
      const i1 = Math.min(pageWidth - 1, Math.ceil((bounds.x1 - x0) * invWidth * pageWidth) - 1)
      const j0 = Math.max(0, Math.floor((bounds.y0 - y0) * invHeight * pageHeight))
      const j1 = Math.min(pageHeight - 1, Math.ceil((bounds.y1 - y0) * invHeight * pageHeight) - 1)

      const fade = fadeOf(entry)
      for (let j = j0; j <= j1; j++) {
        for (let i = i0; i <= i1; i++) {
          const index = j * pageWidth + i
          if (written[index]) {
            // Demote what was here. Coarsest-first ordering makes that the immediate fallback.
            const p = index * 4
            writePage(ancestorPage, index, finePage[p], finePage[p + 1], 1)
          }
          // With nothing underneath, fading in would be a fade from black — a flash, not a
          // transition. A page with no ancestor is drawn outright.
          writePage(finePage, index, entry.slot, tile.z, written[index] ? fade : 1)
          written[index] = 1
        }
      }
    }

    uploadPage(pageTexture, finePage)
    uploadPage(ancestorTexture, ancestorPage)
  }

  const uploadPage = (texture, data) => {
    gl.bindTexture(gl.TEXTURE_2D, texture)
    gl.texImage2D(
      gl.TEXTURE_2D, 0, gl.RGBA, pageWidth, pageHeight, 0,
      gl.RGBA, gl.UNSIGNED_BYTE, data,
    )
  }

  /**
   * Bind everything the GLSL below declares.
   *
   * @param {object} uniforms  locations for tiles/page/ancestor/extent/shortfall; nulls are fine,
   *                           since a program that never mentions one gets null from the compiler
   * @param {number} unit      first texture unit to use; three consecutive units are taken
   */
  const bind = (uniforms = {}, unit = 0) => {
    if (!supported || disposed) return
    const bindOne = (target, texture, location, offset) => {
      gl.activeTexture(gl.TEXTURE0 + unit + offset)
      gl.bindTexture(target, texture)
      if (location) gl.uniform1i(location, unit + offset)
    }
    bindOne(gl.TEXTURE_2D_ARRAY, tiles, uniforms.tiles, 0)
    bindOne(gl.TEXTURE_2D, pageTexture, uniforms.page, 1)
    bindOne(gl.TEXTURE_2D, ancestorTexture, uniforms.ancestor, 2)
    if (uniforms.extent) gl.uniform4f(uniforms.extent, ...extent)
    if (uniforms.shortfall) gl.uniform1f(uniforms.shortfall, shortfall)
  }

  return {
    supported,
    glsl: TILE_PYRAMID_GLSL,
    upload,
    slotOf,
    buildPages,
    bind,

    /** The tiles this frame is drawing with. Their slots are off limits until the next call. */
    retain(keys) { retained = new Set(keys) },

    /** How far selection fell short of the detail it wanted, 0..1. Reaches the shader as a uniform. */
    setShortfall(value) { shortfall = Math.min(1, Math.max(0, value)) },

    /** `[x0, y0, 1/width, 1/height]` — the mercator rectangle the page tables cover. */
    pageExtent: () => [...extent],

    /** The page grid's actual size, `[width, height]`. Sized per build, not fixed. */
    pageSize: () => [pageWidth, pageHeight],

    stats: () => ({
      supported,
      slots: slotCount,
      slotsUsed: resident.size,
      slotsFree: freeSlots.length,
      pageWidth,
      pageHeight,
      // False when the extent needed a finer page grid than the cap allows, so page texels straddle
      // tiles again and blocks near boundaries may be indexed to a neighbour. Surfaced rather than
      // silent, because the artefact looks like a tile-seam problem and is really an indexing one.
      pageAligned,
      atlasBytes: slotCount * tileSize * tileSize * 4,
    }),

    dispose() {
      if (disposed || !supported) { disposed = true; return }
      gl.deleteTexture(tiles)
      gl.deleteTexture(pageTexture)
      gl.deleteTexture(ancestorTexture)
      tiles = pageTexture = ancestorTexture = null
      resident.clear()
      freeSlots.length = 0
      retained = new Set()
      disposed = true
    },
  }
}

/** The mercator rectangle covering every tile in a set, as `[x0, y0, 1/width, 1/height]`. */
const mercatorExtentOf = (tiles) => {
  if (!tiles.length) return [0, 0, 1, 1]
  let x0 = 1
  let y0 = 1
  let x1 = 0
  let y1 = 0
  for (const tile of tiles) {
    const bounds = tileMercatorBounds(tile)
    x0 = Math.min(x0, bounds.x0)
    y0 = Math.min(y0, bounds.y0)
    x1 = Math.max(x1, bounds.x1)
    y1 = Math.max(y1, bounds.y1)
  }
  return [x0, y0, 1 / Math.max(1e-9, x1 - x0), 1 / Math.max(1e-9, y1 - y0)]
}

/**
 * What an overlay layer pastes into its fragment shader.
 *
 * GLSL ES 3.00. `tiledSample` takes mercator 0..1 — the space `buildSphereMesh` already lays every
 * vertex out in — so a layer that has `a_pos` has everything it needs.
 */
export const TILE_PYRAMID_GLSL = /* glsl */`
// GLSL ES 3.00 gives float and int a default precision but NOT sampler2DArray, so a shader that
// declares one without this fails to compile with "No precision specified" — and a custom layer
// whose program never links draws nothing, silently. It belongs here rather than in each consumer,
// because it is a property of the sampler this module introduces.
precision highp sampler2DArray;

uniform sampler2DArray u_tm_tiles;
uniform sampler2D u_tm_page;          // r = slot, g = level, b = fade
uniform sampler2D u_tm_pageAncestor;  // r = slot, g = level
uniform vec4 u_tm_pageExtent;         // x0, y0, 1/width, 1/height
uniform float u_tm_shortfall;         // 0 = the pyramid has the detail, 1 = it ran out

/** One tile of the array, at a point given in mercator 0..1. */
vec4 tm_sampleLayer(vec2 mercatorUV, float slot, float level) {
  return texture(u_tm_tiles, vec3(fract(mercatorUV * exp2(level)), slot));
}

/**
 * The pyramid, sampled at a mercator coordinate, cross-faded between LOD levels.
 *
 * The page table is NEAREST-filtered, so the slot and level read back exactly as written. The mix
 * is what stops a tile popping in over its parent — and the parent-fallback case, where a child
 * has not arrived, is the same expression with the fade at zero.
 */
vec4 tiledSample(vec2 mercatorUV) {
  vec2 page = clamp((mercatorUV - u_tm_pageExtent.xy) * u_tm_pageExtent.zw, 0.0, 1.0);
  vec4 fine = texture(u_tm_page, page);
  vec4 coarse = texture(u_tm_pageAncestor, page);
  vec4 near = tm_sampleLayer(mercatorUV, fine.r * 255.0, fine.g * 255.0);
  vec4 far = tm_sampleLayer(mercatorUV, coarse.r * 255.0, coarse.g * 255.0);
  return mix(far, near, fine.b);
}

/** The same, from a direction on the unit sphere — for anything shading in planet space. */
vec4 tiledSampleSphere(vec3 unitPos) {
  vec3 p = normalize(unitPos);
  float lat = asin(clamp(p.y, -1.0, 1.0));
  float lng = atan(p.z, p.x);
  return tiledSample(vec2(
    lng / 6.28318530718 + 0.5,
    0.5 - log(tan(0.78539816339 + lat * 0.5)) / 6.28318530718
  ));
}

/**
 * How far short of the detail it wanted the pyramid fell, 0..1.
 *
 * Past the source's last level there is no honest answer left, and this is the signal to bring in
 * procedural detail — measured, rather than guessed at from a hardcoded field resolution.
 */
float tiledShortfall() { return u_tm_shortfall; }
`
