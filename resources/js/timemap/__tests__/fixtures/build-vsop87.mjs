/**
 * build-vsop87.mjs — turn the published VSOP87A series into a compact JS data module.
 *
 *   curl -O 'https://cdsarc.cds.unistra.fr/ftp/VI/81/VSOP87A.{mer,ven,ear,mar,jup,sat,ura,nep}'
 *   node build-vsop87.mjs <directory of VSOP87A files> <output .js> [amplitudeCutoff]
 *
 * VSOP87A is heliocentric RECTANGULAR coordinates referred to the dynamical ecliptic and equinox
 * of J2000 — the same frame the Keplerian element sets use, so it drops straight into the same
 * places without a frame conversion.
 *
 * Each series is  coordinate = Σ_α T^α · Σ_n A_n·cos(B_n + C_n·T),  with T in Julian MILLENNIA of
 * Terrestrial Time from J2000: T = (JD_TT − 2451545) / 365250.
 *
 * TRUNCATION. The full theory is 5 MB of coefficients and roughly 44,000 terms, which is more than
 * a browser needs to carry. Terms are dropped by amplitude, and the cutoff is in AU, so its cost
 * is bounded and computable rather than a matter of taste: an error of ε AU in a body's position,
 * viewed from a distance d AU, subtends at most ε/d × 206265 arcseconds. The default 1e-9 AU is
 * 150 metres, which is a thousandth of an arcsecond for anything past a tenth of an AU.
 *
 * That bound holds over ±1000 years because |T| < 1 there, so the T^α factors SHRINK the
 * higher-order terms rather than amplifying them. It stops holding for dates far outside that
 * span, which is one of the reasons the module states an explicit range of validity.
 *
 * Record format (from the archive's own vsop87.txt): amplitude A at columns 80-97, phase B at
 * 98-111, frequency C at 112-131; header lines carry the coordinate index at column 42 and the
 * power of T at column 60.
 */

import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const BODIES = {
  mer: 'mercury', ven: 'venus', ear: 'earth', mar: 'mars',
  jup: 'jupiter', sat: 'saturn', ura: 'uranus', nep: 'neptune',
}

const [, , sourceDir, outputPath, cutoffArg] = process.argv
if (!sourceDir || !outputPath) {
  console.error('usage: node build-vsop87.mjs <vsop87 dir> <out.js> [cutoff in au]')
  process.exit(1)
}
const CUTOFF = Number(cutoffArg ?? 1e-9)

/** Parse one VSOP87A file into [coordinate][power] = [[A, B, C], ...]. */
const parseBody = (path) => {
  const series = [[], [], []]           // X, Y, Z; each a list indexed by power of T
  let current = null

  for (const line of readFileSync(path, 'utf8').split('\n')) {
    if (!line.trim()) continue

    if (line.includes('VSOP87 VERSION')) {
      const coordinate = Number(line.slice(41, 42)) - 1   // ic: 1=X 2=Y 3=Z
      const power = Number(line.slice(59, 60))            // it: degree of T
      if (!series[coordinate][power]) series[coordinate][power] = []
      current = series[coordinate][power]
      continue
    }

    const amplitude = Number(line.slice(79, 97))
    if (Math.abs(amplitude) < CUTOFF) continue
    current.push([amplitude, Number(line.slice(97, 111)), Number(line.slice(111, 131))])
  }

  // Sort each series by descending amplitude: summing small terms first would be marginally more
  // accurate, but keeping the dominant term first makes the tables readable and diffable, and at
  // double precision over ~1000 terms the difference is far below the truncation error anyway.
  return series.map((coordinate) => coordinate.map((terms) => terms.sort((a, b) => Math.abs(b[0]) - Math.abs(a[0]))))
}

const round = (value, places) => Number(value.toFixed(places))

const data = {}
let kept = 0
let total = 0

for (const file of readdirSync(sourceDir).filter((f) => f.startsWith('VSOP87A.')).sort()) {
  const suffix = file.split('.')[1]
  const name = BODIES[suffix]
  if (!name) continue
  const full = parseBody(join(sourceDir, file))
  data[name] = full
  kept += full.flat(2).length
  total += readFileSync(join(sourceDir, file), 'utf8').split('\n').filter((l) => l.trim() && !l.includes('VSOP87 VERSION')).length
}

// Amplitudes to 11 decimals (the archive's own precision), phase and frequency likewise. Rounding
// further would eat into the truncation budget the cutoff was chosen to protect.
const serialise = (terms) => `[${terms.map(([a, b, c]) => `[${round(a, 11)},${round(b, 8)},${round(c, 8)}]`).join(',')}]`

const body = Object.entries(data).map(([name, coordinates]) => {
  const parts = coordinates.map((powers) => `[${powers.map(serialise).join(',')}]`)
  return `  ${name}: [\n    ${parts.join(',\n    ')},\n  ],`
}).join('\n')

writeFileSync(outputPath, `/**
 * vsop87a-data.js — GENERATED, do not edit by hand.
 *
 * Rebuild with:
 *   curl -O 'https://cdsarc.cds.unistra.fr/ftp/VI/81/VSOP87A.[mer,ven,ear,mar,jup,sat,ura,nep]'
 *   node __tests__/fixtures/build-vsop87.mjs <dir> resources/js/timemap/vsop87a-data.js ${CUTOFF}
 *
 * Source: Bretagnon P., Francou G., "Planetary Theories in rectangular and spherical variables:
 * VSOP87 solution", Astron. Astrophys. 202, 309 (1988). Retrieved from CDS VizieR VI/81.
 *
 * Version A: heliocentric rectangular coordinates, dynamical ecliptic and equinox J2000, in au.
 * Shape: [body][coordinate 0=X 1=Y 2=Z][power of T][term] = [amplitude, phase, frequency].
 * Evaluate as Σ_α T^α · Σ_n A·cos(B + C·T) with T = (JD_TT − 2451545) / 365250.
 *
 * Terms with |amplitude| < ${CUTOFF} au were dropped: ${kept.toLocaleString('en')} of
 * ${total.toLocaleString('en')} retained. See build-vsop87.mjs for why that cutoff is safe, and
 * vsop87.test.js for what it actually costs, measured.
 */

export const VSOP87A = {
${body}
}
`)

console.log(`wrote ${outputPath}`)
console.log(`cutoff ${CUTOFF} au — kept ${kept} of ${total} terms`)
