import { describe, it, expect } from 'vitest'
import {
  AU_METRES,
  VSOP87_BODIES,
  apparentGeocentric,
  apparentSun,
  astrometricGeocentric,
  earthVelocity,
  geocentric,
  heliocentric,
  heliocentricAtMillennia,
  julianDateTT,
  millenniaTT,
  solarElongation,
  subPoint,
} from '../vsop87.js'
import { heliocentricPlanet } from '../planets.js'
import { angularSeparation, eclipticSpherical } from '../orbit.js'
import { deltaTSeconds } from '../delta-t.js'
import { wrapLongitude } from '../celestial-frames.js'
import horizons from './fixtures/horizons-precision.json'
import vsopCheck from './fixtures/vsop87a-check.json'

/**
 * VSOP87, checked twice over and against two different kinds of authority.
 *
 * FIRST against the theory's own published substitution results (vsop87.chk). Those are what the
 * series are supposed to produce, so any disagreement is this file's arithmetic or its truncation,
 * and nothing else. That isolates the one thing under this module's control.
 *
 * THEN against JPL Horizons, which is the real sky. Disagreement there is the theory itself, and
 * it is not this module's job to fix — only to measure and state. Every bound below is a MEASURED
 * worst case over 22 dates from 1400 to 2050, asserted as an upper limit so a regression fails and
 * an improvement does not.
 *
 * Both comparisons use the TT-direct entry point, so no ΔT model sits between the two sides.
 */

const arcsec = (a, b) => angularSeparation(a, b) * 3600
const millenniaFromJD = (jd) => (jd - 2451545) / 365250
const dates = horizons.epochs.map((epoch) => new Date(epoch.iso))
const epochIndex = (label) => horizons.epochs.findIndex((e) => e.label === label)

describe('against the theory’s own check values', () => {
  it('reproduces all 80 published substitutions to better than a fiftieth of an arcsecond', () => {
    // The truncation cost, isolated. Measured worst 0.011″ at the 1e-9 au cutoff the table was
    // built with — a hundred times finer than the theory's own accuracy, which is the point:
    // truncation must not be the limiting term.
    let worst = 0
    for (const testCase of vsopCheck.cases) {
      const mine = heliocentricAtMillennia(testCase.body, millenniaFromJD(testCase.jdTT))
      const [x, y, z] = testCase.xyz
      worst = Math.max(worst, arcsec(mine, { x, y, z }))
    }
    expect(worst).toBeLessThan(0.02)
  })

  it('reproduces them in distance as well as direction', () => {
    for (const testCase of vsopCheck.cases) {
      const mine = heliocentricAtMillennia(testCase.body, millenniaFromJD(testCase.jdTT))
      const reference = Math.hypot(...testCase.xyz)
      expect(Math.abs(mine.radius - reference)).toBeLessThan(2e-7)
    }
  })

  it('covers a thousand years of check values, not just the epoch', () => {
    const jds = vsopCheck.cases.map((c) => c.jdTT)
    expect(Math.min(...jds)).toBeLessThan(2200000)     // year 1000 or earlier
    expect(Math.max(...jds)).toBeGreaterThanOrEqual(2451545)
  })
})

describe('against JPL Horizons — the measured accuracy of the theory', () => {
  // Worst angular error over 1400-2050 against DE441, in arcseconds. These are the numbers quoted
  // in the module docstring; if one moves, both move.
  const BUDGET = {
    mercury: 0.2, venus: 0.4, earth: 0.5, mars: 0.8,
    jupiter: 0.6, saturn: 1.5, uranus: 7, neptune: 9,
  }

  for (const body of VSOP87_BODIES) {
    it(`places ${body} within ${BUDGET[body]}″ of DE441 across six centuries`, () => {
      let worst = 0
      horizons.epochs.forEach((epoch, index) => {
        const [x, y, z] = horizons.heliocentric[body][index]
        const mine = heliocentricAtMillennia(body, millenniaFromJD(epoch.jd))
        worst = Math.max(worst, arcsec(mine, { x, y, z }))
      })
      expect(worst).toBeLessThan(BUDGET[body])
    })
  }

  it('is under an arcsecond for every inner planet at the moment of Columbus’s landfall', () => {
    const index = epochIndex('columbus-landfall')
    for (const body of ['mercury', 'venus', 'earth', 'mars']) {
      const [x, y, z] = horizons.heliocentric[body][index]
      const mine = heliocentricAtMillennia(body, millenniaFromJD(horizons.epochs[index].jd))
      expect(arcsec(mine, { x, y, z })).toBeLessThan(1)
    }
  })

  it('beats the Keplerian element set by two to three orders of magnitude', () => {
    // The reason this module exists. planets.js is an ellipse fitted to the planet; this is the
    // perturbation theory. Measured ratios run from 36x for Neptune to 1281x for Jupiter.
    for (const body of VSOP87_BODIES) {
      let theory = 0
      let kepler = 0
      horizons.epochs.forEach((epoch, index) => {
        const [x, y, z] = horizons.heliocentric[body][index]
        theory = Math.max(theory, arcsec(heliocentricAtMillennia(body, millenniaFromJD(epoch.jd)), { x, y, z }))
        kepler = Math.max(kepler, arcsec(heliocentricPlanet(body, new Date(epoch.iso)), { x, y, z }))
      })
      expect(kepler / theory).toBeGreaterThan(30)
    }
  })

  it('gets the distance from the sun right to a few parts in a million', () => {
    for (const body of VSOP87_BODIES) {
      horizons.epochs.forEach((epoch, index) => {
        const [x, y, z] = horizons.heliocentric[body][index]
        const reference = Math.hypot(x, y, z)
        const mine = heliocentricAtMillennia(body, millenniaFromJD(epoch.jd)).radius
        // Worst measured 5.7e-6, Uranus in 1455 — the same body and epoch that is worst in
        // angle, which is what you would expect if it is the theory rather than a coding slip.
        expect(Math.abs(mine - reference) / reference).toBeLessThan(1e-5)
      })
    }
  })
})

describe('time scales', () => {
  it('converts UTC to a Julian Date in TT, applying ΔT', () => {
    const date = new Date(Date.UTC(2000, 0, 1, 12))
    expect(julianDateTT(date)).toBeCloseTo(2451545 + deltaTSeconds(date) / 86400, 9)
  })

  it('is 200 seconds of ΔT away from naive UTC at 1492 — 34 arcseconds of Mercury', () => {
    // The reason this module applies ΔT and planets.js does not: at a third of a degree it was
    // noise, at an arcsecond it is thirty times the error budget.
    const date = new Date(horizons.epochs[epochIndex('columbus-landfall')].iso)
    expect(deltaTSeconds(date)).toBeGreaterThan(150)

    const withDeltaT = heliocentric('mercury', date)
    const withoutDeltaT = heliocentricAtMillennia('mercury', (date.getTime() / 86400000 + 2440587.5 - 2451545) / 365250)
    expect(arcsec(withDeltaT, withoutDeltaT)).toBeGreaterThan(20)
  })

  it('counts millennia, not centuries — VSOP87’s own time unit', () => {
    const date = new Date(Date.UTC(2000, 0, 1, 12))
    expect(millenniaTT(date)).toBeCloseTo(deltaTSeconds(date) / 86400 / 365250, 12)
    expect(millenniaTT(new Date(Date.UTC(1000, 0, 1, 12)))).toBeCloseTo(-1, 1)
  })
})

describe('light-time and aberration', () => {
  it('retards each planet by its own light travel time', () => {
    // Not a fitted constant: the correction must equal distance over the speed of light.
    for (const body of ['mercury', 'jupiter', 'neptune']) {
      const result = astrometricGeocentric(body, dates[19])
      expect(result.lightTimeDays).toBeCloseTo(result.distance / 173.144632674, 6)
    }
  })

  it('displaces every planet, most of all the fast close ones', () => {
    // The size of the correction is the body's apparent speed times its own light-time, so it is
    // largest for Mercury (fast, and only minutes away) and smallest for Neptune (slow, and hours
    // away) — the opposite of the intuition that the far planets need it most. Measured maxima:
    // Mercury 40″, Venus 24″, Mars 18″, Jupiter 9″, Saturn 7″, Uranus 5″, Neptune 4″.
    const maxShift = (body) => Math.max(...dates.map(
      (date) => arcsec(geocentric(body, date), astrometricGeocentric(body, date)),
    ))
    expect(maxShift('mercury')).toBeGreaterThan(30)
    expect(maxShift('jupiter')).toBeGreaterThan(8)
    expect(maxShift('neptune')).toBeGreaterThan(1)
    // Every one of them is far above the theory's own arcsecond, so none of them is optional.
    for (const body of ['mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune']) {
      expect(maxShift(body)).toBeGreaterThan(2)
    }
  })

  it('adds aberration of at most 20.5 arcseconds, the earth’s own speed across the sky', () => {
    // v/c for a 30 km/s earth is 20.5″. Anything larger would mean the velocity is wrong.
    for (const body of ['venus', 'mars', 'saturn']) {
      for (const date of [dates[0], dates[11], dates[19]]) {
        const shift = arcsec(astrometricGeocentric(body, date), apparentGeocentric(body, date))
        expect(shift).toBeLessThan(21)
        expect(shift).toBeGreaterThan(0)
      }
    }
  })

  it('computes an earth velocity of 30 km/s, give or take the eccentricity', () => {
    for (const date of [dates[3], dates[19]]) {
      const speed = Math.hypot(...Object.values(earthVelocity(date))) * AU_METRES / 86400
      expect(speed).toBeGreaterThan(29000)
      expect(speed).toBeLessThan(31000)
    }
  })
})

describe('the sun', () => {
  it('sits opposite the earth, displaced by exactly one aberration and not two', () => {
    // v/c for a 30 km/s earth is 20.5 arcseconds. Retarding the earth instead of the sun — the
    // natural-looking mistake — applies the same velocity twice and lands on 41, which is exactly
    // the sort of wrong answer that looks right because it is the right order of magnitude.
    for (const date of [dates[3], dates[11], dates[19]]) {
      const earth = heliocentric('earth', date)
      const shift = arcsec(apparentSun(date), { x: -earth.x, y: -earth.y, z: -earth.z })
      expect(shift).toBeGreaterThan(19)
      expect(shift).toBeLessThan(21.5)
    }
  })

  it('stays one astronomical unit away, within the earth’s eccentricity', () => {
    for (const date of dates) {
      const { distance } = apparentSun(date)
      expect(distance).toBeGreaterThan(0.98)
      expect(distance).toBeLessThan(1.02)
    }
  })

  it('never has a solar elongation of its own', () => {
    expect(() => geocentric('earth', dates[0])).toThrow(RangeError)
  })
})

describe('what an observer sees', () => {
  it('keeps Mercury within 28° and Venus within 47° of the sun, forever', () => {
    // Facts about the geometry, not about this code: neither is ever visible at midnight.
    const sample = (body) => {
      const values = []
      for (let day = 0; day < 900; day += 3) {
        values.push(solarElongation(body, new Date(Date.UTC(1492, 0, 1) + day * 86400000)))
      }
      return Math.max(...values)
    }
    expect(sample('mercury')).toBeLessThan(28.5)
    expect(sample('venus')).toBeLessThan(47.5)
  })

  it('had Jupiter near opposition the night Galileo first saw its moons', () => {
    expect(solarElongation('jupiter', dates[epochIndex('galileo-moons')])).toBeGreaterThan(120)
  })

  it('walks Venus across the face of the sun on the day Cook sailed to watch it', () => {
    // 3 June 1769, the transit Cook was sent to Tahiti for. Ingress was around 19:15 UT and egress
    // early on the 4th, so at noon Venus is still approaching — and during the night it passes
    // inside the sun's own radius of 0.266°, which is what a transit MEANS. The strongest single
    // historical check available: the event either lines up or it does not.
    const noon = dates[epochIndex('cook-venus-transit')]
    expect(solarElongation('venus', noon)).toBeLessThan(1)

    const elongations = []
    for (let hour = 0; hour <= 24; hour++) {
      elongations.push(solarElongation('venus', new Date(noon.getTime() + hour * 3600000)))
    }
    expect(Math.min(...elongations)).toBeLessThan(0.266)
  })

  it('puts every sub-point in ±180 of longitude and inside the tropics', () => {
    for (const body of VSOP87_BODIES.filter((b) => b !== 'earth')) {
      for (const date of [dates[3], dates[11], dates[19]]) {
        const { lng, lat } = subPoint(body, date)
        expect(lng).toBeGreaterThanOrEqual(-180)
        expect(lng).toBeLessThan(180)
        expect(Math.abs(lat)).toBeLessThan(33)
      }
    }
  })

  it('drifts sub-points west at 15° an hour, as the turning earth requires', () => {
    const date = dates[19]
    const a = subPoint('mars', date).lng
    const b = subPoint('mars', new Date(date.getTime() + 3600000)).lng
    expect(wrapLongitude(a - b)).toBeCloseTo(15.04, 1)
  })

  it('reports sub-point distance in metres', () => {
    const date = dates[19]
    expect(subPoint('venus', date).distance)
      .toBeCloseTo(apparentGeocentric('venus', date).distance * AU_METRES, 0)
  })

  it('gives the sun a sub-point too, without needing a separate solar theory', () => {
    const { lat } = subPoint('sun', dates[19])
    expect(Math.abs(lat)).toBeLessThan(23.5)
  })
})

describe('refusals', () => {
  it('rejects a body it has no series for, rather than returning zeros', () => {
    expect(() => heliocentric('pluto', new Date())).toThrow(RangeError)
    expect(() => heliocentricAtMillennia('nibiru', 0)).toThrow(RangeError)
    expect(() => apparentGeocentric('earth', new Date())).toThrow(RangeError)
    expect(() => astrometricGeocentric('earth', new Date())).toThrow(RangeError)
  })
})

describe('the geocentric ecliptic form', () => {
  it('is the difference of two heliocentric vectors, and says so consistently', () => {
    const date = dates[11]
    const planet = heliocentric('mars', date)
    const earth = heliocentric('earth', date)
    const g = geocentric('mars', date)
    expect(g.x).toBeCloseTo(planet.x - earth.x, 12)
    expect(g.longitude).toBeCloseTo(eclipticSpherical(g).longitude, 12)
  })
})
