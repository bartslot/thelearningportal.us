/**
 * build-forest-points.mjs — turn the curated named FORESTS (public/timemap/forests.geojson) into a
 * dense FIELD of tree POINTS. Mirrors build-mountain-points.mjs.
 *
 * Each forest is a Point with `r` (approx radius km) and `conifer` (fir share 0-1). We buffer it to
 * a blob and fill it DENSELY with small tree glyphs, clipped to land. Discrete named forests (the
 * Black-Forest model) leave open land — grassland — between them, instead of a biome-wide carpet.
 *
 * Output: public/timemap/forest-points.geojson (Point features with { name, size, kind }).
 * Run:    node scripts/build-forest-points.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import * as turf from '@turf/turf'

const __dirname = dirname(fileURLToPath(import.meta.url))
const pub = resolve(__dirname, '../public/timemap')

const GRID_KM = 16    // spacing between trees (larger = more spread out, bolder trees read)
const JITTER_KM = 7   // random offset so it's not a rigid grid
const MEDIUM_SHARE = 0.38 // a healthy share of larger trees for substance
const RADIUS_MULT = 1.3 // grow each forest's extent so woodlands spread wider across the land

const forests = JSON.parse(readFileSync(`${pub}/forests.geojson`, 'utf8'))
const out = []

const kmToDeg = (km) => km / 111 // rough; fine for a small jitter

// Land mask — drop any tree that lands in the sea (coastal forest blobs spill into water).
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

for (const forest of forests.features) {
  if (forest.geometry?.type !== 'Point') continue
  const name = forest.properties?.name ?? ''
  const r = Number(forest.properties?.r) || 20
  const coniferShare = Number(forest.properties?.conifer)
  const conifer = Number.isFinite(coniferShare) ? coniferShare : 0.4

  const blob = turf.buffer(forest, r * RADIUS_MULT, { units: 'kilometers' })
  if (!blob) continue

  const grid = turf.pointGrid(turf.bbox(blob), GRID_KM, { units: 'kilometers' })
  for (const pt of grid.features) {
    if (!turf.booleanPointInPolygon(pt, blob)) continue

    const [gx, gy] = pt.geometry.coordinates
    const lng = gx + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM)
    const lat = gy + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM)
    if (!onLand(lng, lat)) continue

    const size = Math.random() < MEDIUM_SHARE ? 'medium' : 'small'
    const kind = Math.random() < conifer ? 'conifer' : 'deciduous'
    out.push({ type: 'Feature', geometry: { type: 'Point', coordinates: [lng, lat] }, properties: { name, size, kind } })
  }
}

writeFileSync(
  `${pub}/forest-points.geojson`,
  JSON.stringify({ type: 'FeatureCollection', features: out }),
)

const byKind = (k) => out.filter((f) => f.properties.kind === k).length
console.log(`forest-points.geojson: ${out.length} trees — conifer ${byKind('conifer')} / deciduous ${byKind('deciduous')} (from ${forests.features.length} named forests)`)
