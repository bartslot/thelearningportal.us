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
const N = 1 << Z
const SIZE = N * 256
const CACHE = resolve(__dirname, '../../storage/app/elevation')
const BIN = resolve(CACHE, `dem-z${Z}.bin`)
const TILE_URL = (x, y) => `https://s3.amazonaws.com/elevation-tiles-prod/terrarium/${Z}/${x}/${y}.png`

// web-mercator lng/lat → grid pixel
const lngToPx = (lng) => ((lng + 180) / 360) * SIZE
const latToPx = (lat) => {
  const r = (lat * Math.PI) / 180
  return (0.5 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / (2 * Math.PI)) * SIZE
}

async function fetchTile (x, y, tries = 3) {
  for (let t = 0; t < tries; t++) {
    try {
      const res = await fetch(TILE_URL(x, y))
      if (res.ok) return Buffer.from(await res.arrayBuffer())
    } catch (_) { /* retry */ }
  }
  return null
}

async function buildGrid () {
  const grid = new Int16Array(SIZE * SIZE)
  const jobs = []
  for (let ty = 0; ty < N; ty++) for (let tx = 0; tx < N; tx++) jobs.push([tx, ty])
  let done = 0
  const CONC = 8
  async function worker (queue) {
    for (;;) {
      const job = queue.pop(); if (!job) return
      const [tx, ty] = job
      const buf = await fetchTile(tx, ty)
      if (buf) {
        try {
          const png = PNG.sync.read(buf)
          for (let py = 0; py < 256; py++) {
            for (let px = 0; px < 256; px++) {
              const i = (py * 256 + px) * 4
              const e = png.data[i] * 256 + png.data[i + 1] + png.data[i + 2] / 256 - 32768
              grid[(ty * 256 + py) * SIZE + (tx * 256 + px)] = Math.max(-1000, Math.min(9000, Math.round(e)))
            }
          }
        } catch (_) { /* leave as 0 */ }
      }
      if (++done % 32 === 0) process.stderr.write(`  dem ${done}/${jobs.length} tiles\r`)
    }
  }
  const queue = jobs.slice()
  await Promise.all(Array.from({ length: CONC }, () => worker(queue)))
  process.stderr.write('\n')
  mkdirSync(CACHE, { recursive: true })
  writeFileSync(BIN, Buffer.from(grid.buffer))
  return grid
}

export async function loadDem () {
  let grid
  if (existsSync(BIN)) grid = new Int16Array(readFileSync(BIN).buffer.slice())
  else { console.error('building elevation grid from terrain tiles (one-time)…'); grid = await buildGrid() }

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
