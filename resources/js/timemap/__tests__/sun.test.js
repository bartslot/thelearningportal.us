import { describe, it, expect } from 'vitest'
import { solarPosition, subsolarPoint, sunDirection } from '../sun.js'

/**
 * Where the sun is.
 *
 * Checked against facts anyone can verify rather than against the implementation: the solstices sit
 * on the tropics, the equinoxes on the equator, and the sun is over the Greenwich meridian at noon
 * UTC give or take the equation of time. If the series is ever swapped for a better one, these
 * still hold.
 */

const utc = (y, m, d, h = 12, min = 0) => new Date(Date.UTC(y, m - 1, d, h, min))

describe('solarPosition', () => {
  it('puts the June solstice sun on the Tropic of Cancer', () => {
    expect(solarPosition(utc(2026, 6, 21)).declination).toBeCloseTo(23.44, 0)
  })

  it('puts the December solstice sun on the Tropic of Capricorn', () => {
    expect(solarPosition(utc(2026, 12, 21)).declination).toBeCloseTo(-23.44, 0)
  })

  it('crosses the equator at the equinoxes', () => {
    expect(Math.abs(solarPosition(utc(2026, 3, 20)).declination)).toBeLessThan(0.6)
    expect(Math.abs(solarPosition(utc(2026, 9, 22, 18)).declination)).toBeLessThan(0.6)
  })

  it('tracks the equation of time — noon is not noon', () => {
    // Its two well-known extremes: the sun runs ~14 min slow in mid-February and ~16 min fast in
    // early November. A model missing this would return roughly zero all year.
    expect(solarPosition(utc(2026, 2, 11)).equationOfTime).toBeLessThan(-12)
    expect(solarPosition(utc(2026, 11, 3)).equationOfTime).toBeGreaterThan(14)
  })
})

describe('subsolarPoint', () => {
  it('sits near Greenwich at noon UTC, offset only by the equation of time', () => {
    const { lng } = subsolarPoint(utc(2026, 6, 21, 12))
    expect(Math.abs(lng)).toBeLessThan(4.5)      // ±16 min of time is ±4° of longitude
  })

  it('is over the far side of the world at midnight UTC', () => {
    const { lng } = subsolarPoint(utc(2026, 6, 21, 0))
    expect(Math.abs(Math.abs(lng) - 180)).toBeLessThan(4.5)
  })

  it('travels west at fifteen degrees an hour', () => {
    const noon = subsolarPoint(utc(2026, 4, 10, 12)).lng
    const later = subsolarPoint(utc(2026, 4, 10, 15)).lng
    expect(noon - later).toBeCloseTo(45, 0)
  })

  it('always reports a longitude in ±180, so callers never handle the wrap', () => {
    for (let hour = 0; hour < 24; hour++) {
      const { lng } = subsolarPoint(utc(2026, 7, 4, hour))
      expect(lng).toBeGreaterThanOrEqual(-180)
      expect(lng).toBeLessThanOrEqual(180)
    }
  })
})

describe('sunDirection', () => {
  it('is a unit vector — shaders normalise nothing', () => {
    for (const month of [1, 4, 7, 10]) {
      const d = sunDirection(utc(2026, month, 15, 9))
      expect(Math.hypot(...d)).toBeCloseTo(1, 9)
    }
  })

  it('points north of the equator in northern summer and south in winter', () => {
    expect(sunDirection(utc(2026, 6, 21))[1]).toBeGreaterThan(0.3)   // +Y is north
    expect(sunDirection(utc(2026, 12, 21))[1]).toBeLessThan(-0.3)
  })

  it('agrees with the subsolar point it came from', () => {
    const date = utc(2026, 5, 2, 7, 30)
    const { lng, lat } = subsolarPoint(date)
    const [x, y, z] = sunDirection(date)
    expect(Math.asin(y) * 180 / Math.PI).toBeCloseTo(lat, 6)
    expect(Math.atan2(z, x) * 180 / Math.PI).toBeCloseTo(lng, 6)
  })
})
