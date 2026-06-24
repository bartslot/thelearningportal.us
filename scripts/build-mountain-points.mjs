/**
 * build-mountain-points.mjs — place hills & mountains from a REAL elevation grid (AWS terrain tiles,
 * see scripts/lib/elevation.mjs), so every upland shows — not just a curated list of famous ranges.
 * The Vosges, Black Forest, Harz, Massif Central, etc. now appear, sized by their true height.
 *
 *   • where to place : local RUGGEDNESS (relief) gates it — flat plains & flat plateaus stay empty,
 *                      rugged terrain fills in; placement probability scales with relief.
 *   • how big        : the local ELEVATION picks the glyph tier (gentle hills → sharp peaks).
 *   • how it reads   : each spot is a little Tolkien hill-GROUP (mostly 3 columns, centre taller,
 *                      flanks a size smaller; sometimes a lone 1), not a mechanical line.
 * Deserts are left to the dune scatter unless they hold a real massif (Ahaggar/Tibesti, E>1500).
 *
 * Output: public/timemap/mountains-points.geojson  ({ size, icon, sz, rot }).
 * Run:    node scripts/build-mountain-points.mjs   (first run builds the DEM cache, ~1 min)
 */
import { writeFileSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { wellInland } from './lib/land-mask.mjs'
import { inDesert } from './lib/deserts.mjs'
import { loadDem } from './lib/elevation.mjs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const pub = resolve(__dirname, '../public/timemap')

const SIZES = ['smaller', 'small', 'medium', 'large']
const down = (size, n) => SIZES[Math.max(0, SIZES.indexOf(size) - n)]

const STEP_KM = 34      // placement grid spacing
const RELIEF_MIN = 180  // metres of local relief to count as "rugged" (gates flat land out)
const INLAND_KM = 8
// density per cell scales with ruggedness — gentle uplands get a light sprinkle, real ranges fill in
const probFor = (R) => Math.min(0.72, Math.max(0.06, (R - 160) / 1500))

// local elevation (m, coarse 10 km average) → glyph tier. Averaging pulls peaks down, so the bands
// sit lower than true summits (Alps average ~2500 → large; Vosges ~1000 → small hills).
function tierFor (E, R) {
  if (E < 600) return 'smaller'
  if (E < 1100) return 'small'
  if (E < 1800) return 'medium'
  if (E < 2700) return (R > 1200 || Math.random() < 0.5) ? 'large' : 'medium'
  return 'large'
}
const COL_KM = { smaller: 13, small: 15, medium: 20, large: 27 } // cluster column gap by centre size

const MANIFEST = JSON.parse(readFileSync(resolve(pub, 'assets/mountains/manifest.json'), 'utf8'))
// drop mirrored (…f) variants — the etched shading is the lit side; every glyph faces one "sun".
const VARIANTS = Object.fromEntries(Object.entries(MANIFEST).map(([k, v]) => [k, v.filter((id) => !id.endsWith('f'))]))
const pick = (a) => a[Math.floor(Math.random() * a.length)]
const rand = (lo, hi) => lo + Math.random() * (hi - lo)
const kmToDeg = (km) => km / 111
const jit = (km) => (Math.random() - 0.5) * 2 * kmToDeg(km)

const out = []
const r3 = (n) => Math.round(n * 1000) / 1000 // ~110 m precision keeps the file small
const push = (lng, lat, size) => {
  if (!wellInland(lng, lat, INLAND_KM)) return
  out.push({
    type: 'Feature',
    geometry: { type: 'Point', coordinates: [r3(lng), r3(lat)] },
    properties: { t: size, icon: pick(VARIANTS[size] || VARIANTS.medium), sz: +rand(0.88, 1.14).toFixed(2), rot: +rand(-3.5, 3.5).toFixed(1) },
  })
}

const dem = await loadDem()

for (let lat = -56; lat <= 82; lat += kmToDeg(STEP_KM)) {
  const lngStep = kmToDeg(STEP_KM) / Math.max(0.25, Math.cos((lat * Math.PI) / 180))
  for (let lng = -180; lng <= 180; lng += lngStep) {
    // cheap pre-filters first (array lookups), expensive land test only for survivors
    const E = dem.elevationAt(lng, lat)
    if (E < 120) continue                          // sea level / ocean
    const R = dem.reliefAt(lng, lat, 3)            // ~30 km ruggedness
    if (R < RELIEF_MIN && E < 2000) continue        // flat lowland / flat plateau → no peaks
    if (inDesert(lng, lat) && E < 1500) continue    // desert plains → dunes handle them
    if (Math.random() > probFor(R)) continue

    // jittered cluster centre
    const cLng = lng + jit(9), cLat = lat + jit(7)
    const top = tierFor(dem.elevationAt(cLng, cLat) || E, R)
    const side = down(top, 1)

    const r = Math.random()
    const cols = r < 0.6 ? 3 : r < 0.82 ? 2 : 1
    push(cLng + jit(3), cLat + jit(3), top)
    if (cols >= 2) {
      const dLng = COL_KM[top] / 111 / Math.max(0.25, Math.cos((cLat * Math.PI) / 180))
      const flanks = cols === 3 ? [-1, 1] : [Math.random() < 0.5 ? -1 : 1]
      for (const sgn of flanks) {
        push(cLng + sgn * dLng * (0.85 + Math.random() * 0.3) + jit(3), cLat - kmToDeg(rand(0, 5)) + jit(2), side)
      }
    }
  }
}

writeFileSync(`${pub}/mountains-points.geojson`, JSON.stringify({ type: 'FeatureCollection', features: out }))
const by = (s) => out.filter((f) => f.properties.t === s).length
console.log(`mountains-points.geojson: ${out.length} glyphs (large ${by('large')}, medium ${by('medium')}, small ${by('small')}, smaller ${by('smaller')})`)
