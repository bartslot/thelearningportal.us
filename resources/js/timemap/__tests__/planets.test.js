import { describe, it, expect } from 'vitest'
import {
  AU_METRES,
  PLANET_ELEMENTS,
  PLANET_NAMES,
  geocentricPlanet,
  heliocentricPlanet,
  planetElementsAt,
  planetSubPoint,
  solarElongation,
} from '../planets.js'
import { angularSeparation, eclipticSpherical } from '../orbit.js'
import { precessEclipticToDate, wrapLongitude } from '../celestial-frames.js'
import horizons from './fixtures/horizons-j2000-ecliptic.json'

/**
 * The planets, against JPL Horizons at six dates spanning 1492 to 2026.
 *
 * The accuracy tests assert the errors MEASURED against Horizons, upper bound only, so that a
 * regression fails loudly and an improvement does not. The numbers in the module docstring are
 * these numbers; if one moves, both move.
 *
 * Six dates is a spot check rather than a bound. Where a genuine guarantee is wanted, Standish's
 * own figure for the table is the one to quote.
 */

const DEG = Math.PI / 180
// The heliocentric fixture is keyed by JPL body; the earth's row is the Earth/Moon barycentre,
// which is the body the element table is actually fitted to.
const FIXTURE_KEY = { earth: 'embary' }
const fixtureFor = (name) => horizons.heliocentric[FIXTURE_KEY[name] || name]

const dates = horizons.epochs.map((epoch) => new Date(epoch.iso))

/** Worst error over every reference epoch, for one planet, under some measurement. */
const worstOverEpochs = (measure) => Math.max(...dates.map((date, index) => measure(date, index)))

describe('the element table itself', () => {
  it('carries all eight planets, each with six elements and their rates', () => {
    expect(PLANET_NAMES).toEqual(['mercury', 'venus', 'earth', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'])
    for (const name of PLANET_NAMES) {
      const table = PLANET_ELEMENTS[name]
      for (const key of ['semiMajorAxis', 'eccentricity', 'inclination', 'meanLongitude', 'longitudeOfPerihelion', 'ascendingNode']) {
        expect(table[key]).toHaveLength(2)
        expect(Number.isFinite(table[key][0])).toBe(true)
        expect(Number.isFinite(table[key][1])).toBe(true)
      }
    }
  })

  it('gives the outer four the great-inequality terms and the inner four none', () => {
    for (const name of ['mercury', 'venus', 'earth', 'mars']) {
      expect(PLANET_ELEMENTS[name].greatInequality).toBeUndefined()
    }
    for (const name of ['jupiter', 'saturn', 'uranus', 'neptune']) {
      expect(PLANET_ELEMENTS[name].greatInequality).toBeDefined()
    }
  })

  it('puts every planet on a closed, mildly eccentric orbit at every epoch', () => {
    for (const name of PLANET_NAMES) {
      for (const date of dates) {
        const elements = planetElementsAt(name, date)
        expect(elements.eccentricity).toBeGreaterThanOrEqual(0)
        expect(elements.eccentricity).toBeLessThan(0.25)
        expect(Math.abs(elements.inclination)).toBeLessThan(10)
      }
    }
  })

  it('orders the planets by distance from the sun, as their names promise', () => {
    const radii = PLANET_NAMES.map((name) => planetElementsAt(name, dates[0]).semiMajorAxis)
    for (let n = 1; n < radii.length; n++) expect(radii[n]).toBeGreaterThan(radii[n - 1])
  })

  it('refuses a planet it has no elements for', () => {
    expect(() => planetElementsAt('pluto', new Date())).toThrow(RangeError)
    expect(() => planetElementsAt('vulcan', new Date())).toThrow(RangeError)
  })

  it('moves the elements with time, rather than freezing them at J2000', () => {
    // Saturn's perihelion drifts 2.75° over the five centuries back to Columbus. If a future
    // refactor drops the rate terms, this is where it shows.
    const now = planetElementsAt('saturn', new Date(Date.UTC(2000, 0, 1, 12)))
    const then = planetElementsAt('saturn', dates[0])
    const perihelionDrift = Math.abs(wrapLongitude(
      (now.ascendingNode + now.argumentOfPeriapsis) - (then.ascendingNode + then.argumentOfPeriapsis),
    ))
    expect(perihelionDrift).toBeGreaterThan(2.5)
    expect(perihelionDrift).toBeLessThan(3.0)
  })
})

describe('heliocentric position against JPL Horizons', () => {
  // Worst |Δ ecliptic longitude| over the six epochs, in degrees, measured.
  const LONGITUDE_BUDGET = {
    mercury: 0.007, venus: 0.004, earth: 0.004, mars: 0.035,
    jupiter: 0.15, saturn: 0.33, uranus: 0.21, neptune: 0.09,
  }

  for (const name of PLANET_NAMES) {
    it(`places ${name} within ${LONGITUDE_BUDGET[name]}° of where Horizons has it`, () => {
      const worst = worstOverEpochs((date, index) => {
        const [x, y, z] = fixtureFor(name)[index]
        const mine = eclipticSpherical(heliocentricPlanet(name, date))
        return Math.abs(wrapLongitude(mine.longitude - eclipticSpherical({ x, y, z }).longitude))
      })
      expect(worst).toBeLessThan(LONGITUDE_BUDGET[name])
    })
  }

  it('gets the distance from the sun right to well under a percent', () => {
    for (const name of PLANET_NAMES) {
      const worst = worstOverEpochs((date, index) => {
        const [x, y, z] = fixtureFor(name)[index]
        const reference = Math.hypot(x, y, z)
        return Math.abs(heliocentricPlanet(name, date).radius - reference) / reference
      })
      expect(worst).toBeLessThan(0.002)
    }
  })

  it('is no worse at 1492 than it is today, because the rates are in the table', () => {
    // The claim the secular terms exist to support, tested rather than asserted in a comment.
    for (const name of PLANET_NAMES) {
      const [x, y, z] = fixtureFor(name)[0]
      const separation = angularSeparation(heliocentricPlanet(name, dates[0]), { x, y, z })
      expect(separation).toBeLessThan(0.35)
    }
  })
})

describe('geocentric position — what an observer actually sees', () => {
  const SEPARATION_BUDGET = {
    mercury: 0.005, venus: 0.015, mars: 0.02,
    jupiter: 0.16, saturn: 0.36, uranus: 0.21, neptune: 0.09,
  }

  for (const name of PLANET_NAMES.filter((n) => n !== 'earth')) {
    it(`sees ${name} within ${SEPARATION_BUDGET[name]}° of the true direction`, () => {
      const worst = worstOverEpochs((date, index) => {
        const [px, py, pz] = fixtureFor(name)[index]
        const [ex, ey, ez] = horizons.heliocentric.embary[index]
        return angularSeparation(geocentricPlanet(name, date), { x: px - ex, y: py - ey, z: pz - ez })
      })
      expect(worst).toBeLessThan(SEPARATION_BUDGET[name])
    })
  }

  it('refuses to compute the earth as seen from the earth', () => {
    expect(() => geocentricPlanet('earth', new Date())).toThrow(RangeError)
  })

  it('agrees with Horizons’ own of-date table for Jupiter, precession and all', () => {
    // A different quantity from a different Horizons table: apparent ecliptic longitude referred
    // to the equinox of date. Getting here needs the geocentric vector AND the precession right,
    // so it exercises the one flag that is worth seven degrees.
    const worst = worstOverEpochs((date, index) => {
      const precessed = precessEclipticToDate(geocentricPlanet('jupiter', date), date)
      return Math.abs(wrapLongitude(precessed.longitude - horizons.ofDate.jupiter[index][0]))
    })
    expect(worst).toBeLessThan(0.2)
  })

  it('would be seven degrees adrift at 1492 without precessing the J2000 elements', () => {
    const date = dates[0]
    const raw = geocentricPlanet('jupiter', date)
    const gap = Math.abs(wrapLongitude(precessEclipticToDate(raw, date).longitude - raw.longitude))
    expect(gap).toBeGreaterThan(7.0)
    expect(gap).toBeLessThan(7.2)
  })
})

describe('the great-inequality terms', () => {
  it('are worth a degree at 1492 for Uranus, which is why they are not optional', () => {
    // Recompute the Table 2b correction directly and check it against the module docstring's claim.
    const T = (dates[0].getTime() - Date.UTC(2000, 0, 1, 12)) / 86400000 / 36525
    const correction = (name) => {
      const { b, c, s, f } = PLANET_ELEMENTS[name].greatInequality
      return b * T * T + c * Math.cos(f * T * DEG) + s * Math.sin(f * T * DEG)
    }
    expect(Math.abs(correction('uranus'))).toBeCloseTo(0.857, 2)
    expect(Math.abs(correction('neptune'))).toBeCloseTo(0.585, 2)
    expect(Math.abs(correction('saturn'))).toBeCloseTo(0.356, 2)
    expect(Math.abs(correction('jupiter'))).toBeCloseTo(0.151, 2)
  })

  it('vanish at the epoch they are referred to, apart from the standing cosine', () => {
    const T = 0
    for (const name of ['jupiter', 'saturn', 'uranus', 'neptune']) {
      const { b, c, s, f } = PLANET_ELEMENTS[name].greatInequality
      expect(b * T * T + c * Math.cos(f * T * DEG) + s * Math.sin(f * T * DEG)).toBeCloseTo(c, 12)
    }
  })
})

describe('planetSubPoint', () => {
  it('reports longitude in ±180 and latitude inside the tropics-plus-inclination band', () => {
    for (const name of PLANET_NAMES.filter((n) => n !== 'earth')) {
      for (const date of dates) {
        const { lng, lat } = planetSubPoint(name, date)
        expect(lng).toBeGreaterThanOrEqual(-180)
        expect(lng).toBeLessThan(180)
        // Nothing in the solar system strays far from the ecliptic, so nothing can appear
        // overhead beyond the obliquity plus its own inclination.
        expect(Math.abs(lat)).toBeLessThan(23.5 + 10)
      }
    }
  })

  it('drifts west at very nearly 15° an hour, as everything in the sky does', () => {
    const date = dates[5]
    const a = planetSubPoint('mars', date).lng
    const b = planetSubPoint('mars', new Date(date.getTime() + 3600000)).lng
    expect(wrapLongitude(a - b)).toBeCloseTo(15.04, 1)
  })

  it('returns a distance in metres, matching the geocentric distance in au', () => {
    const date = dates[4]
    expect(planetSubPoint('venus', date).distance)
      .toBeCloseTo(geocentricPlanet('venus', date).distance * AU_METRES, 0)
  })
})

describe('solarElongation', () => {
  it('keeps the inner planets near the sun and lets the outer ones reach opposition', () => {
    // Mercury never gets more than 28° from the sun and Venus never more than 47°, which is why
    // neither is ever visible at midnight. This is a fact about the geometry, not about the code.
    const sample = (name) => {
      const values = []
      for (let day = 0; day < 800; day += 4) {
        values.push(solarElongation(name, new Date(Date.UTC(1596, 0, 1) + day * 86400000)))
      }
      return values
    }
    expect(Math.max(...sample('mercury'))).toBeLessThan(28.5)
    expect(Math.max(...sample('venus'))).toBeLessThan(47.5)
    expect(Math.max(...sample('mars'))).toBeGreaterThan(170)
    expect(Math.max(...sample('jupiter'))).toBeGreaterThan(175)
  })

  it('is always a real angle between 0 and 180', () => {
    for (const name of PLANET_NAMES.filter((n) => n !== 'earth')) {
      for (const date of dates) {
        const angle = solarElongation(name, date)
        expect(angle).toBeGreaterThanOrEqual(0)
        expect(angle).toBeLessThanOrEqual(180)
      }
    }
  })

  it('had Jupiter well clear of the sun the night Galileo first saw its moons', () => {
    // 7 January 1610, Julian. He could only have seen them because Jupiter was near opposition;
    // if this module put it in the glare, something upstream would be wrong.
    const galileo = dates[2]
    expect(solarElongation('jupiter', galileo)).toBeGreaterThan(120)
  })
})
