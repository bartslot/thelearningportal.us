/**
 * elevation.mjs — a global elevation grid for terrain placement, built from free AWS Terrain Tiles
 * (Terrarium PNG encoding) at zoom Z. Decoded once with pngjs and cached to disk; thereafter loads
 * instantly. Provides elevationAt(lng,lat) and reliefAt(lng,lat) (local ruggedness).
 *
 *   const dem = await loadDem()
 *   dem.elevationAt(7.0, 48.0)   // metres (Vosges ≈ 700–1000)
 *   dem.reliefAt(7.0, 48.0, 3)   // max-min over a ~3px window
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { PNG } from 'pngjs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const Z = 4                                   // 16×16 tiles → 4096² grid, ~10 km/px
const CACHE = resolve(__dirname, '../../storage/app/elevation')
const TILE_URL = (z, x, y) => `https://s3.amazonaws.com/elevation-tiles-prod/terrarium/${z}/${x}/${y}.png`

/** Side of the square mercator grid a given tile zoom produces, in pixels. */
export const gridSizeForZoom = (zoom) => (1 << zoom) * 256

async function fetchTile (z, x, y, tries = 3) {
  for (let t = 0; t < tries; t++) {
    try {
      const res = await fetch(TILE_URL(z, x, y))
      if (res.ok) return Buffer.from(await res.arrayBuffer())
    } catch (_) { /* retry */ }
  }
  return null
}

async function buildGrid (zoom) {
  const n = 1 << zoom
  const size = gridSizeForZoom(zoom)
  const grid = new Int16Array(size * size)
  const jobs = []
  for (let ty = 0; ty < n; ty++) for (let tx = 0; tx < n; tx++) jobs.push([tx, ty])
  let done = 0
  const CONC = 8
  async function worker (queue) {
    for (;;) {
      const job = queue.pop(); if (!job) return
      const [tx, ty] = job
      const buf = await fetchTile(zoom, tx, ty)
      if (buf) {
        try {
          const png = PNG.sync.read(buf)
          for (let py = 0; py < 256; py++) {
            for (let px = 0; px < 256; px++) {
              const i = (py * 256 + px) * 4
              const e = png.data[i] * 256 + png.data[i + 1] + png.data[i + 2] / 256 - 32768
              grid[(ty * 256 + py) * size + (tx * 256 + px)] = Math.max(-1000, Math.min(9000, Math.round(e)))
            }
          }
        } catch (_) { /* leave as 0 */ }
      }
      if (++done % 32 === 0) process.stderr.write(`  dem z${zoom} ${done}/${jobs.length} tiles\r`)
    }
  }
  const queue = jobs.slice()
  await Promise.all(Array.from({ length: CONC }, () => worker(queue)))
  process.stderr.write('\n')
  mkdirSync(CACHE, { recursive: true })
  writeFileSync(resolve(CACHE, `dem-z${zoom}.bin`), Buffer.from(grid.buffer))
  return grid
}

/**
 * The raw square mercator height grid at a tile zoom, in metres, north-first.
 *
 * Cached to disk per zoom, because the fetch is thousands of tiles and the answer never changes.
 * Callers that want lng/lat lookups want `loadDem`; callers resampling the whole planet — the
 * relief normal map — want the grid itself.
 */
export async function loadHeightGrid (zoom = Z) {
  const bin = resolve(CACHE, `dem-z${zoom}.bin`)
  if (existsSync(bin)) return new Int16Array(readFileSync(bin).buffer.slice())
  console.error(`building elevation grid from terrain tiles at z${zoom} (one-time)…`)
  return buildGrid(zoom)
}

export async function loadDem () {
  const SIZE = gridSizeForZoom(Z)
  const grid = await loadHeightGrid(Z)

  // web-mercator lng/lat → grid pixel
  const lngToPx = (lng) => ((lng + 180) / 360) * SIZE
  const latToPx = (lat) => {
    const r = (lat * Math.PI) / 180
    return (0.5 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / (2 * Math.PI)) * SIZE
  }

  const at = (px, py) => {
    const x = Math.max(0, Math.min(SIZE - 1, Math.round(px)))
    const y = Math.max(0, Math.min(SIZE - 1, Math.round(py)))
    return grid[y * SIZE + x]
  }
  const elevationAt = (lng, lat) => at(lngToPx(lng), latToPx(lat))
  const reliefAt = (lng, lat, rPx = 3) => {
    const cx = lngToPx(lng), cy = latToPx(lat)
    let min = Infinity, max = -Infinity
    for (let dy = -rPx; dy <= rPx; dy++) {
      for (let dx = -rPx; dx <= rPx; dx++) {
        const e = at(cx + dx, cy + dy)
        if (e < min) min = e
        if (e > max) max = e
      }
    }
    return max - min
  }
  return { elevationAt, reliefAt, SIZE, Z }
}
