import { describe, it, expect } from 'vitest'
import { moonPosition, moonVector, moonPhase } from '../moon.js'
import { sunDirection } from '../sun.js'

/**
 * Where the moon is.
 *
 * Checked against facts about the moon rather than against this implementation: it stays between
 * perigee and apogee, it goes round in a synodic month, it drifts eastward against the stars, and
 * it is never further from the equator than the earth's tilt plus its own orbital inclination. A
 * better ephemeris swapped in later still has to satisfy every one of these.
 */

const utc = (y, m, d, h = 0) => new Date(Date.UTC(y, m - 1, d, h))
const PERIGEE_M = 356500000
const APOGEE_M = 406700000
const SYNODIC_DAYS = 29.530588   // new moon to new moon

describe('moonPosition', () => {
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
    // The orbit is genuinely eccentric: perigee and apogee differ by ~50,000 km.
    expect(Math.max(...distances) - Math.min(...distances)).toBeGreaterThan
      ? expect(Math.max(...distances) - Math.min(...distances)).toBeGreaterThan(35000000)
      : null
  })

  it('stays within the tilt of the earth plus the tilt of its own orbit', () => {
    // 23.44° + 5.15° = 28.6°, the furthest north or south the moon can ever be overhead.
    for (let day = 0; day < 400; day += 2) {
      const { lat } = moonPosition(new Date(utc(2026, 1, 1).getTime() + day * 86400000))
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
    // The earth turns 15°/hour under both. The moon also advances ~0.55°/hour eastward along its
    // orbit, so its sub-point falls behind the sun's — that difference IS the lunar month.
    const a = moonPosition(utc(2026, 5, 5, 0)).lng
    const b = moonPosition(utc(2026, 5, 5, 6)).lng
    let drift = a - b
    if (drift < -180) drift += 360
    if (drift > 180) drift -= 360
    expect(drift).toBeGreaterThan(80)     // 6h x 15°/h = 90°, minus the moon's own motion
    expect(drift).toBeLessThan(90)
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
    expect(Math.min(...phases)).toBeLessThan(0.06)   // it is new at some point
    expect(Math.max(...phases)).toBeGreaterThan(0.94) // and full at some point
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
    // Search a month for the fullest moment, then check the geometry at that moment: the angle
    // between the sun and the moon, seen from earth, must be near 180°.
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
