import { describe, it, expect } from 'vitest'
import { moonPosition, moonVector } from '../moon.js'
import { subsolarPoint, sunDirection } from '../sun.js'

/**
 * ABSOLUTE sky tests — where the sun and moon are, not merely how they move.
 *
 * These exist because the existing suites did not catch a twenty-degree error in the moon.
 *
 * Every test in `moon.test.js` is RELATIVE: the drift rate, the length of the synodic month, the
 * range between perigee and apogee, the tilt limit. A moon that is consistently in the wrong place
 * satisfies all of them, because a rigid offset changes none of those quantities. The bug was the
 * epoch — Schlyter's elements count from "2000 January 0.0" (1999-12-31 00:00 UTC), not J2000
 * (2000-01-01 12:00), a day and a half. At 13.18 deg/day that is twenty degrees of moon and about
 * a degree and a half of sun, and it moved the August 2026 eclipse to the 14th.
 *
 * The lesson generalises past this file: a self-consistent wrong answer passes every test written
 * in terms of differences. At least one test has to nail the thing to the sky.
 *
 * The anchors below are chosen so they need no external ephemeris to be checkable by a reader:
 * solstices and equinoxes are defined by the sun's declination, and a total solar eclipse is BY
 * DEFINITION the moon and sun in nearly the same direction at a known minute.
 */

const utc = (y, m, d, h = 0, min = 0) => new Date(Date.UTC(y, m - 1, d, h, min))

const angleBetween = (a, b) => {
  const la = Math.hypot(...a), lb = Math.hypot(...b)
  const cos = (a[0] * b[0] + a[1] * b[1] + a[2] * b[2]) / (la * lb)
  return Math.acos(Math.max(-1, Math.min(1, cos))) * 180 / Math.PI
}

describe('the sun, nailed to the calendar', () => {
  // The solstices and equinoxes ARE the sun's declination. Nothing else defines them.
  it.each([
    ['June solstice 2026', utc(2026, 6, 21, 8), 23.44, 0.2],
    ['December solstice 2026', utc(2026, 12, 21, 20), -23.44, 0.2],
    ['March equinox 2026', utc(2026, 3, 20, 14), 0, 0.3],
    ['September equinox 2026', utc(2026, 9, 23, 0), 0, 0.3],
  ])('%s puts the sun overhead at the right latitude', (_name, date, expected, tolerance) => {
    expect(subsolarPoint(date).lat).toBeCloseTo(expected, 0)
    expect(Math.abs(subsolarPoint(date).lat - expected)).toBeLessThan(tolerance)
  })

  it('is overhead near local noon at the prime meridian, at 12:00 UTC', () => {
    // Longitude of the subsolar point at noon UTC is within a few degrees of 0 all year — the
    // equation of time is worth at most about 4 degrees. A wrong epoch shifts this systematically.
    for (const month of [1, 4, 7, 10]) {
      const { lng } = subsolarPoint(utc(2026, month, 15, 12))
      expect(Math.abs(lng)).toBeLessThan(4.5)
    }
  })
})

describe('the moon, nailed to eclipses', () => {
  /**
   * A total solar eclipse is the moon and the sun in the same direction, seen from earth. That is
   * not a coincidence to be checked loosely — at greatest eclipse the elongation is well under a
   * degree, and no amount of self-consistency can fake it.
   */
  it.each([
    ['1919-05-29 Eddington', utc(1919, 5, 29, 13, 8)],
    ['1999-08-11', utc(1999, 8, 11, 11, 3)],
    ['2024-04-08', utc(2024, 4, 8, 18, 17)],
    ['2026-08-12', utc(2026, 8, 12, 17, 46)],
  ])('%s has the moon in front of the sun', (_name, date) => {
    const elongation = angleBetween(moonVector(date), sunDirection(date))
    // Generous against this ephemeris's own ~0.1 deg accuracy, and still nothing like 20 degrees.
    expect(elongation).toBeLessThan(1.5)
  })

  it('is nowhere near the sun on an ordinary day', () => {
    // Guards the test above from passing because everything collapsed to one direction.
    const date = utc(2024, 4, 23, 12)   // full moon, a fortnight after the 2024 eclipse
    expect(angleBetween(moonVector(date), sunDirection(date))).toBeGreaterThan(150)
  })

  it('reports a sub-lunar point consistent with its own vector', () => {
    const date = utc(2026, 8, 12, 17, 46)
    const { lng, lat } = moonPosition(date)
    const [x, y, z] = moonVector(date)
    const length = Math.hypot(x, y, z)
    expect(Math.asin(y / length) * 180 / Math.PI).toBeCloseTo(lat, 4)
    expect(Math.atan2(z, x) * 180 / Math.PI).toBeCloseTo(lng, 4)
  })
})
