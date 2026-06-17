/**
 * build-mountain-points.mjs — turn the curated ridge LINES (public/timemap/mountains.geojson)
 * into a dense FIELD of mountain POINTS so a massif like the Alps reads as many peaks, not one
 * line. Each point is sized by its distance from the ridge spine: big in the core, medium
 * mid-way, small toward the edges — matching how a range's mass tapers off.
 *
 * Output: public/timemap/mountains-points.geojson (Point features with { name, size }).
 * Run:    node scripts/build-mountain-points.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import * as turf from '@turf/turf'

const __dirname = dirname(fileURLToPath(import.meta.url))
const pub = resolve(__dirname, '../public/timemap')

const BUFFER_KM = 55      // how wide the massif spreads either side of the ridge
const GRID_KM = 38        // spacing between mountains (smaller = denser)
const JITTER_KM = 11      // random offset so it's not a rigid grid

const ranges = JSON.parse(readFileSync(`${pub}/mountains.geojson`, 'utf8'))
const out = []

const kmToDeg = (km) => km / 111 // rough; fine for a small jitter

for (const range of ranges.features) {
  if (range.geometry?.type !== 'LineString') continue
  const name = range.properties?.name ?? ''
  const massif = turf.buffer(range, BUFFER_KM, { units: 'kilometers' })
  if (!massif) continue

  const grid = turf.pointGrid(turf.bbox(massif), GRID_KM, { units: 'kilometers' })
  for (const pt of grid.features) {
    if (!turf.booleanPointInPolygon(pt, massif)) continue

    // Bigger peaks in the core, tapering through medium/small to "smaller" at the edges.
    const frac = turf.pointToLineDistance(pt, range, { units: 'kilometers' }) / BUFFER_KM
    const size = frac < 0.25 ? 'large' : frac < 0.5 ? 'medium' : frac < 0.75 ? 'small' : 'smaller'

    const [lng, lat] = pt.geometry.coordinates
    out.push({
      type: 'Feature',
      geometry: {
        type: 'Point',
        coordinates: [
          lng + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM),
          lat + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM),
        ],
      },
      properties: { name, size },
    })
  }
}

writeFileSync(
  `${pub}/mountains-points.geojson`,
  JSON.stringify({ type: 'FeatureCollection', features: out }),
)

const by = (s) => out.filter((f) => f.properties.size === s).length
console.log(`mountains-points.geojson: ${out.length} peaks (large ${by('large')}, medium ${by('medium')}, small ${by('small')}, smaller ${by('smaller')})`)
