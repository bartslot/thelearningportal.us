/**
 * sources.js — what a tile actually contains.
 *
 * The first source is the DEM this project already uses: the AWS Open Data terrarium tiles behind
 * the atlas styles' hillshade (`map-imagery.js`). Real tiles at real LOD, keyless and free, to z12
 * — so mountain shadow and the ocean can both be built without baking a single global asset.
 *
 * A TILE CARRIES SIGNED HEIGHT AND NOTHING ELSE. That is a decision, not a default. Two consumers
 * want opposite halves of the same number: relief clamps to `max(h, 0)` because three kilometres of
 * water is opaque and shading the sea floor turns the Atlantic into a bathymetric chart, while the
 * ocean wants exactly what relief throws away — the negative half, as depth for its colour ramp.
 *
 * So the DEM is decoded ONCE per tile and each reader clamps for itself. Baking either clamp into
 * the tile is the trap: `max(h, 0)` would leave the ocean with a perfectly flat sea floor, no error
 * anywhere, and relief still looking exactly right. Nobody would find it.
 *
 * An earlier version of this file stored a normal in RGB and a shoreline ramp in A. Both are gone.
 * The ramp saturated at ±64 m, which destroys depth; and the normal is better derived in the shader
 * (see `tiledNormal` in atlas.js), where a tap that crosses a tile boundary is routed through the
 * page table into the neighbouring tile and the edge seam simply does not arise.
 */

import {
  MAX_ELEVATION_M,
  MIN_ELEVATION_M,
  decodeTerrarium as decodeTerrariumPixel,
} from '../terrain-normals.js'

export { MAX_ELEVATION_M, MIN_ELEVATION_M } from '../terrain-normals.js'

/** The span a stored height covers, in metres. Challenger Deep to Everest, with headroom. */
export const ELEVATION_RANGE_M = MAX_ELEVATION_M - MIN_ELEVATION_M

/**
 * Fixed point, three steps to the metre, with sea level ANCHORED ON A STEP.
 *
 * Spreading 16 bits evenly across the range is the obvious encoding and it puts sea level halfway
 * between two steps, so a flat ocean decodes to 14.5 cm rather than 0. Water still comes out flat —
 * every ocean texel lands in the same bucket, and a normal is a difference — but `height >= 0`
 * quietly calls the whole sea land, and that is the same family of uniform bias that put a constant
 * tilt on every flat texel of the baked normal map and survived every relative check.
 *
 * Anchoring costs nothing: 3 steps/m spans 21,845 m against the 20,500 m needed, so the precision
 * is 33 cm either way and zero is exactly zero.
 */
export const HEIGHT_STEPS_PER_M = 3
export const HEIGHT_ZERO_STEP = -MIN_ELEVATION_M * HEIGHT_STEPS_PER_M

/**
 * The elevation source, matching `DEM_SOURCE` in map-imagery.js.
 *
 * `height = (R*256 + G + B/256) - 32768` metres. z12 is the last level published, which is why
 * selection reports `starved` past it rather than asking for a tile that is not there.
 *
 * `channels: 'rg'` puts the tile in a two-channel texture rather than a four-channel one. Height
 * needs 16 bits and no more, and halving the atlas halves its VRAM — which is the budget that
 * decides how much of the world stays resident on a school Chromebook.
 */
export const TERRARIUM_TERRAIN = {
  id: 'terrarium-terrain',
  url: 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png',
  minzoom: 0,
  maxzoom: 12,
  tileSize: 256,
  channels: 'rg',
  attribution: 'Elevation: AWS Open Data terrain tiles (terrarium)',
}

export const tileUrl = (source, { z, x, y }) =>
  source.url.replace('{z}', z).replace('{x}', x).replace('{y}', y)

/**
 * Heights in metres, one per pixel, signed and unclamped.
 *
 * @param {Uint8ClampedArray} rgba  the tile's pixels, four bytes each
 * @param {number} size             texels along an edge
 * @returns {Float32Array}
 */
export const decodeTerrarium = (rgba, size) => {
  const heights = new Float32Array(size * size)
  for (let i = 0; i < heights.length; i++) {
    const p = i * 4
    heights[i] = decodeTerrariumPixel(rgba[p], rgba[p + 1], rgba[p + 2])
  }
  return heights
}

/**
 * Pack heights into the two bytes a tile stores them in.
 *
 * Sixteen bits across 20.5 km is a step of 31 cm — far finer than either consumer needs, and the
 * reason it matters is the ocean rather than the land: a depth field feeding a colour ramp bands
 * where a boolean mask never would, and banding in shallow water reads as contour rings.
 */
export const heightTextureFromHeights = (heights, size) => {
  const bytes = new Uint8Array(size * size * 2)
  const count = Math.min(heights.length, size * size)
  for (let i = 0; i < count; i++) {
    // The RANGE comes from terrain-normals.js so both halves of the fleet agree on it. The rounding
    // to whole metres that `clampTerrainHeight` also does is deliberately NOT used: that suits an
    // integer-metre grid, and here the same sixteen bits buy 33 cm for nothing. Whole-metre steps
    // would band a shallow-water colour ramp into contour rings.
    const step = Math.round(heights[i] * HEIGHT_STEPS_PER_M) + HEIGHT_ZERO_STEP
    const packed = Math.min(65535, Math.max(0, step))
    bytes[i * 2] = packed >> 8
    bytes[i * 2 + 1] = packed & 255
  }
  return bytes
}

/** The inverse, for tests and for anything reading a tile back on the CPU. */
export const decodeHeightTexel = (high, low) =>
  (high * 256 + low - HEIGHT_ZERO_STEP) / HEIGHT_STEPS_PER_M

/**
 * What the shader needs to undo the packing: metres at step zero, and metres per step.
 *
 * `height = x + raw * y`, where raw is the 16-bit value the two bytes carry.
 */
export const HEIGHT_DECODE = [-HEIGHT_ZERO_STEP / HEIGHT_STEPS_PER_M, 1 / HEIGHT_STEPS_PER_M]

/**
 * The decoder the cache is handed for this source: PNG bytes in, a tile-shaped height texture out.
 *
 * `createImageBitmap` rather than an `Image`, because it decodes off the main thread — one 256x256
 * PNG is small, but a burst of thirty on a pan is not, and blocking the render loop to decode is
 * the stutter this whole module exists to avoid.
 */
export const decodeTerrainTile = async (buffer, tile) => {
  const size = TERRARIUM_TERRAIN.tileSize
  const pixels = await rgbaFromPng(buffer, size)
  const data = heightTextureFromHeights(decodeTerrarium(pixels, size), size)
  return { data, bytes: data.byteLength }
}

/** PNG bytes to raw pixels, via the browser's own decoder. */
const rgbaFromPng = async (buffer, size) => {
  const bitmap = await createImageBitmap(new Blob([buffer], { type: 'image/png' }))
  const canvas = new OffscreenCanvas(size, size)
  const context = canvas.getContext('2d', { willReadFrequently: true })
  context.drawImage(bitmap, 0, 0, size, size)
  bitmap.close()
  return context.getImageData(0, 0, size, size).data
}
