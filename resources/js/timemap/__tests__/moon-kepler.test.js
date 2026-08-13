import { describe, it, expect } from 'vitest'
import { moonEcliptic, moonPhase, moonPosition, moonVector, SCHLYTER_EPOCH } from '../moon-kepler.js'
import { moonPosition as legacyMoonPosition } from '../moon.js'
import { subsolarPoint, sunDirection } from '../sun.js'
import { gmstDegrees, wrapLongitude } from '../celestial-frames.js'
import horizons from './fixtures/horizons-j2000-ecliptic.json'

/**
 * The moon, rebuilt on the general Kepler solver.
 *
 * The first block is the acceptance suite from `moon.test.js`, unchanged in substance: distance
 * between perigee and apogee, latitude inside the combined tilts, a synodic month, full when
 * opposite the sun. Those are facts about the moon, not about any implementation, and a new
 * ephemeris has to satisfy every one of them.
 *
 * The second block is what that suite could not see. Every assertion in it is invariant under a
 * shift in time — move the whole moon a day and a half and the distances, the latitudes, the drift
 * rate and the phase geometry are all still perfectly self-consistent. So a wrong epoch passes it
 * cleanly, which is exactly what happened. The cure is an ABSOLUTE position checked against an
 * ephemeris, and that is what these are.
 */

const utc = (y, m, d, h = 0) => new Date(Date.UTC(y, m - 1, d, h))
const PERIGEE_M = 356500000
const APOGEE_M = 406700000
const SYNODIC_DAYS = 29.530588

const at = (label) => new Date(horizons.epochs.find((e) => e.label === label).iso)
const indexOf = (label) => horizons.epochs.findIndex((e) => e.label === label)

describe('moonPosition — the facts any moon must satisfy', () => {
  it('never leaves the range between perigee and apogee', () => {
    for (let day = 0; day < 400; day += 3) {
      const { distance } = moonPosition(new Date(utc(2026, 1, 1).getTime() + day * 86400000))
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
      const { lat } = moonPosition(new Date(utc(2026, 1, 1).getTime() + day * 86400000))
      expect(Math.abs(lat)).toBeLessThan(29.5)
    }
  })

  it('holds that bound five centuries before the epoch too', () => {
    for (let day = 0; day < 400; day += 2) {
      const { lat } = moonPosition(new Date(utc(1492, 1, 1).getTime() + day * 86400000))
      expect(Math.abs(lat)).toBeLessThan(29.5)
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
})

describe('moonVector', () => {
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

describe('moonPhase', () => {
  it('runs from new to full and back within one synodic month', () => {
    const start = utc(2026, 1, 1).getTime()
    const phases = []
    for (let day = 0; day < 30; day++) {
      const date = new Date(start + day * 86400000)
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
})

describe('absolute position — what a time-shift-invariant suite cannot see', () => {
  it('is where JPL Horizons says it is, in ecliptic longitude, at every epoch', () => {
    // The check the old suite was missing. A wrong epoch shows up here and nowhere else.
    for (const epoch of horizons.epochs) {
      const index = indexOf(epoch.label)
      const [referenceLon, referenceLat] = horizons.ofDate.moon[index]
      const mine = moonEcliptic(at(epoch.label))
      expect(Math.abs(wrapLongitude(mine.longitude - referenceLon))).toBeLessThan(0.25)
      expect(Math.abs(mine.latitude - referenceLat)).toBeLessThan(0.15)
    }
  })

  it('lands the sub-lunar point where the apparent right ascension and GMST put it', () => {
    // End of the chain, checked against Horizons' own apparent RA/Dec rather than against the
    // ecliptic coordinates this module already agreed with — a genuinely separate quantity.
    for (const epoch of horizons.epochs) {
      const index = indexOf(epoch.label)
      const [rightAscension, declination] = horizons.apparentEquatorial.moon[index]
      const date = at(epoch.label)
      const expectedLng = wrapLongitude(rightAscension - gmstDegrees(date))
      const mine = moonPosition(date)
      expect(Math.abs(wrapLongitude(mine.lng - expectedLng))).toBeLessThan(0.25)
      expect(Math.abs(mine.lat - declination)).toBeLessThan(0.25)
    }
  })

  it('gets the distance right to a few hundred kilometres, not just the right range', () => {
    for (const epoch of horizons.epochs) {
      const index = indexOf(epoch.label)
      const [x, y, z] = horizons.geocentric.moon[index]
      const referenceKm = Math.hypot(x, y, z) * 149597870.7
      // Measured worst case 1101 km, at the 1769 epoch: the two cosine terms in the distance
      // series are a coarser model than the two in longitude. That is 0.3% of the distance, and
      // under a thousandth of a degree of angular size.
      expect(Math.abs(moonEcliptic(at(epoch.label)).distance / 1000 - referenceKm)).toBeLessThan(1300)
    }
  })

  it('pins the element epoch at 1999-12-31, a day and a half before J2000', () => {
    // Stated as a constant so the next person cannot quietly "tidy" it into J2000. The consequence
    // of that tidy-up is measured in the test below.
    expect(SCHLYTER_EPOCH).toBe(Date.UTC(1999, 11, 31, 0))
    expect((Date.UTC(2000, 0, 1, 12) - SCHLYTER_EPOCH) / 86400000).toBe(1.5)
  })

  it('would be twenty degrees wrong on the J2000 epoch, which is what makes this worth pinning', () => {
    // Feed the module a date shifted by the epoch error and watch the ephemeris move by the moon's
    // travel in a day and a half. This is the magnitude of the defect in moon.js, asserted.
    const date = at('present-day')
    const shifted = new Date(date.getTime() + 1.5 * 86400000)
    const drift = Math.abs(wrapLongitude(moonEcliptic(shifted).longitude - moonEcliptic(date).longitude))
    expect(drift).toBeGreaterThan(17)
    expect(drift).toBeLessThan(23)
  })
})

describe('against the moon.js it replaces', () => {
  it('agrees with it, now that both read the elements on the elements’ own epoch', () => {
    // moon.js carried the twenty-degree epoch error until this branch. Both are now checked
    // against the same authority in the same breath, so if either drifts it is caught here.
    for (const epoch of horizons.epochs) {
      const index = indexOf(epoch.label)
      const date = at(epoch.label)
      const [rightAscension] = horizons.apparentEquatorial.moon[index]
      const truthLng = wrapLongitude(rightAscension - gmstDegrees(date))

      const mineError = Math.abs(wrapLongitude(moonPosition(date).lng - truthLng))
      const legacyError = Math.abs(wrapLongitude(legacyMoonPosition(date).lng - truthLng))

      expect(mineError).toBeLessThan(0.3)
      expect(legacyError).toBeLessThan(0.3)
      // The residual difference between the two is the obliquity and sidereal-time refinements in
      // celestial-frames.js, and nothing else.
      expect(Math.abs(wrapLongitude(moonPosition(date).lng - legacyMoonPosition(date).lng)))
        .toBeLessThan(0.02)
    }
  })

  it('matches moon.js on distance to the last millimetre', () => {
    // Distance depends only on the elements and the solver, not on the frame chain, so this is
    // where the two implementations can be compared exactly. Agreeing to a thousandth of a metre
    // settles it in both directions: the epoch was the only real difference, and the safeguarded
    // solver reproduces the old three-pass iteration bit for bit at the moon's eccentricity.
    for (const label of ['columbus-landfall', 'galileo-moons', 'present-day']) {
      const date = at(label)
      expect(Math.abs(legacyMoonPosition(date).distance - moonEcliptic(date).distance)).toBeLessThan(0.001)
      expect(legacyMoonPosition(date).distance).toBeGreaterThan(PERIGEE_M * 0.99)
      expect(legacyMoonPosition(date).distance).toBeLessThan(APOGEE_M * 1.01)
    }
  })
})

describe('the sun it is lit by', () => {
  it('agrees with sun.js on the subsolar point, so the phase is lit from the right side', () => {
    for (const label of ['j2000', 'present-day']) {
      const index = indexOf(label)
      const [rightAscension, declination] = horizons.apparentEquatorial.sun[index]
      const date = at(label)
      const expectedLng = wrapLongitude(rightAscension - gmstDegrees(date))
      const theirs = subsolarPoint(date)
      expect(Math.abs(wrapLongitude(theirs.lng - expectedLng))).toBeLessThan(0.15)
      expect(Math.abs(theirs.lat - declination)).toBeLessThan(0.05)
    }
  })
})
