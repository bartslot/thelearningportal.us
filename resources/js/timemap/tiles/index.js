/**
 * index.js — the tile pyramid, as one object an overlay layer can hold.
 *
 * Clouds, ocean and mountain shadow each hit the same wall: a single global texture, magnified
 * until it falls apart. This is the shared answer. Four calls, in the four places a MapLibre custom
 * layer already has:
 *
 *     const pyramid = createTilePyramid({ source: TERRARIUM_TERRAIN })
 *     onAdd(map, gl)  -> pyramid.onAdd(gl)
 *     render(gl, args) -> pyramid.update(cameraFromMap(map, args))
 *                         pyramid.bind(uniforms, firstTextureUnit)
 *     onRemove()      -> pyramid.onRemove()
 *
 * and `pyramid.glsl` pasted into the fragment shader, which gives `tiledSample(vec2 mercatorUV)`.
 *
 * NOTHING HERE AWAITS. `update` is called from inside a render, so it starts work and returns; the
 * tiles it asked for turn up over the next few frames and are picked up by the updates after this
 * one. A pyramid that has not finished loading draws the coarser tiles it does have, which is what
 * the ancestor page table is for — there is never a frame with nothing to show.
 */

import { TILE_PYRAMID_GLSL, createTileAtlas } from './atlas.js'
import { cacheApiStore, createTileCache, memoryStore } from './cache.js'
import { selectTiles } from './selection.js'
import { HEIGHT_DECODE, TERRARIUM_TERRAIN, decodeTerrainTile, tileUrl } from './sources.js'

export {
  ELEVATION_RANGE_M, HEIGHT_DECODE, HEIGHT_STEPS_PER_M, MAX_ELEVATION_M, MIN_ELEVATION_M,
  TERRARIUM_TERRAIN, decodeHeightTexel, decodeTerrainTile, decodeTerrarium,
  heightTextureFromHeights, tileUrl,
} from './sources.js'
export { selectTiles, pixelsPerTexel, magnificationOf } from './selection.js'
export { createTileCache, cacheApiStore, memoryStore } from './cache.js'
export { createTileAtlas, TILE_PYRAMID_GLSL } from './atlas.js'
export {
  coastTerrain, flatTerrain, oceanTerrain, ridgeTerrain, syntheticSource, syntheticTerrain,
} from './fixtures.js'
export * from './scheme.js'

/**
 * The camera, from a map and the arguments its render was handed.
 *
 * The projection comes from `shaderData.variantName` rather than from the map, because that is what
 * the frame being drawn is actually using — the variant flips mid-flight as the map crosses the
 * globe/mercator threshold, and asking the map is a frame behind.
 */
export const cameraFromMap = (map, args = {}) => {
  const canvas = map.getCanvas()
  const altitude = map.transform?.getCameraAltitude?.()
  return {
    center: map.getCenter(),
    zoom: map.getZoom(),
    canvasWidth: canvas.width,
    canvasHeight: canvas.height,
    projection: args.shaderData?.variantName === 'globe' ? 'globe' : 'mercator',
    fov: args.fov,
    // Null, not a fallback: selection derives an altitude from the zoom, and a wrong number here
    // would be silently believed.
    altitudeMetres: Number.isFinite(altitude) ? altitude : null,
  }
}

/**
 * @param {object} opts
 * @param {object} [opts.source]                tile source descriptor; the DEM by default
 * @param {number} [opts.budgetBytes]           decoded bytes held in memory
 * @param {number} [opts.layers]                tiles resident on the GPU
 * @param {number} [opts.maxTiles]              ceiling on one frame's selection
 * @param {number} [opts.targetPixelsPerTexel]  1 means one texel per screen pixel
 * @param {object} [opts.store]                 persistent store; the Cache API by default
 * @param {Function} [opts.fetch]               injected for tests
 * @param {Function} [opts.decode]              injected for tests, or to read a different format
 */
export const createTilePyramid = ({
  source = TERRARIUM_TERRAIN,
  budgetBytes = 96 << 20,
  layers = 128,
  maxTiles = 64,
  targetPixelsPerTexel = 1,
  pageResolution = 32,
  store = null,
  fetch: fetchBytes = undefined,
  decode = decodeTerrainTile,
} = {}) => {
  let gl = null
  let atlas = null
  let report = null
  let shortfall = 0
  let resident = 0
  const pending = new Set()
  const inFlight = new Set()

  const cache = createTileCache({
    budgetBytes,
    // Lazy, because `caches` is missing in some private-browsing modes and absent under test.
    store: store ?? (globalThis.caches ? cacheApiStore(`tm-tiles-${source.id}`) : memoryStore()),
    fetch: fetchBytes,
    decode,
    urlFor: (tile) => tileUrl(source, tile),
  })

  /**
   * Ask for a tile, and put it in the atlas when it turns up.
   *
   * Deliberately not awaited by `update`. The promise is kept only so tests and any future preload
   * can wait for quiet; a render never does.
   */
  const request = (tile) => {
    if (pending.has(tile.key)) return
    pending.add(tile.key)
    const done = cache.get(tile).then((data) => {
      if (data && atlas) atlas.upload(tile, data)
    }).finally(() => pending.delete(tile.key))
    inFlight.add(done)
    done.finally(() => inFlight.delete(done))
  }

  return {
    get supported() { return atlas ? atlas.supported : false },
    /** What to paste into the fragment shader. Available before `onAdd`, since it is just text. */
    glsl: TILE_PYRAMID_GLSL,

    onAdd(glContext) {
      gl = glContext
      atlas = createTileAtlas(gl, {
        tileSize: source.tileSize,
        layers,
        pageResolution,
        channels: source.channels ?? 'rgba',
        heightRange: HEIGHT_DECODE,
      })
    },

    /**
     * Choose this frame's tiles, start fetching what is missing, and rebuild the page tables.
     *
     * Cheap and idempotent: calling it every frame is the intended use. The only work that scales
     * is the page-table write, which is a few kilobytes.
     */
    update(camera) {
      if (!atlas?.supported) return null

      report = selectTiles(camera, source, { targetPixelsPerTexel, maxTiles })
      const keys = report.tiles.map((tile) => tile.key)

      // Pin before anything else: whatever this frame draws with must survive the eviction that
      // admitting new tiles is about to cause.
      cache.pin(keys)
      atlas.retain(keys)

      for (const tile of report.tiles) {
        if (atlas.slotOf(tile.key) >= 0) continue
        // Already decoded and merely evicted from the GPU: no need to go round the network again.
        const ready = cache.peek(tile.key)
        if (ready) atlas.upload(tile, ready)
        else request(tile)
      }

      // Only starvation counts. Blur because the budget ran out is this frame's problem and will
      // fix itself; blur because the source has no more levels never will, and that is the one a
      // layer should answer with procedural detail.
      shortfall = report.starved
        ? Math.min(1, Math.max(0, 1 - targetPixelsPerTexel / Math.max(targetPixelsPerTexel, report.worstPixelsPerTexel)))
        : 0
      atlas.setShortfall(shortfall)
      atlas.buildPages(report.tiles)
      // How much of this frame's selection is actually on the GPU. Below 1 the picture is real but
      // partly drawn from ancestors — worth knowing before concluding anything from what is on
      // screen, and the only honest signal that a view has finished arriving.
      resident = report.tiles.reduce((count, tile) => count + (atlas.slotOf(tile.key) >= 0 ? 1 : 0), 0)
      return report
    },

    /** Bind the atlas and page tables. Three consecutive texture units from `unit`. */
    bind(uniforms, unit = 0) { atlas?.bind(uniforms, unit) },

    /** Settles once every tile asked for so far has arrived or failed. For tests and preloading. */
    async idle() { while (inFlight.size) await Promise.all([...inFlight]) },

    /** How many tiles of each level the last selection chose. Diagnostics only. */
    lastLevels() {
      const counts = {}
      report?.tiles.forEach((tile) => { counts[tile.z] = (counts[tile.z] ?? 0) + 1 })
      return counts
    },

    stats: () => ({
      ...cache.stats(),
      ...(atlas?.stats() ?? {}),
      tileCount: report?.tileCount ?? 0,
      maxLevel: report?.maxLevel ?? 0,
      minLevel: report?.minLevel ?? 0,
      worstPixelsPerTexel: report?.worstPixelsPerTexel ?? 0,
      starved: report?.starved ?? false,
      shortfall,
      selectedResident: resident,
      /** 1 when every tile this frame wants is on the GPU. Below 1, ancestors are filling in. */
      completeness: report?.tileCount ? resident / report.tileCount : 0,
    }),

    onRemove() {
      atlas?.dispose()
      cache.clear()
      atlas = null
      gl = null
      report = null
    },
  }
}
