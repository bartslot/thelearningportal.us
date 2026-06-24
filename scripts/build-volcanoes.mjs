/**
 * build-volcanoes.mjs — a curated set of famous volcanoes placed at their real coordinates, drawn
 * with the volcano cones extracted from drawing.svg (assets/volcanoes). Output is a small point
 * GeoJSON the map renders as a symbol layer (see resources/js/map-volcanoes.js).
 *
 * Output: public/timemap/volcanoes.geojson  Run: node scripts/build-volcanoes.mjs
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'

const VARIANTS = JSON.parse(readFileSync(resolve('public/timemap/assets/volcanoes/manifest.json'), 'utf8')).ids

// name, lng, lat — well-known volcanoes within the map's coverage
const VOLCANOES = [
  ['Vesuvius', 14.426, 40.821], ['Etna', 14.999, 37.748], ['Stromboli', 15.213, 38.789],
  ['Thera', 25.396, 36.404], ['Hekla', -19.666, 63.992], ['Teide', -16.642, 28.272],
  ['Elbrus', 42.439, 43.355], ['Ararat', 44.298, 39.702], ['Damavand', 52.11, 35.955],
  ['Fuji', 138.731, 35.361], ['Krakatoa', 105.423, -6.102], ['Pinatubo', 120.35, 15.13],
  ['Kilimanjaro', 37.355, -3.066], ['Nyiragongo', 29.25, -1.52], ['Cotopaxi', -78.437, -0.684],
  ['Popocatepetl', -98.622, 19.023], ['St. Helens', -122.18, 46.20], ['Mauna Loa', -155.608, 19.475],
]

// deterministic spread of cone variants (no Date/random needed)
const features = VOLCANOES.map(([name, lng, lat], i) => ({
  type: 'Feature',
  properties: { name, icon: VARIANTS[i % VARIANTS.length], sz: 1 },
  geometry: { type: 'Point', coordinates: [lng, lat] },
}))

writeFileSync(resolve('public/timemap/volcanoes.geojson'), JSON.stringify({ type: 'FeatureCollection', features }))
console.log(`volcanoes.geojson: ${features.length} famous volcanoes`)
