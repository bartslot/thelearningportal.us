import { describe, it, expect } from 'vitest'
import {
  centuriesTT,
  moonEclipticAtCenturies,
  moonEclipticJ2000,
  moonPhase,
  moonPosition,
  moonVector,
} from '../elp2000.js'
import { moonEcliptic as keplerMoonEcliptic } from '../moon-kepler.js'
import { apparentSun, heliocentric } from '../vsop87.js'
import { sunDirection } from '../sun.js'
import { angularSeparation } from '../orbit.js'
import { precessEclipticToDate, wrapLongitude } from '../celestial-frames.js'
import { deltaTSeconds } from '../delta-t.js'
import horizons from './fixtures/horizons-precision.json'

/**
 * The moon by ELP 2000-82B.
 *
 * The first block is the acceptance suite from `moon.test.js`, unchanged in substance: perigee and
 * apogee, the 28.6° latitude bound, a synodic month, full when opposite the sun. Those are facts
 * about the moon rather than about any implementation, and a better ephemeris still has to satisfy
 * every one of them. They are also, every one, invariant under a shift in time — which is how a
 * twenty-degree epoch error once lived in this directory undetected. So the second block checks
 * ABSOLUTE position against JPL Horizons, which is the only kind of assertion that can see that.
 */

const utc = (y, m, d, h = 0) => new Date(Date.UTC(y, m - 1, d, h))
const PERIGEE_M = 356500000
const APOGEE_M = 406700000
const SYNODIC_DAYS = 29.530588
const AU_METRES = 149597870700

const arcsec = (a, b) => angularSeparation(a, b) * 3600
const epochIndex = (label) => horizons.epochs.findIndex((e) => e.label === label)
const at = (label) => new Date(horizons.epochs[epochIndex(label)].iso)
/** Horizons' geometric geocentric vector, in metres, for the epoch at `index`. */
const reference = (index) => {
  const [x, y, z] = horizons.geocentric.moon[index]
  return { x: x * AU_METRES, y: y * AU_METRES, z: z * AU_METRES }
}

describe('the facts any moon must satisfy', () => {
  it('never leaves the range between perigee and apogee', () => {
    for (let day = 0; day < 400; day += 3) {
      const { distance } = moonPosition(new Date(utc(2026, 1, 1).getTime() + day * 86400000))
      expect(distance).toBeGreaterThan(PERIGEE_M * 0.99)
      expect(distance).toBeLessThan(APOGEE_M * 1.01)
    }
  })

  it('holds that range five centuries earlier too', () => {
    for (let day = 0; day < 400; day += 3) {
      const { distance } = moonPosition(new Date(utc(1492, 1, 1).getTime() + day * 86400000))
      expect(distance).toBeGreaterThan(PERIGEE_M * 0.99)
      expect(distance).toBeLessThan(APOGEE_M * 1.01)
    }
  })

  it('swings through the full range of distance over a few months, not a fixed circle', () => {
    const distances = []
    for (let day = 0; day < 90; day++) {
      distances.push(moonPosition(new Date(utc(2026, 3, 1).getTime() + day * 86400000)).distance)
    }
    expect(Math.max(...distances) - Math.min(...distances)).toBeGreaterThan(35000000)
  })

  it('stays within the tilt of the earth plus the tilt of its own orbit', () => {
    // 23.44° + 5.15° = 28.6°, the furthest north or south the moon can ever be overhead.
    for (let day = 0; day < 400; day += 2) {
      expect(Math.abs(moonPosition(new Date(utc(2026, 1, 1).getTime() + day * 86400000)).lat)).toBeLessThan(29.5)
      expect(Math.abs(moonPosition(new Date(utc(1492, 1, 1).getTime() + day * 86400000)).lat)).toBeLessThan(29.5)
    }
  })

  it('reports a sub-lunar longitude in ±180 so callers never handle the wrap', () => {
    for (let hour = 0; hour < 48; hour += 3) {
      const { lng } = moonPosition(utc(2026, 6, 12, hour))
      expect(lng).toBeGreaterThanOrEqual(-180)
      expect(lng).toBeLessThanOrEqual(180)
    }
  })

  it('drifts west more slowly than the sun, because it is moving east against the stars', () => {
    const a = moonPosition(utc(2026, 5, 5, 0)).lng
    const b = moonPosition(utc(2026, 5, 5, 6)).lng
    expect(wrapLongitude(a - b)).toBeGreaterThan(80)   // 6h × 15°/h = 90°, less the moon's own motion
    expect(wrapLongitude(a - b)).toBeLessThan(90)
  })

  it('is about sixty earth radii out — the distance nobody draws to scale', () => {
    const length = Math.hypot(...moonVector(utc(2026, 7, 1)))
    expect(length).toBeGreaterThan(55)
    expect(length).toBeLessThan(64)
  })

  it('points the same way as the sub-lunar point it came from', () => {
    const date = utc(2026, 9, 9, 4)
    const { lng, lat } = moonPosition(date)
    const [x, y, z] = moonVector(date)
    const length = Math.hypot(x, y, z)
    expect(Math.asin(y / length) * 180 / Math.PI).toBeCloseTo(lat, 4)
    expect(Math.atan2(z, x) * 180 / Math.PI).toBeCloseTo(lng, 4)
  })
})

describe('phase', () => {
  it('runs from new to full and back within one synodic month', () => {
    const phases = []
    for (let day = 0; day < 30; day++) {
      const date = new Date(utc(2026, 1, 1).getTime() + day * 86400000)
      phases.push(moonPhase(date, sunDirection(date)))
    }
    expect(Math.min(...phases)).toBeLessThan(0.06)
    expect(Math.max(...phases)).toBeGreaterThan(0.94)
  })

  it('repeats a synodic month later — the definition of the month', () => {
    const date = utc(2026, 4, 18, 12)
    const later = new Date(date.getTime() + SYNODIC_DAYS * 86400000)
    expect(moonPhase(later, sunDirection(later))).toBeCloseTo(moonPhase(date, sunDirection(date)), 1)
  })

  it('is a fraction, always', () => {
    for (let day = 0; day < 60; day++) {
      const date = new Date(utc(2026, 2, 1).getTime() + day * 86400000)
      const phase = moonPhase(date, sunDirection(date))
      expect(phase).toBeGreaterThanOrEqual(0)
      expect(phase).toBeLessThanOrEqual(1)
    }
  })

  it('is full when the moon is opposite the sun, whatever the date', () => {
    // The inverted-phase bug this suite was built to catch: a moon reported FULL at new survives
    // every visual check, because a crescent still looks like a crescent. Assert the geometry.
    let best = { phase: -1, date: null }
    for (let hour = 0; hour < 30 * 24; hour += 6) {
      const date = new Date(utc(2026, 8, 1).getTime() + hour * 3600000)
      const phase = moonPhase(date, sunDirection(date))
      if (phase > best.phase) best = { phase, date }
    }
    const moon = moonVector(best.date)
    const length = Math.hypot(...moon)
    const sun = sunDirection(best.date)
    const cosElongation = (moon[0] * sun[0] + moon[1] * sun[1] + moon[2] * sun[2]) / length
    expect(Math.acos(cosElongation) * 180 / Math.PI).toBeGreaterThan(168)
  })

  it('is new when the moon is between us and the sun, five centuries ago as well', () => {
    let best = { phase: 2, date: null }
    for (let hour = 0; hour < 30 * 24; hour += 6) {
      const date = new Date(utc(1492, 9, 1).getTime() + hour * 3600000)
      const phase = moonPhase(date, sunDirection(date))
      if (phase < best.phase) best = { phase, date }
    }
    const moon = moonVector(best.date)
    const sun = sunDirection(best.date)
    const cosElongation = (moon[0] * sun[0] + moon[1] * sun[1] + moon[2] * sun[2]) / Math.hypot(...moon)
    expect(Math.acos(cosElongation) * 180 / Math.PI).toBeLessThan(12)
  })
})

describe('absolute position against JPL Horizons — measured, not claimed', () => {
  // Worst angular error over 22 dates from 1400 to 2050, in arcseconds, against DE441. Compared on
  // the TT-direct path so no ΔT model sits between the two sides.
  it('is within 34 arcseconds of DE441 across six centuries, and a tenth of one at J2000', () => {
    let worst = 0
    let worstLabel = null
    horizons.epochs.forEach((epoch, index) => {
      const mine = moonEclipticAtCenturies((epoch.jd - 2451545) / 36525)
      const error = arcsec(mine, reference(index))
      if (error > worst) { worst = error; worstLabel = epoch.label }
    })
    expect(worst).toBeLessThan(34)
    expect(worstLabel).toBe('agincourt-1415')

    const j2000 = epochIndex('j2000')
    expect(arcsec(moonEclipticAtCenturies(0), reference(j2000))).toBeLessThan(0.2)
  })

  it('is inside two thirds of an arcminute at every voyage the map cares about', () => {
    // The number the brief asked for, per date. Measured: Columbus 21.3″, Magellan 18.4″,
    // Barentsz 12.9″, Tasman 12.1″ — all under 0.36 arcminutes.
    for (const label of ['columbus-landfall', 'magellan-departure', 'barentsz-arctic', 'tasman-tasmania']) {
      const index = epochIndex(label)
      const mine = moonEclipticAtCenturies((horizons.epochs[index].jd - 2451545) / 36525)
      expect(arcsec(mine, reference(index)) / 60).toBeLessThan(0.36)
    }
  })

  it('grows into the past and nowhere else — the signature of a secular difference', () => {
    // The residual is not noise. ELP was fitted to LE200; Horizons runs DE441; the two adopt
    // slightly different tidal accelerations for the moon, and a difference in acceleration
    // integrates to a t² divergence. That predicts an error near zero at the fitting epoch and
    // growing steadily backwards, which is exactly the shape measured:
    //
    //     1400-1543   mean 25.4″        1596-1805   mean 8.3″        1835-2050   mean 0.55″
    //
    // Noise would not be ordered like that, and a coding error would not be smallest at J2000.
    const errors = horizons.epochs.map((epoch, index) => ({
      year: new Date(epoch.iso).getUTCFullYear(),
      error: arcsec(moonEclipticAtCenturies((epoch.jd - 2451545) / 36525), reference(index)),
    }))
    const mean = (group) => group.reduce((sum, e) => sum + e.error, 0) / group.length

    const oldest = mean(errors.filter((e) => e.year < 1550))
    const middle = mean(errors.filter((e) => e.year >= 1550 && e.year < 1830))
    const recent = mean(errors.filter((e) => e.year >= 1830))
    expect(oldest).toBeGreaterThan(middle * 2)
    expect(middle).toBeGreaterThan(recent * 5)
    expect(recent).toBeLessThan(1.5)

    // Smallest of all at the epoch the theory was fitted at.
    const atEpoch = errors.find((e) => e.year === 2000).error
    expect(atEpoch).toBeLessThan(Math.min(...errors.filter((e) => e.year !== 2000).map((e) => e.error)) + 0.05)
  })

  it('gets the distance right to a few kilometres, out of four hundred thousand', () => {
    horizons.epochs.forEach((epoch, index) => {
      const mine = moonEclipticAtCenturies((epoch.jd - 2451545) / 36525)
      const reference3 = reference(index)
      const gap = Math.abs(Math.hypot(mine.x, mine.y, mine.z) - Math.hypot(reference3.x, reference3.y, reference3.z))
      expect(gap / 1000).toBeLessThan(4)
    })
  })

  it('beats the Keplerian moon by more than an order of magnitude', () => {
    // moon-kepler.js is an ellipse with three named perturbations and measures about 5 arcminutes.
    // This is the same body solved properly.
    for (const label of ['columbus-landfall', 'tasman-tasmania']) {
      const index = epochIndex(label)
      const date = at(label)
      const elp = moonEclipticAtCenturies((horizons.epochs[index].jd - 2451545) / 36525)
      const elpError = arcsec(elp, reference(index))

      // The Kepler moon works in the ecliptic OF DATE, so precess ELP's J2000 longitude to compare.
      const precessed = precessEclipticToDate(moonEclipticJ2000(date), date)
      const kepler = keplerMoonEcliptic(date)
      const keplerGap = Math.abs(wrapLongitude(precessed.longitude - kepler.longitude)) * 3600

      expect(elpError).toBeLessThan(25)
      expect(keplerGap).toBeGreaterThan(10 * elpError)
    }
  })
})

describe('time scales', () => {
  it('applies ΔT, which at this accuracy is worth a hundred arcseconds at 1492', () => {
    // The moon moves 0.55 arcseconds per second of time. ΔT at 1492 is about 200 seconds, so
    // reading UTC as TT would be 110 arcseconds — five times the theory's own error there, and
    // enough to throw away the entire point of using ELP instead of an ellipse.
    const date = at('columbus-landfall')
    expect(deltaTSeconds(date)).toBeGreaterThan(150)

    const applied = moonEclipticJ2000(date)
    const naive = moonEclipticAtCenturies((date.getTime() / 86400000 + 2440587.5 - 2451545) / 36525)
    expect(arcsec(applied, naive)).toBeGreaterThan(80)
  })

  it('counts centuries, where VSOP87 counts millennia', () => {
    const date = new Date(Date.UTC(2000, 0, 1, 12))
    expect(centuriesTT(date)).toBeCloseTo(deltaTSeconds(date) / 86400 / 36525, 12)
    expect(centuriesTT(new Date(Date.UTC(1900, 0, 1, 12)))).toBeCloseTo(-1, 2)
  })
})

describe('sharing a frame with the planets', () => {
  it('lights the moon from where VSOP87 puts the sun, to a thousandth of a phase', () => {
    // A cross-check between two independent theories through two different frames. The left side
    // builds the phase angle straight from ELP's moon and VSOP87's sun, both in J2000 ecliptic
    // rectangular coordinates. The right side is the module's own answer, which goes out through
    // the sub-lunar point into earth-fixed planet space and takes its sun from sun.js.
    //
    // Nothing forces those to agree except the geometry being right in both. Measured agreement is
    // better than 0.0015 of a phase; the residual is sun.js's own tenth-of-a-degree accuracy.
    for (const label of ['columbus-landfall', 'galileo-moons', 'present-day']) {
      const date = at(label)
      const moon = moonEclipticJ2000(date)
      const sun = apparentSun(date)

      const moonLength = Math.hypot(moon.x, moon.y, moon.z)
      const toEarth = [-moon.x / moonLength, -moon.y / moonLength, -moon.z / moonLength]
      const toSun = [sun.x * AU_METRES - moon.x, sun.y * AU_METRES - moon.y, sun.z * AU_METRES - moon.z]
      const toSunLength = Math.hypot(...toSun)
      const cosPhaseAngle = toSun.reduce((sum, v, i) => sum + (v / toSunLength) * toEarth[i], 0)

      const fromGeometry = (1 + cosPhaseAngle) / 2
      const fromModule = moonPhase(date, sunDirection(date))
      expect(Math.abs(fromGeometry - fromModule)).toBeLessThan(0.0015)
    }
  })

  it('is far closer than the sun, which is the only reason eclipses work', () => {
    const date = at('galileo-moons')
    const moon = moonEclipticJ2000(date).distance
    const sun = Math.hypot(...Object.values(heliocentric('earth', date)).slice(0, 3)) * AU_METRES
    expect(sun / moon).toBeGreaterThan(350)
    expect(sun / moon).toBeLessThan(420)
  })
})
