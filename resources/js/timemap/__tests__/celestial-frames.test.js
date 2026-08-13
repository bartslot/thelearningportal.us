import { describe, it, expect } from 'vitest'
import {
  centuriesSinceJ2000,
  daysSinceJ2000,
  eclipticToEquatorial,
  eclipticToSubPoint,
  gmstDegrees,
  obliquityOfEcliptic,
  precessEclipticToDate,
  subPoint,
  wrapLongitude,
} from '../celestial-frames.js'
import { deltaTSeconds } from '../delta-t.js'
import { eclipticSpherical } from '../orbit.js'
import { subsolarPoint } from '../sun.js'
import horizons from './fixtures/horizons-j2000-ecliptic.json'

/**
 * The frame chain, checked against published astronomy and against JPL Horizons — never against
 * what this code happens to print.
 */

const at = (label) => new Date(horizons.epochs.find((e) => e.label === label).iso)
const indexOf = (label) => horizons.epochs.findIndex((e) => e.label === label)
const J2000_DATE = new Date(Date.UTC(2000, 0, 1, 12))

/** Signed difference between two longitudes, taking the short way round. */
const longitudeGap = (a, b) => wrapLongitude(a - b)

describe('day counts', () => {
  it('is zero at the epoch and negative for history', () => {
    expect(daysSinceJ2000(J2000_DATE)).toBe(0)
    expect(centuriesSinceJ2000(J2000_DATE)).toBe(0)
    expect(daysSinceJ2000(at('columbus-landfall'))).toBeLessThan(-185000)
    expect(centuriesSinceJ2000(at('columbus-landfall'))).toBeCloseTo(-5.072, 2)
  })
})

describe('obliquityOfEcliptic', () => {
  it('is 23°26′21.4″ at J2000, the published value', () => {
    expect(obliquityOfEcliptic(J2000_DATE)).toBeCloseTo(23 + 26 / 60 + 21.448 / 3600, 5)
  })

  it('was larger in the past — the tilt is shrinking by 47″ a century', () => {
    const now = obliquityOfEcliptic(J2000_DATE)
    const columbus = obliquityOfEcliptic(at('columbus-landfall'))
    expect(columbus).toBeGreaterThan(now)
    expect(columbus - now).toBeCloseTo(46.815 * 5.077 / 3600, 3)
    expect(columbus).toBeCloseTo(23.505, 3)
  })

  it('agrees with the linear form moon.js uses, so the two modules share a frame', () => {
    for (const year of [1000, 1492, 1700, 2000, 2200, 3000]) {
      const date = new Date(Date.UTC(year, 5, 1))
      const linear = 23.4393 - 3.563e-7 * daysSinceJ2000(date)   // moon.js, verbatim
      expect(Math.abs(obliquityOfEcliptic(date) - linear)).toBeLessThan(0.001)
    }
  })
})

describe('eclipticToEquatorial', () => {
  it('leaves the vernal equinox alone — it is where the two frames touch', () => {
    const { rightAscension, declination } = eclipticToEquatorial({ longitude: 0, latitude: 0 }, J2000_DATE)
    expect(rightAscension).toBeCloseTo(0, 10)
    expect(declination).toBeCloseTo(0, 10)
  })

  it('puts the solstices at the full tilt, north and south', () => {
    const tilt = obliquityOfEcliptic(J2000_DATE)
    const june = eclipticToEquatorial({ longitude: 90, latitude: 0 }, J2000_DATE)
    const december = eclipticToEquatorial({ longitude: 270, latitude: 0 }, J2000_DATE)
    expect(june.rightAscension).toBeCloseTo(90, 10)
    expect(june.declination).toBeCloseTo(tilt, 10)
    expect(december.declination).toBeCloseTo(-tilt, 10)
  })

  it('never sends anything on the ecliptic beyond the tropics', () => {
    for (let longitude = 0; longitude < 360; longitude += 5) {
      const { declination } = eclipticToEquatorial({ longitude, latitude: 0 }, J2000_DATE)
      expect(Math.abs(declination)).toBeLessThanOrEqual(obliquityOfEcliptic(J2000_DATE) + 1e-9)
    }
  })

  it('carries distance through untouched', () => {
    expect(eclipticToEquatorial({ longitude: 33, latitude: 4, distance: 384400000 }, J2000_DATE).distance)
      .toBe(384400000)
  })
})

describe('gmstDegrees', () => {
  it('is 18h 41m 50.5s at J2000, the value the sidereal formula is anchored on', () => {
    expect(gmstDegrees(J2000_DATE) / 15).toBeCloseTo(18.697374558, 6)
  })

  it('comes back to the same value one sidereal day later, not one solar day', () => {
    const start = at('present-day')
    const siderealDayMs = 86164090.5
    expect(Math.abs(longitudeGap(gmstDegrees(new Date(start.getTime() + siderealDayMs)), gmstDegrees(start))))
      .toBeLessThan(0.01)
    // A solar day leaves it very nearly a degree short: that gap is the year.
    expect(Math.abs(longitudeGap(gmstDegrees(new Date(start.getTime() + 86400000)), gmstDegrees(start))))
      .toBeCloseTo(0.9856, 2)
  })

  it('turns 15° per hour, to a part in ten thousand', () => {
    const start = at('barentsz-arctic')
    const gap = longitudeGap(gmstDegrees(new Date(start.getTime() + 3600000)), gmstDegrees(start))
    expect(gap).toBeCloseTo(15.0411, 3)
  })
})

describe('subPoint', () => {
  it('reports longitude in ±180 so callers never handle the wrap', () => {
    for (let hour = 0; hour < 48; hour += 1) {
      const { lng } = subPoint({ rightAscension: 200, declination: 12 }, new Date(Date.UTC(1596, 5, 19, hour)))
      expect(lng).toBeGreaterThanOrEqual(-180)
      expect(lng).toBeLessThan(180)
    }
  })

  it('hands declination straight through as latitude', () => {
    expect(subPoint({ rightAscension: 77, declination: -13.5 }, J2000_DATE).lat).toBe(-13.5)
  })

  it('drifts west at 15° an hour, because that is the earth turning underneath', () => {
    const start = at('present-day')
    const a = subPoint({ rightAscension: 100, declination: 0 }, start).lng
    const b = subPoint({ rightAscension: 100, declination: 0 }, new Date(start.getTime() + 3600000)).lng
    expect(longitudeGap(a, b)).toBeCloseTo(15.04, 2)
  })
})

describe('precessEclipticToDate', () => {
  it('does nothing at the epoch it precesses from', () => {
    const same = precessEclipticToDate({ longitude: 137.5, latitude: 4.2 }, J2000_DATE)
    expect(same.longitude).toBeCloseTo(137.5, 6)
    expect(same.latitude).toBeCloseTo(4.2, 6)
  })

  it('moves the equinox 1.396° a century, the published rate of general precession', () => {
    const century = new Date(Date.UTC(2100, 0, 1, 12))
    const moved = precessEclipticToDate({ longitude: 0, latitude: 0 }, century)
    expect(moved.longitude).toBeCloseTo(5029.0966 / 3600, 3)
  })

  it('runs backwards for history — seven degrees at 1492', () => {
    const moved = precessEclipticToDate({ longitude: 0, latitude: 0 }, at('columbus-landfall'))
    expect(wrapLongitude(moved.longitude)).toBeCloseTo(-7.077, 2)
  })

  it('matches JPL Horizons, in latitude as well as longitude, for the moon at 1492', () => {
    // The moon sits 5° off the ecliptic, which is where ignoring the tipping of the ecliptic plane
    // itself shows up. Longitude carries a known ~0.03° offset because the Horizons of-date table
    // is stamped in UT while its vectors are in TDB; latitude has no such excuse.
    for (const label of ['columbus-landfall', 'galileo-moons', 'present-day']) {
      const index = indexOf(label)
      const [x, y, z] = horizons.geocentric.moon[index]
      const j2000 = eclipticSpherical({ x, y, z })
      const moved = precessEclipticToDate(j2000, at(label))
      const [referenceLon, referenceLat] = horizons.ofDate.moon[index]
      expect(Math.abs(moved.latitude - referenceLat)).toBeLessThan(0.005)
      expect(Math.abs(longitudeGap(moved.longitude, referenceLon))).toBeLessThan(0.06)
    }
  })

  it('beats the shortcut of shifting longitude and leaving latitude alone', () => {
    const index = indexOf('columbus-landfall')
    const [x, y, z] = horizons.geocentric.moon[index]
    const j2000 = eclipticSpherical({ x, y, z })
    const [, referenceLat] = horizons.ofDate.moon[index]
    const shortcutError = Math.abs(j2000.latitude - referenceLat)   // longitude-only precession
    const fullError = Math.abs(precessEclipticToDate(j2000, at('columbus-landfall')).latitude - referenceLat)
    expect(shortcutError).toBeGreaterThan(0.05)
    expect(fullError).toBeLessThan(0.005)
  })
})

describe('eclipticToSubPoint', () => {
  it('agrees with sun.js on where the sun is overhead, in the era sun.js is written for', () => {
    // sun.js reaches the subsolar point by an entirely different road — declination plus the
    // equation of time, no vectors anywhere. Feeding this chain the JPL sun vector and landing in
    // the same place is the strongest available evidence that the frames line up.
    for (const label of ['j2000', 'present-day']) {
      const index = indexOf(label)
      const [x, y, z] = horizons.geocentric.sun[index]
      const mine = eclipticToSubPoint(eclipticSpherical({ x, y, z }), at(label), { fromJ2000: true })
      const theirs = subsolarPoint(at(label))
      expect(Math.abs(longitudeGap(mine.lng, theirs.lng))).toBeLessThan(0.05)
      expect(Math.abs(mine.lat - theirs.lat)).toBeLessThan(0.02)
    }
  })

  it('still agrees with sun.js five centuries before the epoch', () => {
    // sun.js only claims 1950–2050. It happens to hold up further, but loosely, so this asserts
    // what is actually true rather than what would be nice.
    for (const label of ['columbus-landfall', 'cook-venus-transit']) {
      const index = indexOf(label)
      const [x, y, z] = horizons.geocentric.sun[index]
      const mine = eclipticToSubPoint(eclipticSpherical({ x, y, z }), at(label), { fromJ2000: true })
      const theirs = subsolarPoint(at(label))
      expect(Math.abs(longitudeGap(mine.lng, theirs.lng))).toBeLessThan(0.6)
      expect(Math.abs(mine.lat - theirs.lat)).toBeLessThan(0.2)
    }
  })

  it('lands the better part of seven degrees out at 1492 if J2000 coordinates are not precessed', () => {
    // The failure this flag exists to prevent, asserted so it cannot come back silently. The gap
    // is 6.8° rather than the 7.08° of general precession itself, because the tilt through the
    // obliquity does not carry an ecliptic longitude shift into right ascension one for one.
    const index = indexOf('columbus-landfall')
    const [x, y, z] = horizons.geocentric.sun[index]
    const ecliptic = eclipticSpherical({ x, y, z })
    const date = at('columbus-landfall')
    const correct = eclipticToSubPoint(ecliptic, date, { fromJ2000: true })
    const unprecessed = eclipticToSubPoint(ecliptic, date, { fromJ2000: false })
    const gap = Math.abs(longitudeGap(correct.lng, unprecessed.lng))
    expect(gap).toBeGreaterThan(6.5)
    expect(gap).toBeLessThan(7.3)
  })

  it('keeps the sub-solar latitude inside the tropics for a whole year', () => {
    const start = Date.UTC(1596, 0, 1)
    for (let day = 0; day < 366; day += 5) {
      const date = new Date(start + day * 86400000)
      expect(Math.abs(subsolarPoint(date).lat)).toBeLessThan(23.6)
    }
  })
})

describe('deltaTSeconds', () => {
  it('matches the published anchor values across two millennia', () => {
    // Espenak & Meeus, the values their table is built to reproduce.
    const anchors = [[1000, 1570], [1500, 198], [1600, 120], [1700, 9], [1800, 13.7], [1900, -2.8], [2000, 63.8]]
    for (const [year, expected] of anchors) {
      const got = deltaTSeconds(new Date(Date.UTC(year, 0, 1)))
      expect(Math.abs(got - expected)).toBeLessThan(Math.max(6, Math.abs(expected) * 0.06))
    }
  })

  it('is about three and a half minutes at the time of Columbus', () => {
    expect(deltaTSeconds(at('columbus-landfall'))).toBeGreaterThan(150)
    expect(deltaTSeconds(at('columbus-landfall'))).toBeLessThan(260)
  })

  it('costs the moon well under a twentieth of a degree at 1492', () => {
    // 0.55°/hour of apparent motion times ΔT. This is the whole reason it is safe to leave out.
    const drift = 0.55 * deltaTSeconds(at('columbus-landfall')) / 3600
    expect(drift).toBeLessThan(0.05)
  })

  it('is continuous across every branch boundary', () => {
    // Piecewise polynomials joined by hand are exactly where a typo hides. A jump at a seam is one.
    for (const year of [-500, 500, 1600, 1700, 1800, 1860, 1900, 1920, 1941, 1961, 1986, 2005, 2050, 2150]) {
      const before = deltaTSeconds(new Date(Date.UTC(year, 0, 1)))
      const after = deltaTSeconds(new Date(Date.UTC(year, 1, 1)))
      expect(Math.abs(after - before)).toBeLessThan(12)
    }
  })

  it('grows in both directions away from the present, as a slowing earth demands', () => {
    expect(deltaTSeconds(new Date(Date.UTC(-1000, 0, 1)))).toBeGreaterThan(deltaTSeconds(new Date(Date.UTC(0, 0, 1))))
    expect(deltaTSeconds(new Date(Date.UTC(2400, 0, 1)))).toBeGreaterThan(deltaTSeconds(new Date(Date.UTC(2100, 0, 1))))
  })
})
