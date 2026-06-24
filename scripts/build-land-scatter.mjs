/**
 * build-land-scatter.mjs — sprinkle sparse little tree groves across ALL land (not the named
 * forests) so empty parchment gets some hand-drawn texture. Coarse jittered grid, clipped to land,
 * thinned at random; each point gets a random scatter-grove variant + size jitter.
 *
 * Output: public/timemap/land-scatter.geojson (Point features with { icon, sz }).
 * Run:    node scripts/build-land-scatter.mjs  (run build-terrain-icons.mjs first for the manifest)
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { wellInland } from './lib/land-mask.mjs'
import { inDesert } from './lib/deserts.mjs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const pub = resolve(__dirname, '../public/timemap')

const GRID_KM = 95     // coarse spacing — texture, not a carpet
const JITTER_KM = 38   // break the grid up
const KEEP = 0.34      // randomly drop most cells so it stays sparse
const INLAND_KM = 28   // keep scatter well off the coast (avoids marks floating over water)

const VARIANTS = JSON.parse(readFileSync(resolve(pub, 'assets/scatter/manifest.json'), 'utf8'))
const pick = (a) => a[Math.floor(Math.random() * a.length)]
const rand = (lo, hi) => lo + Math.random() * (hi - lo)
const kmToDeg = (km) => km / 111

// manual lat/lng grid (turf.pointGrid can't handle a global bbox at this resolution)
const out = []
const STEP = kmToDeg(GRID_KM)
for (let gy = -58; gy <= 80; gy += STEP) {
  for (let gx = -180; gx <= 180; gx += STEP) {
    if (Math.random() > KEEP) continue
    const lng = gx + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM)
    const lat = gy + (Math.random() - 0.5) * 2 * kmToDeg(JITTER_KM)
    if (!wellInland(lng, lat, INLAND_KM)) continue
    // Desert → sand dunes (flat, no tilt). Elsewhere → varied scrub/woodland dabs (slight tilt).
    const desert = inDesert(lng, lat)
    const icon = pick(desert ? VARIANTS.dune : VARIANTS.tree)
    const props = desert
      ? { icon, sz: +rand(0.85, 1.25).toFixed(3) }
      : { icon, sz: +rand(0.78, 1.28).toFixed(3), rot: +rand(-14, 14).toFixed(1) }
    out.push({ type: 'Feature', geometry: { type: 'Point', coordinates: [lng, lat] }, properties: props })
  }
}

const dunes = out.filter((f) => f.properties.icon.startsWith('dune-')).length
writeFileSync(resolve(pub, 'land-scatter.geojson'), JSON.stringify({ type: 'FeatureCollection', features: out }))
console.log(`land-scatter.geojson: ${out.length} marks (${out.length - dunes} scrub, ${dunes} dunes)`)
