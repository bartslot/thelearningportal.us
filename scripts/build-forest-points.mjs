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
import { wellInland } from './lib/land-mask.mjs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const pub = resolve(__dirname, '../public/timemap')

const GRID_KM = 30    // spacing between GROVES (each glyph is now a 4-5 tree cluster, not one tree)
const JITTER_KM = 10  // random offset so it's not a rigid grid
const MEDIUM_SHARE = 0.5 // mix of small + bigger groves
const RADIUS_MULT = 1.3 // grow each forest's extent so woodlands spread wider across the land
const INLAND_KM = 16  // keep groves off the coastline (land-fill vs NE-mask mismatch)

// Variant grove ids per size (from the icon build). A random one per grove breaks the repetition.
const VARIANTS = JSON.parse(readFileSync(resolve(pub, 'assets/forests/manifest.json'), 'utf8'))
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)]
const rand = (lo, hi) => lo + Math.random() * (hi - lo)

const forests = JSON.parse(readFileSync(`${pub}/forests.geojson`, 'utf8'))
const out = []

const kmToDeg = (km) => km / 111 // rough; fine for a small jitter

for (const forest of forests.features) {
  if (forest.geometry?.type !== 'Point') continue
  const name = forest.properties?.name ?? ''
  const r = Number(forest.properties?.r) || 20

  const blob = turf.buffer(forest, r * RADIUS_MULT, { units: 'kilometers' })
  if (!blob) continue

  const grid = turf.pointGrid(turf.bbox(blob), GRID_KM, { units: 'kilometers' })
  for (const pt of grid.features) {
    if (!turf.booleanPointInPolygon(pt, blob)) continue

    const [gx, gy] = pt.geometry.coordinates
    const lng = gx + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM)
    const lat = gy + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM)
    if (!wellInland(lng, lat, INLAND_KM)) continue

    const size = Math.random() < MEDIUM_SHARE ? 'medium' : 'small'
    const icon = pick(VARIANTS[size] || VARIANTS.small)
    const sz = +rand(0.85, 1.15).toFixed(3)
    out.push({ type: 'Feature', geometry: { type: 'Point', coordinates: [lng, lat] }, properties: { name, size, icon, sz } })
  }
}

writeFileSync(
  `${pub}/forest-points.geojson`,
  JSON.stringify({ type: 'FeatureCollection', features: out }),
)

const bySize = (s) => out.filter((f) => f.properties.size === s).length
console.log(`forest-points.geojson: ${out.length} groves — small ${bySize('small')} / medium ${bySize('medium')} (from ${forests.features.length} named forests)`)
