/**
 * build-elp2000.mjs — flatten ELP 2000-82B into a compact JS data module.
 *
 *   curl -O 'https://cdsarc.cds.unistra.fr/ftp/VI/79/ELP[1-36]'
 *   node build-elp2000.mjs <directory of ELP files> <output .js> [truncationRadians]
 *
 * This is a faithful port of the term assembly in the archive's own elp82b.f, which is the
 * authoritative statement of the conventions — the prose notice is a PostScript file, the Fortran
 * is unambiguous. Nothing here is recalled from memory.
 *
 * ELP publishes 36 files of series in three groups, and the group decides both the record layout
 * and how the argument is built:
 *
 *   ELP1-3    main problem: longitude, latitude, distance. The argument is a combination of the
 *             four Delaunay arguments D, ℓ', ℓ, F, each a quartic in time, so the argument is a
 *             QUARTIC. Amplitudes carry corrections fitted to DE200/LE200.
 *   ELP4-9    earth figure; ELP22-36 tides, moon figure, relativity, solar eccentricity. Argument
 *             is a phase plus Delaunay and zeta terms, linear in time.
 *   ELP10-21  planetary perturbations, argument built from the eight planetary mean longitudes.
 *
 * Every term is reduced here to one shape:
 *
 *     contribution = amplitude · t^power · sin(c0 + c1·t + c2·t² + c3·t³ + c4·t⁴)
 *
 * with t in Julian CENTURIES of TT from J2000 (note: centuries, where VSOP87 uses millennia). All
 * the constant folding happens at build time, so the runtime evaluator is one loop and a sine.
 * The main problem's distance series is a cosine in the Fortran, which is folded in here as a
 * phase shift of π/2 so that one loop can serve all three coordinates.
 *
 * TRUNCATION uses ELP's own mechanism: a level in radians, converted to arcseconds for longitude
 * and latitude and to kilometres for distance exactly as elp82b.f does. Using the theory's own
 * knob rather than inventing one means the published accuracy statements still mean something.
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const [, , sourceDir, outputPath, precisionArg] = process.argv
if (!sourceDir || !outputPath) {
  console.error('usage: node build-elp2000.mjs <elp dir> <out.js> [truncation in radians]')
  process.exit(1)
}
const PRECISION = Number(precisionArg ?? 1e-9)

const PI = Math.PI
const RAD = 648000 / PI                 // arcseconds per radian
const DEG = PI / 180
const ARCSEC = 1 / RAD

// Constants, verbatim from elp82b.f.
const ATH = 384747.9806743165
const A0 = 384747.9806448954
const AM = 0.074801329518
const ALFA = 0.002571881335
const DTASM = (2 * ALFA) / (3 * AM)

const dms = (d, m, s) => (d + m / 60 + s / 3600) * DEG

// Lunar arguments W1, W2, W3 and the earth's, each as a polynomial in centuries.
const W = [
  [dms(218, 18, 59.95571), 1732559343.73604 * ARCSEC, -5.8883 * ARCSEC, 0.6604e-2 * ARCSEC, -0.3169e-4 * ARCSEC],
  [dms(83, 21, 11.67475), 14643420.2632 * ARCSEC, -38.2776 * ARCSEC, -0.45047e-1 * ARCSEC, 0.21301e-3 * ARCSEC],
  [dms(125, 2, 40.39816), -6967919.3622 * ARCSEC, 6.3622 * ARCSEC, 0.7625e-2 * ARCSEC, -0.3586e-4 * ARCSEC],
]
const EARTH = [dms(100, 27, 59.22059), 129597742.2758 * ARCSEC, -0.0202 * ARCSEC, 0.9e-5 * ARCSEC, 0.15e-6 * ARCSEC]
const PERI = [dms(102, 56, 14.42753), 1161.2283 * ARCSEC, 0.5327 * ARCSEC, -0.138e-3 * ARCSEC, 0]

const PRECES = 5029.0966 * ARCSEC

// Planetary mean longitudes: [constant, rate], index 0..7 = Mercury..Neptune. Earth is the same
// series the moon's own arguments are referred to, which is why it is spliced in rather than given.
const P = [
  [dms(252, 15, 3.25986), 538101628.68898 * ARCSEC],
  [dms(181, 58, 47.28305), 210664136.43355 * ARCSEC],
  [EARTH[0], EARTH[1]],
  [dms(355, 25, 59.78866), 68905077.59284 * ARCSEC],
  [dms(34, 21, 5.34212), 10925660.42861 * ARCSEC],
  [dms(50, 4, 38.89694), 4399609.65932 * ARCSEC],
  [dms(314, 3, 18.01841), 1542481.19393 * ARCSEC],
  [dms(304, 20, 55.19575), 786550.32074 * ARCSEC],
]

// Corrections of the constants, fitted to DE200/LE200.
const DELNU = (0.55604 * ARCSEC) / W[0][1]
const DELE = 0.01789 * ARCSEC
const DELG = -0.08066 * ARCSEC
const DELNP = (-0.06424 * ARCSEC) / W[0][1]
const DELEP = -0.12879 * ARCSEC

// Delaunay arguments as polynomials in centuries: D, ℓ' (sun), ℓ (moon), F.
const DEL = [[], [], [], []]
for (let k = 0; k < 5; k++) {
  DEL[0][k] = W[0][k] - EARTH[k]
  DEL[1][k] = EARTH[k] - PERI[k]
  DEL[2][k] = W[0][k] - W[1][k]
  DEL[3][k] = W[0][k] - W[2][k]
}
DEL[0][0] += PI
const ZETA = [W[0][0], W[0][1] + PRECES]

/** Truncation thresholds per coordinate, exactly as elp82b.f derives them. */
const threshold = [PRECISION * RAD, PRECISION * RAD, PRECISION * ATH]

const series = [[], [], []]   // longitude (arcsec), latitude (arcsec), distance (km)

const addTerm = (coordinate, amplitude, timePower, argument) => {
  while (argument.length < 5) argument.push(0)
  // Fold the constant term into [0, 2π) so the emitted numbers stay small and readable.
  argument[0] = ((argument[0] % (2 * PI)) + 2 * PI) % (2 * PI)
  series[coordinate].push([amplitude, timePower, ...argument.map((v) => Number(v.toFixed(12)))])
}

const lines = (file) => readFileSync(join(sourceDir, file), 'utf8').split('\n').slice(1).filter((l) => l.trim())

// ── ELP1-3: the main problem ────────────────────────────────────────────────────────────────
for (let ific = 1; ific <= 3; ific++) {
  const coordinate = (ific - 1) % 3
  for (const line of lines(`ELP${ific}`)) {
    const ilu = [0, 1, 2, 3].map((i) => Number(line.slice(i * 3, i * 3 + 3)))
    // Fixed-width, per the Fortran format 4i3,2x,f13.5,6(2x,f10.2).
    const c = [
      Number(line.slice(14, 27)),
      Number(line.slice(29, 39)), Number(line.slice(41, 51)), Number(line.slice(53, 63)),
      Number(line.slice(65, 75)), Number(line.slice(77, 87)), Number(line.slice(89, 99)),
    ]
    if (Math.abs(c[0]) < threshold[coordinate]) continue

    let a = c[0]
    if (ific === 3) a -= (2 * a * DELNU) / 3
    const tgv = c[1] + DTASM * c[5]
    const amplitude = a + tgv * (DELNP - AM * DELNU) + c[2] * DELG + c[3] * DELE + c[4] * DELEP

    const argument = [0, 0, 0, 0, 0]
    for (let k = 0; k < 5; k++) for (let i = 0; i < 4; i++) argument[k] += ilu[i] * DEL[i][k]
    if (coordinate === 2) argument[0] += PI / 2   // the distance series is a cosine
    addTerm(coordinate, amplitude, 0, argument)
  }
}

// ── ELP4-9 and ELP22-36: figures, tides, relativity, solar eccentricity ─────────────────────
const linearGroups = [4, 5, 6, 7, 8, 9, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36]
for (const ific of linearGroups) {
  const coordinate = (ific - 1) % 3
  for (const line of lines(`ELP${ific}`)) {
    // Format 5i3,1x,f9.5,1x,f9.5,1x,f9.3
    const iz = Number(line.slice(0, 3))
    const ilu = [1, 2, 3, 4].map((i) => Number(line.slice(i * 3, i * 3 + 3)))
    const phase = Number(line.slice(16, 25))
    const amplitude = Number(line.slice(26, 35))
    if (amplitude < threshold[coordinate]) continue

    // The amplitude of some groups is itself multiplied by a power of time.
    let timePower = 0
    if ((ific >= 7 && ific <= 9) || (ific >= 25 && ific <= 27)) timePower = 1
    if (ific >= 34 && ific <= 36) timePower = 2

    const argument = [phase * DEG, 0, 0, 0, 0]
    for (let k = 0; k < 2; k++) {
      argument[k] += iz * ZETA[k]
      for (let i = 0; i < 4; i++) argument[k] += ilu[i] * DEL[i][k]
    }
    addTerm(coordinate, amplitude, timePower, argument)
  }
}

// ── ELP10-21: planetary perturbations ───────────────────────────────────────────────────────
for (let ific = 10; ific <= 21; ific++) {
  const coordinate = (ific - 1) % 3
  for (const line of lines(`ELP${ific}`)) {
    // Format 11i3,1x,f9.5,1x,f9.5,1x,f9.3
    const ipla = Array.from({ length: 11 }, (unused, i) => Number(line.slice(i * 3, i * 3 + 3)))
    const phase = Number(line.slice(34, 43))
    const amplitude = Number(line.slice(44, 53))
    if (amplitude < threshold[coordinate]) continue

    const timePower = (ific >= 13 && ific <= 15) || (ific >= 19 && ific <= 21) ? 1 : 0
    const argument = [phase * DEG, 0, 0, 0, 0]

    for (let k = 0; k < 2; k++) {
      if (ific <= 15) {
        // Table 1: eight planetary longitudes plus three Delaunay combinations.
        argument[k] += ipla[8] * DEL[0][k] + ipla[9] * DEL[2][k] + ipla[10] * DEL[3][k]
        for (let i = 0; i < 8; i++) argument[k] += ipla[i] * P[i][k]
      } else {
        // Table 2: four Delaunay arguments plus seven planetary longitudes.
        for (let i = 0; i < 4; i++) argument[k] += ipla[i + 7] * DEL[i][k]
        for (let i = 0; i < 7; i++) argument[k] += ipla[i] * P[i][k]
      }
    }
    addTerm(coordinate, amplitude, timePower, argument)
  }
}

const counts = series.map((s) => s.length)
const format = (terms) => terms
  .map((t) => `[${t.map((v, i) => (i === 0 ? Number(v.toFixed(8)) : v)).join(',')}]`)
  .join(',\n  ')

writeFileSync(outputPath, `/**
 * elp2000-data.js — GENERATED, do not edit by hand.
 *
 * Rebuild with:
 *   curl -O 'https://cdsarc.cds.unistra.fr/ftp/VI/79/ELP[1-36]'
 *   node __tests__/fixtures/build-elp2000.mjs <dir> resources/js/timemap/elp2000-data.js ${PRECISION}
 *
 * Source: Chapront-Touze M., Chapront J., "ELP 2000-85: a semi-analytical lunar ephemeris adequate
 * for historical times", Astron. Astrophys. 190, 342 (1988). Constants fitted to JPL DE200/LE200.
 * Retrieved from CDS VizieR VI/79; term assembly ported from the archive's own elp82b.f.
 *
 * Each term is [amplitude, timePower, c0, c1, c2, c3, c4] and contributes
 *
 *     amplitude · t^timePower · sin(c0 + c1·t + c2·t² + c3·t³ + c4·t⁴)
 *
 * with t in Julian CENTURIES of TT from J2000. Longitude and latitude are in ARCSECONDS,
 * distance in KILOMETRES. Truncated at ${PRECISION} rad by ELP's own criterion:
 * ${counts[0]} longitude, ${counts[1]} latitude, ${counts[2]} distance terms.
 */

export const ELP_LONGITUDE = [
  ${format(series[0])},
]

export const ELP_LATITUDE = [
  ${format(series[1])},
]

export const ELP_DISTANCE = [
  ${format(series[2])},
]

/** W1: the moon's mean longitude, a quartic in centuries. Added to the longitude series. */
export const ELP_W1 = [${W[0].join(',')}]

/** Scale factor a0/ath applied to the distance series, and the laser-fitted semi-major axis. */
export const ELP_DISTANCE_SCALE = ${A0 / ATH}
`)

console.log(`wrote ${outputPath}`)
console.log(`truncation ${PRECISION} rad — ${counts[0]} longitude, ${counts[1]} latitude, ${counts[2]} distance terms`)
