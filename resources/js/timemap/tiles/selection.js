/**
 * selection.js — which tiles, at which level, for the view we are actually looking at.
 *
 * The failure this replaces is always the same one: a global texture has exactly one level, so as
 * the camera descends the ground keeps getting closer and the texture does not. Selection is the
 * answer, and the measure it uses is SCREEN-SPACE ERROR — how many screen pixels one texel is
 * being stretched across. A texel covering one pixel is sharp; a texel covering four is the milky
 * wash the cloud deck turns into past z8.
 *
 * WHY NOT A ZOOM TABLE. `level = zoom + 1` is right in one case: flat, overhead, tile centred. It
 * is wrong at the limb of the globe, where a tile is seen almost edge-on and lands on a third of
 * the pixels, and refining it further buys detail nobody can see and spends atlas slots doing it.
 * Measuring the footprint costs a dozen multiplies and is right everywhere, so the metric is the
 * thing and the zoom table is a special case of it — pinned as such in the tests.
 *
 * Pure: a plain camera description in, a list of tiles out. No MapLibre, no GL, no fetching. The
 * adapter that turns a real map into this camera lives in index.js, which keeps the interesting
 * arithmetic testable without a browser.
 */

import { EARTH_RADIUS_M, mercatorYFromLat, planetSpacePosition } from '../planet-mesh.js'
import {
  MERCATOR_EDGE_LAT,
  groundMetresPerScreenPixel,
  groundMetresPerTexel,
  keyOf,
  tileLngLatBounds,
  tileMercatorBounds,
  tilesPerAxis,
  wrapTileX,
} from './scheme.js'

/** MapLibre's default vertical field of view, in radians. Matches `args.fov` on a v5 render. */
const DEFAULT_FOV = 0.6435

/**
 * How far a tile is allowed to be foreshortened before we stop believing the number.
 *
 * Incidence goes to zero exactly at the horizon, and a factor of zero says "infinitely blurry,
 * refine forever". The floor keeps limb tiles coarse without letting them run away.
 */
const MIN_INCIDENCE = 0.15

const clamp = (value, low, high) => Math.min(high, Math.max(low, value))

/** The centre of a tile, in degrees. */
const tileCentre = (tile) => {
  const { west, east, north, south } = tileLngLatBounds(tile)
  return { lng: (west + east) / 2, lat: (north + south) / 2 }
}

/**
 * The camera as a point on and above the planet, in the same space `planetSpacePosition` uses.
 *
 * Altitude comes from the zoom when it is not supplied, by the same route planet-mesh takes when
 * MapLibre will not answer: the camera sits `0.5·height/tan(fov/2)` screen pixels from the centre,
 * and a screen pixel is a known number of metres at this zoom and latitude.
 */
const cameraGeometry = (camera) => {
  const fov = camera.fov ?? DEFAULT_FOV
  const focalPixels = (0.5 * camera.canvasHeight) / Math.tan(fov / 2)
  const metresPerPixel = groundMetresPerScreenPixel(camera.zoom, camera.center.lat)
  const altitude = camera.altitudeMetres ?? focalPixels * metresPerPixel
  return {
    fov,
    focalPixels,
    metresPerPixel,
    altitude,
    position: planetSpacePosition(camera.center.lng, camera.center.lat, altitude),
    // Earth radii from the planet's centre. The horizon of a unit sphere seen from here is exactly
    // the plane dot(p, position) = 1, which is the same test FACING_CAMERA_GLSL makes in the shader.
    radii: 1 + altitude / EARTH_RADIUS_M,
  }
}

const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2]

/**
 * How many screen pixels one of this tile's texels covers.
 *
 * Above 1 the tile is being magnified and looks soft; below 1 it is being minified and we are
 * paying for detail that never reaches the screen. Selection refines while this is above the
 * target, which is how the level ends up tracking the camera instead of a table.
 */
export const pixelsPerTexel = (tile, camera, source) => {
  const centre = tileCentre(tile)
  const metresPerTexel = groundMetresPerTexel(tile.z, centre.lat, source.tileSize)

  if (camera.projection !== 'globe') {
    // Flat and overhead: the ratio of two ground resolutions, and the anchor every other case is
    // checked against. Pitch would foreshorten distant ground further; the globe path below is
    // where that actually matters on this map.
    return metresPerTexel / groundMetresPerScreenPixel(camera.zoom, centre.lat)
  }

  const geometry = cameraGeometry(camera)
  const surface = planetSpacePosition(centre.lng, centre.lat, 0)
  const toCamera = [
    geometry.position[0] - surface[0],
    geometry.position[1] - surface[1],
    geometry.position[2] - surface[2],
  ]
  const distance = Math.hypot(...toCamera) || 1e-6
  // The surface normal of a unit sphere at p IS p, so the cosine of the incidence angle is this.
  const incidence = Math.max(MIN_INCIDENCE, dot(surface, toCamera) / distance)

  // Pixels per earth radius at that distance, times the tile's texel size in earth radii, times
  // how much of it survives being seen at an angle.
  const texelRadii = metresPerTexel / EARTH_RADIUS_M
  return ((geometry.focalPixels * texelRadii) / distance) * incidence
}

/**
 * Is any of this tile on the half of the globe facing the camera?
 *
 * The test is made at the point of the tile NEAREST the camera, and that has to be found properly
 * rather than sampled. Corners-and-centre is the obvious shortcut and it is wrong in the one case
 * that matters: at z0 the tile is the whole world, its five sample points are all thousands of
 * kilometres from a camera over Amsterdam, every one of them fails, and the pyramid culls the tile
 * it is standing on — an empty selection at every zoom above about 6, with nothing to explain it.
 *
 * For a latitude/longitude rectangle the nearest point is found by clamping each coordinate
 * independently, and because `dot(p, camera) = |camera|·cos(angular distance)`, nearest is also the
 * maximum — so one exact point settles the whole tile. Longitude is tried on both sides of the
 * seam, since a tile just west of the antimeridian is next door to a camera just east of it.
 */
const facesCamera = (tile, geometry, camera) => {
  const { west, east, north, south } = tileLngLatBounds(tile)
  const lat = clamp(camera.center.lat, south, north)
  for (const offset of [0, 360, -360]) {
    const lng = clamp(camera.center.lng + offset, west, east)
    if (dot(planetSpacePosition(lng, lat, 0), geometry.position) > 1) return true
  }
  return false
}

/**
 * Does the tile touch the viewport rectangle?
 *
 * Longitude wraps, so the horizontal test is made on the shortest way round rather than on raw
 * coordinates — otherwise a camera at 179.7°E culls everything just across the seam, which is
 * precisely the ground it is looking at.
 */
const withinExtent = (tile, extent, padding) => {
  if (!extent) return true
  const { x0, y0, x1, y1 } = tileMercatorBounds(tile)
  const tileCx = (x0 + x1) / 2
  const tileHalfX = (x1 - x0) / 2
  let dx = Math.abs(tileCx - extent.cx)
  if (dx > 0.5) dx = 1 - dx
  if (dx > extent.halfWidth + tileHalfX + padding) return false
  const dy = Math.abs((y0 + y1) / 2 - extent.cy)
  return dy <= extent.halfHeight + (y1 - y0) / 2 + padding
}

/**
 * Choose the tile set for a view.
 *
 * Greedy refinement: start from the source's coarsest level and repeatedly split whichever visible
 * tile is the blurriest, until every tile is sharp enough, the source runs out of levels, or the
 * budget is spent. Splitting the worst first is what makes a partial budget degrade gracefully —
 * the tiles you can afford are the ones that were hurting most.
 *
 * @param {object} camera   {center:{lng,lat}, zoom, canvasWidth, canvasHeight, projection, fov?, altitudeMetres?}
 * @param {object} source   {minzoom, maxzoom, tileSize}
 * @param {object} [options]
 * @param {number} [options.targetPixelsPerTexel]  1 means one texel per screen pixel
 * @param {number} [options.maxTiles]              hard ceiling on the set
 * @param {number} [options.padding]               extra mercator margin, to prefetch just off-screen
 */
export const selectTiles = (camera, source, options = {}) => {
  const { targetPixelsPerTexel = 1, maxTiles = 64, padding = 0 } = options
  const minzoom = source.minzoom ?? 0
  const maxzoom = source.maxzoom ?? 12

  const geometry = camera.projection === 'globe' ? cameraGeometry(camera) : null
  const extent = viewportExtentFor(camera)

  const visible = (tile) => {
    if (geometry && !facesCamera(tile, geometry, camera)) return false
    return withinExtent(tile, extent, padding)
  }

  /**
   * The starting set: the visible tiles at the source's coarsest level.
   *
   * Reached by walking DOWN from z0 and culling at every step, rather than enumerating the level
   * directly. The direct loop is the obvious version and it is a landmine — a source with minzoom 8
   * (plenty of WMTS layers have one) would iterate 65,536 tiles on every frame, and minzoom 12
   * would iterate sixteen million. Walking down costs only what is visible.
   */
  let seed = [{ z: 0, x: 0, y: 0 }]
  for (let level = 0; level < minzoom; level++) {
    seed = seed.flatMap((tile) => childTiles(tile).filter(visible))
    if (!seed.length) break
  }
  seed = seed.filter(visible)
  // A camera that sees nothing (below the surface, or a degenerate canvas) still gets the tile it
  // is standing on, so consumers never have to handle an empty pyramid as a special case.
  if (!seed.length) seed.push({ z: minzoom, x: 0, y: 0 })

  let chosen = seed.map((tile) => ({ ...tile, error: pixelsPerTexel(tile, camera, source) }))

  /**
   * The seed can be over budget on its own, and the refinement loop below would never notice —
   * it only ever checks before splitting. A source whose coarsest level is deep enough to fill the
   * screen hands back its whole visible grid, measured at 77 tiles against a budget of 64.
   *
   * Blurriest first, as everywhere else here: those are the tiles nearest the camera.
   */
  if (chosen.length > maxTiles) {
    chosen = [...chosen].sort((a, b) => b.error - a.error).slice(0, maxTiles)
  }

  let starved = false

  while (chosen.length + 3 <= maxTiles) {
    let worst = -1
    for (let i = 0; i < chosen.length; i++) {
      if (chosen[i].error <= targetPixelsPerTexel) continue
      if (chosen[i].z >= maxzoom) { starved = true; continue }
      if (worst < 0 || chosen[i].error > chosen[worst].error) worst = i
    }
    if (worst < 0) break

    const parent = chosen[worst]
    const kids = []
    for (const kid of childTiles(parent)) {
      if (!visible(kid)) continue
      kids.push({ ...kid, error: pixelsPerTexel(kid, camera, source) })
    }
    // Immutable replacement: a new array rather than a splice, so a caller holding the previous
    // selection (to diff against for cache retention) still has it.
    chosen = [...chosen.slice(0, worst), ...kids, ...chosen.slice(worst + 1)]
  }

  // Anything still too blurry at the last level is the source running out, not the budget.
  if (chosen.some((tile) => tile.error > targetPixelsPerTexel && tile.z >= maxzoom)) starved = true

  const tiles = chosen.map(({ z, x, y, error }) => ({ z, x, y, key: keyOf({ z, x, y }), pixelsPerTexel: error }))
  return {
    tiles,
    tileCount: tiles.length,
    maxLevel: tiles.reduce((max, tile) => Math.max(max, tile.z), minzoom),
    minLevel: tiles.reduce((min, tile) => Math.min(min, tile.z), maxzoom),
    worstPixelsPerTexel: tiles.reduce((max, tile) => Math.max(max, tile.pixelsPerTexel), 0),
    starved,
  }
}

/** Children, with x wrapped — a tile on the last column has children on the first. */
const childTiles = ({ z, x, y }) => {
  const level = z + 1
  return [
    { z: level, x: wrapTileX(x * 2, level), y: y * 2 },
    { z: level, x: wrapTileX(x * 2 + 1, level), y: y * 2 },
    { z: level, x: wrapTileX(x * 2, level), y: y * 2 + 1 },
    { z: level, x: wrapTileX(x * 2 + 1, level), y: y * 2 + 1 },
  ]
}

/**
 * The viewport as a mercator rectangle, or null when the view is wider than the world.
 *
 * Null is the honest answer at globe zooms: the flat formula says the view spans several worlds,
 * which is not a rectangle to cull against. There the horizon test does the work on its own.
 *
 * The latitude is clamped into the square before conversion, because mercator y diverges at the
 * pole — an unclamped camera at 89.9° gets an extent centred somewhere off the bottom of the
 * world and culls every tile it can actually see.
 */
const viewportExtentFor = (camera) => {
  const world = 512 * 2 ** camera.zoom
  const halfWidth = camera.canvasWidth / 2 / world
  const halfHeight = camera.canvasHeight / 2 / world
  if (halfWidth >= 0.5 || halfHeight >= 0.5) return null
  return {
    cx: (camera.center.lng + 180) / 360,
    cy: mercatorYFromLat(clamp(camera.center.lat, -MERCATOR_EDGE_LAT, MERCATOR_EDGE_LAT)),
    halfWidth,
    halfHeight,
  }
}
