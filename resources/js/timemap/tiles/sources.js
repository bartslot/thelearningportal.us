/**
 * sources.js — what a tile actually contains, and how it turns into something a shader can sample.
 *
 * The first source is the DEM this project already uses: the AWS Open Data terrarium tiles behind
 * the atlas styles' hillshade (`map-imagery.js`). That choice is deliberate rather than convenient.
 * They are real tiles at real LOD, keyless and free, published to z12 — which means the mountain
 * shadow and the ocean coastline can both be built without baking a single global asset.
 *
 * ONE TILE, TWO CONSUMERS. Mountain shadow wants a normal map; the ocean wants to know where the
 * water is. Both answers come out of the same decoded heights, so they are packed into one RGBA
 * tile — normal in RGB, shoreline in A — rather than fetched and decoded twice. Halving the
 * requests matters more here than two tidier names would.
 */

import { groundMetresPerTexel } from './scheme.js'

/** Where the ramp in the alpha channel saturates, either side of sea level. */
export const SEA_BAND_M = 64

/**
 * The elevation source, matching `DEM_SOURCE` in map-imagery.js.
 *
 * `height = (R*256 + G + B/256) - 32768` metres. z12 is the last level published, which is why
 * selection reports `starved` past it rather than asking for a tile that is not there.
 */
export const TERRARIUM_TERRAIN = {
  id: 'terrarium-terrain',
  url: 'https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png',
  minzoom: 0,
  maxzoom: 12,
  tileSize: 256,
  attribution: 'Elevation: AWS Open Data terrain tiles (terrarium)',
}

export const tileUrl = (source, { z, x, y }) =>
  source.url.replace('{z}', z).replace('{x}', x).replace('{y}', y)

/**
 * Heights in metres, one per pixel.
 *
 * @param {Uint8ClampedArray} rgba  the tile's pixels, four bytes each
 * @param {number} size             texels along an edge
 * @returns {Float32Array}
 */
export const decodeTerrarium = (rgba, size) => {
  const heights = new Float32Array(size * size)
  for (let i = 0; i < heights.length; i++) {
    const p = i * 4
    heights[i] = rgba[p] * 256 + rgba[p + 1] + rgba[p + 2] / 256 - 32768
  }
  return heights
}

/**
 * Where the shoreline is, as a number that survives being filtered.
 *
 * A hard land/sea mask is what makes the current baked mask step along every coast: linear
 * filtering across a binary edge puts the shoreline at the texel boundary rather than where the
 * land actually stops, and the step gets a texel wider every time you zoom in. A ramp that is
 * LINEAR IN METRES interpolates to the true zero crossing instead — the same reason the ocean
 * layer's distance field had to stay linear rather than take a square root.
 */
export const seaLevelRamp = (height) => Math.min(1, Math.max(0, 0.5 + height / (2 * SEA_BAND_M)))

const clamp = (value, low, high) => Math.min(high, Math.max(low, value))

/**
 * A tile's heights as a packed RGBA texture: surface normal in RGB, shoreline in A.
 *
 * THE SPACING IS NOT OPTIONAL. A 50 m rise across one texel is a gentle slope at z8, where a texel
 * is 611 m of ground, and a cliff at z12, where it is 38 m. Differencing heights without dividing
 * by the tile's own resolution makes every level look like a different planet — and it stays
 * invisible until two levels are on screen together, which is exactly when cross-level blending
 * puts them there.
 *
 * Edges clamp. Terrarium tiles do not overlap, so an edge texel is missing one neighbour row; the
 * cost is a half-texel seam, which at z12 is 19 m and has never been visible. Fetching the eight
 * neighbours per tile would remove it at eight times the requests.
 *
 * @param {Float32Array} heights   metres, `size * size` of them
 * @param {number} size            texels along an edge
 * @param {number} metresPerTexel  how much ground one texel covers — see `groundMetresPerTexel`
 * @returns {Uint8ClampedArray}    RGBA, ready for texSubImage3D
 */
export const terrainTextureFromHeights = (heights, size, metresPerTexel) => {
  const rgba = new Uint8ClampedArray(size * size * 4)
  const spacing = Math.max(1e-6, metresPerTexel)

  const heightAt = (x, y) => heights[clamp(y, 0, size - 1) * size + clamp(x, 0, size - 1)]

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      // Central differences. Ground climbing eastward leans its normal west, hence the subtraction
      // in this order; the same for south and +y, which points south in tile space.
      const dx = (heightAt(x - 1, y) - heightAt(x + 1, y)) / (2 * spacing)
      const dy = (heightAt(x, y - 1) - heightAt(x, y + 1)) / (2 * spacing)
      const length = Math.hypot(dx, dy, 1)

      const i = (y * size + x) * 4
      rgba[i] = ((dx / length) * 0.5 + 0.5) * 255
      rgba[i + 1] = ((dy / length) * 0.5 + 0.5) * 255
      rgba[i + 2] = ((1 / length) * 0.5 + 0.5) * 255
      rgba[i + 3] = seaLevelRamp(heightAt(x, y)) * 255
    }
  }
  return rgba
}

/**
 * The decoder the cache is handed for this source: PNG bytes in, a tile-shaped RGBA texture out.
 *
 * `createImageBitmap` is used rather than an `Image`, because it decodes off the main thread — a
 * 256x256 PNG is small, but a burst of thirty of them on a pan is not, and blocking the render
 * loop to decode is exactly the stutter this whole module exists to avoid.
 */
export const decodeTerrainTile = async (buffer, tile) => {
  const size = TERRARIUM_TERRAIN.tileSize
  const pixels = await rgbaFromPng(buffer, size)
  const heights = decodeTerrarium(pixels, size)
  const centreLat = tileCentreLatitude(tile)
  const data = terrainTextureFromHeights(heights, size, groundMetresPerTexel(tile.z, centreLat, size))
  return { data, bytes: data.byteLength }
}

/** The latitude a tile's normals should be scaled at — its centre, where the error is smallest. */
const tileCentreLatitude = ({ z, y }) => {
  const span = 2 ** z
  const my = (y + 0.5) / span
  return ((2 * Math.atan(Math.exp((1 - 2 * my) * Math.PI)) - Math.PI / 2) * 180) / Math.PI
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
