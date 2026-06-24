/**
 * build-graticule.mjs — a sketched lat/long grid for the SEA, like an old chart. Meridians and
 * parallels every GRID°, densified, given a small hand-drawn wobble, then CLIPPED to ocean (the
 * over-land segments are dropped) so the grid only ever shows on water — independent of how
 * transparent the land fill is per style.
 *
 * Output: public/timemap/graticule.geojson (LineString features, water-only segments).
 * Run:    node scripts/build-graticule.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import * as turf from '@turf/turf'

const __dirname = dirname(fileURLToPath(import.meta.url))

const GRID = 10        // degrees between grid lines
const STEP = 1         // densify: a vertex every STEP degrees (finer = cleaner coastline clip + wobble)
const WOBBLE = 0.22    // max hand-drawn deviation (degrees)
const LAT_MAX = 84

let seed = 1337
const rnd = () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff }
const wob = () => (rnd() - 0.5) * 2 * WOBBLE

// land mask (same NE land used by the terrain builders) → keep only vertices that fall in the SEA
const landFC = JSON.parse(readFileSync(resolve(__dirname, '../storage/app/naturalearth/ne_50m_land.geojson'), 'utf8'))
const landParts = landFC.features.map((f) => ({ f, bbox: turf.bbox(f) }))
const onLand = (lng, lat) => {
  const pt = turf.point([lng, lat])
  for (const { f, bbox } of landParts) {
    if (lng < bbox[0] || lng > bbox[2] || lat < bbox[1] || lat > bbox[3]) continue
    if (turf.booleanPointInPolygon(pt, f)) return true
  }
  return false
}

const features = []
// break a densified point list into water-only runs → separate LineStrings
function emitWaterRuns (pts, kind) {
  let run = []
  const flush = () => { if (run.length >= 2) features.push({ type: 'Feature', properties: { kind }, geometry: { type: 'LineString', coordinates: run } }); run = [] }
  for (const [lng, lat] of pts) {
    if (onLand(lng, lat)) flush()
    else run.push([lng, lat])
  }
  flush()
}

for (let lng = -180; lng <= 180; lng += GRID) {
  const pts = []
  for (let lat = -LAT_MAX; lat <= LAT_MAX; lat += STEP) pts.push([lng + wob(), lat + wob() * 0.4])
  emitWaterRuns(pts, 'meridian')
}
for (let lat = -80; lat <= 80; lat += GRID) {
  const pts = []
  for (let lng = -180; lng <= 180; lng += STEP) pts.push([lng + wob() * 0.4, lat + wob()])
  emitWaterRuns(pts, 'parallel')
}

writeFileSync(resolve(__dirname, '../public/timemap/graticule.geojson'), JSON.stringify({ type: 'FeatureCollection', features }))
console.log(`graticule.geojson: ${features.length} water segments (every ${GRID}°, wobble ±${WOBBLE}°)`)
