/**
 * scheme.js — where a tile is, in the one coordinate system everything else on this map agrees on.
 *
 * This is the web-mercator z/x/y scheme, the same one MapLibre uses for its own raster tiles and
 * the same one the AWS terrarium DEM is published in. That is not a preference, it is the point:
 * our overlay tiles have to land on exactly the same ground as the imagery underneath them, and a
 * scheme of our own — however tidy — would mean resampling every tile and living with a permanent
 * half-texel offset that reads as a seam the moment anyone zooms in.
 *
 * Pure arithmetic. No GL, no network, no MapLibre import. Everything above this file depends on it
 * and it depends on nothing, which is what makes the rest testable.
 *
 * THE ONE SUBTLETY IS THE RADIUS. planet-mesh.js uses the earth's MEAN radius (6371008.8 m) because
 * it is drawing a sphere. Web mercator is defined on the WGS84 semi-major axis (6378137 m) instead,
 * and the two differ by about 0.11% — 7 km at the equator, which is thirty z12 tiles. Tiles must
 * use the mercator figure or they do not line up with anybody's imagery, so it is spelled out here
 * rather than imported from a file that means something else by "radius".
 */

import { MERCATOR_EDGE_LAT, latFromMercatorY, mercatorYFromLat } from '../planet-mesh.js'

/** WGS84 semi-major axis, the radius web mercator is defined on. Not the sphere's mean radius. */
export const MERCATOR_RADIUS_M = 6378137

/** One trip round the equator in mercator's own terms: the width of the whole tile square in metres. */
export const MERCATOR_CIRCUMFERENCE_M = 2 * Math.PI * MERCATOR_RADIUS_M

export { MERCATOR_EDGE_LAT }

/** How many tiles across the world is at this level. */
export const tilesPerAxis = (z) => 2 ** z

/** Is this latitude outside the square the tile grid covers? Everything past it is the polar cap. */
export const latitudeIsCapped = (lat) => Math.abs(lat) > MERCATOR_EDGE_LAT

const clamp = (value, low, high) => Math.min(high, Math.max(low, value))

/**
 * Longitude wraps; latitude does not.
 *
 * A globe camera over the Pacific sees tiles on both sides of the antimeridian, and the naive
 * column index there is negative or past the last column. Wrapping is the correct answer for x and
 * the wrong one for y — wrapping y folds the Arctic onto the Antarctic, which is the same mistake
 * as REPEAT on an equirectangular texture's T axis.
 */
export const wrapTileX = (x, z) => {
  const span = tilesPerAxis(z)
  return ((x % span) + span) % span
}

/**
 * The tile containing a point.
 *
 * `capped` is not decoration. Past ±85.0511° there is no tile to return, so this hands back the
 * edge row — and a caller that does not know it has been clamped will happily stretch one row of
 * texels across the last five degrees of Antarctica and never find out why the pole looks smeared.
 */
export const tileForLngLat = (lng, lat, z) => {
  const span = tilesPerAxis(z)
  const capped = latitudeIsCapped(lat)
  const my = mercatorYFromLat(clamp(lat, -MERCATOR_EDGE_LAT, MERCATOR_EDGE_LAT))
  return {
    z,
    x: wrapTileX(Math.floor(((lng + 180) / 360) * span), z),
    y: clamp(Math.floor(my * span), 0, span - 1),
    capped,
  }
}

/** The tile's corner in mercator 0..1, the space `buildSphereMesh` lays its vertices out in. */
export const tileMercatorBounds = ({ z, x, y }) => {
  const size = 1 / tilesPerAxis(z)
  return { x0: x * size, y0: y * size, x1: (x + 1) * size, y1: (y + 1) * size, size }
}

/** The ground the tile covers, in degrees. */
export const tileLngLatBounds = (tile) => {
  const { x0, y0, x1, y1 } = tileMercatorBounds(tile)
  return {
    west: x0 * 360 - 180,
    east: x1 * 360 - 180,
    north: latFromMercatorY(y0),
    south: latFromMercatorY(y1),
  }
}

/** A tile as a string, for map keys and cache keys. `z/x/y`, the same order every tile URL uses. */
export const keyOf = ({ z, x, y }) => `${z}/${x}/${y}`

export const parseKey = (key) => {
  const [z, x, y] = key.split('/').map(Number)
  return { z, x, y }
}

export const parentOf = ({ z, x, y }) => (z <= 0 ? null : { z: z - 1, x: x >> 1, y: y >> 1 })

export const childrenOf = ({ z, x, y }) => [
  { z: z + 1, x: x * 2, y: y * 2 },
  { z: z + 1, x: x * 2 + 1, y: y * 2 },
  { z: z + 1, x: x * 2, y: y * 2 + 1 },
  { z: z + 1, x: x * 2 + 1, y: y * 2 + 1 },
]

/**
 * How much ground one texel of a tile covers, in metres.
 *
 * The number this whole system exists to drive down. Selection compares it against how much ground
 * one SCREEN pixel covers, and refines while the texel is losing.
 *
 * @param {number} z         tile level
 * @param {number} lat       latitude, because mercator stretches toward the poles
 * @param {number} tileSize  texels along a tile's edge — 256 for the DEM, 512 for most imagery
 */
export const groundMetresPerTexel = (z, lat, tileSize = 256) =>
  (MERCATOR_CIRCUMFERENCE_M * Math.cos((lat * Math.PI) / 180)) / (tileSize * tilesPerAxis(z))

/**
 * How much ground one screen pixel covers, at MapLibre's zoom.
 *
 * MapLibre's zoom is defined on 512-pixel tiles, so z and the tile level line up one for one only
 * when the source is also 512. This is the standard resolution formula and the anchor for the
 * screen-space error metric in selection.js.
 */
export const groundMetresPerScreenPixel = (zoom, lat) =>
  (MERCATOR_CIRCUMFERENCE_M * Math.cos((lat * Math.PI) / 180)) / (512 * 2 ** zoom)
