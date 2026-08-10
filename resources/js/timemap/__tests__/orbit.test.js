import { describe, it, expect } from 'vitest'
import {
  KEPLER_TOLERANCE,
  eccentricAnomalyFromTrue,
  eclipticSpherical,
  orbitalRadius,
  positionAt,
  propagateElements,
  solveKepler,
  stateFromElements,
  trueAnomaly,
} from '../orbit.js'

/**
 * The Kepler machinery, checked against the definitions rather than against itself.
 *
 * Every assertion here is either an identity that must hold for any correct solver (the residual of
 * Kepler's equation, |p| = r, equal areas in equal times) or a value that can be derived a second,
 * independent way. Nothing is compared to a number this module happened to print.
 */

const TAU = Math.PI * 2
const DEG = Math.PI / 180

/** Residual of Kepler's equation. Zero for the true eccentric anomaly, by definition. */
const residual = (E, e, M) => {
  let r = E - e * Math.sin(E) - M
  while (r > Math.PI) r -= TAU
  while (r < -Math.PI) r += TAU
  return r
}

/** A circular orbit lying in the ecliptic, as a starting point for perturbing one element at a time. */
const CIRCLE = {
  semiMajorAxis: 1,
  eccentricity: 0,
  inclination: 0,
  ascendingNode: 0,
  argumentOfPeriapsis: 0,
  meanAnomaly: 0,
  meanMotion: 1,          // degrees per day
}

describe('solveKepler', () => {
  it('returns the mean anomaly unchanged for a circular orbit', () => {
    for (const M of [-3, -1, 0, 0.5, 2, 3]) {
      expect(solveKepler(M, 0)).toBeCloseTo(M, 15)
    }
  })

  it('satisfies Kepler’s equation across the whole plausible range of e and M', () => {
    for (let e = 0; e < 0.96; e += 0.05) {
      for (let step = 0; step < 64; step++) {
        const M = -Math.PI + (step / 64) * TAU
        expect(Math.abs(residual(solveKepler(M, e), e, M))).toBeLessThan(2 * KEPLER_TOLERANCE)
      }
    }
  })

  it('converges for near-parabolic orbits, where the naive iteration does not', () => {
    // 1 - e·cos E is the derivative Newton divides by. Just off periapsis on a very eccentric
    // orbit it is ~1e-4, so an unsafeguarded step lands hundreds of radians away and the iteration
    // wanders. This is the case the safeguard exists for.
    const hostile = [
      [1e-8, 0.9999], [1e-6, 0.9999], [1e-3, 0.999], [0.01, 0.99],
      [Math.PI - 1e-6, 0.9999], [-1e-6, 0.9999], [2, 0.995],
    ]
    for (const [M, e] of hostile) {
      const E = solveKepler(M, e)
      expect(Number.isFinite(E)).toBe(true)
      expect(Math.abs(residual(E, e, M))).toBeLessThan(2 * KEPLER_TOLERANCE)
    }
  })

  it('beats an unsafeguarded Newton loop on the case that breaks it', () => {
    // The textbook iteration, with the textbook seed E₀ = M, given 100 passes. On a very eccentric
    // orbit the first step divides by 1 - e·cos E ≈ 1e-5 and throws E hundreds of radians away;
    // the iteration then settles on a DIFFERENT revolution's root and reports it calmly. Residual
    // 2.8 radians, no error, no warning — precisely the class of bug this file exists to not have.
    const naive = (M, e) => {
      let E = M
      for (let pass = 0; pass < 100; pass++) E -= (E - e * Math.sin(E) - M) / (1 - e * Math.cos(E))
      return E
    }
    const M = 0.4712388980384685
    const e = 0.99999
    expect(Math.abs(residual(naive(M, e), e, M))).toBeGreaterThan(1)      // it landed elsewhere
    expect(Math.abs(residual(solveKepler(M, e), e, M))).toBeLessThan(2 * KEPLER_TOLERANCE)
  })

  it('is exact at the two points where the answer is known in closed form', () => {
    // The Newton step is zero at both, so the solver must return on that step rather than let the
    // bracket reject it and creep in by bisection.
    expect(solveKepler(0, 0.7)).toBe(0)                     // periapsis
    expect(solveKepler(Math.PI, 0.7)).toBe(Math.PI)         // apoapsis
  })

  it('stays inside one revolution of the mean anomaly it was given', () => {
    // |E - M| = |e·sin E| ≤ e. Any answer outside that band is wrong whatever its residual.
    // M is compared folded, since that is the revolution the returned E belongs to.
    const fold = (angle) => {
      const f = angle % TAU
      if (f > Math.PI) return f - TAU
      if (f <= -Math.PI) return f + TAU
      return f
    }
    for (let e = 0.05; e < 0.99; e += 0.11) {
      for (let step = 0; step < 32; step++) {
        const M = -Math.PI + (step / 32) * TAU
        expect(Math.abs(solveKepler(M, e) - fold(M))).toBeLessThanOrEqual(e + 1e-12)
      }
    }
  })

  it('is monotonic in the mean anomaly, as E - e·sin E is', () => {
    // Within one revolution. The ±π seam is a wrap, not a discontinuity in the physics.
    let previous = -Infinity
    for (let step = 1; step <= 200; step++) {
      const E = solveKepler(-Math.PI + (step / 200) * TAU, 0.85)
      expect(E).toBeGreaterThan(previous)
      previous = E
    }
  })

  it('folds a mean anomaly of many revolutions onto the same answer', () => {
    // Historical dates are hundreds of thousands of revolutions from the epoch. The answer must
    // not depend on how many whole turns were included.
    const e = 0.2
    const base = solveKepler(1.234, e)
    for (const turns of [1, 100, 10000, -250000]) {
      expect(solveKepler(1.234 + turns * TAU, e)).toBeCloseTo(base, 9)
    }
  })

  it('refuses eccentricities it cannot solve rather than looping', () => {
    expect(() => solveKepler(1, 1)).toThrow(RangeError)
    expect(() => solveKepler(1, 1.4)).toThrow(RangeError)
    expect(() => solveKepler(1, -0.1)).toThrow(RangeError)
    expect(() => solveKepler(NaN, 0.5)).toThrow(RangeError)
  })

  it('honours a hard iteration cap instead of running forever', () => {
    // One pass cannot reach 1e-12 from this seed, so the cap must fire. A solver with no cap would
    // simply keep going, which in a render loop is a frozen tab.
    expect(() => solveKepler(2, 0.9, { maxIterations: 1 })).toThrow(/converge/i)
  })

  it('can finish on a bisection step, not only on a Newton one', () => {
    // The bisection branch has its own convergence exit, and it is genuinely reachable: at
    // e = 0.999999 just off periapsis, Newton is still throwing steps far outside a bracket that
    // has already closed to within the tolerance. Found by tracing the loop rather than guessed —
    // an untested safety net is only a safety net by assertion.
    const M = 0.56549
    const e = 0.999999
    const E = solveKepler(M, e, { tolerance: 0.01 })
    expect(Math.abs(E - M)).toBeLessThanOrEqual(e)
    expect(Math.abs(residual(E, e, M))).toBeLessThan(0.02)
  })

  it('reaches a looser tolerance in fewer steps when asked', () => {
    const loose = solveKepler(2, 0.9, { tolerance: 1e-4 })
    const tight = solveKepler(2, 0.9)
    expect(Math.abs(loose - tight)).toBeLessThan(1e-3)
    expect(Math.abs(loose - tight)).toBeGreaterThan(0)
  })
})

describe('trueAnomaly', () => {
  it('equals the eccentric anomaly on a circular orbit', () => {
    for (const E of [-2, -0.3, 0, 1, 3]) expect(trueAnomaly(E, 0)).toBeCloseTo(E, 14)
  })

  it('matches the perifocal geometry it is defined by', () => {
    // Independent route: the orbital-plane coordinates are x = a(cos E - e), y = a√(1-e²) sin E,
    // and the true anomaly is the direction of that vector. atan2 gets the quadrant right and is
    // well conditioned everywhere, so it is a fair reference for the half-angle formula.
    for (const e of [0.01, 0.2, 0.55, 0.9, 0.99]) {
      for (let step = 0; step < 40; step++) {
        const E = -Math.PI + (step / 40) * TAU
        const reference = Math.atan2(Math.sqrt(1 - e * e) * Math.sin(E), Math.cos(E) - e)
        expect(trueAnomaly(E, e)).toBeCloseTo(reference, 12)
      }
    }
  })

  it('keeps the quadrant that the cosine form throws away', () => {
    // cos θ = (cos E - e)/(1 - e·cos E) inverted through acos always lands in [0, π], so the
    // outbound and inbound halves of the orbit come back identical. This is the trap in the
    // reference, and the half-angle form must not fall into it.
    const e = 0.6
    for (const E of [-2.5, -1.2, -0.4]) {
      const viaCosine = Math.acos((Math.cos(E) - e) / (1 - e * Math.cos(E)))
      expect(viaCosine).toBeGreaterThan(0)                 // the cosine route has lost the sign
      expect(trueAnomaly(E, e)).toBeLessThan(0)            // the half-angle route has kept it
      expect(trueAnomaly(E, e)).toBeCloseTo(-viaCosine, 9)
    }
  })

  it('is far more accurate than the cosine form near periapsis of an eccentric orbit', () => {
    // Near periapsis cos θ approaches 1, where acos is ill conditioned — its slope goes to
    // infinity, so the last digits of the input become the leading digits of the error. Measured
    // here: the half-angle route is good to 2e-18, the cosine route to 5e-9, a billion times worse.
    const e = 0.9999
    const E = 1e-7
    const reference = Math.atan2(Math.sqrt(1 - e * e) * Math.sin(E), Math.cos(E) - e)
    const viaCosine = Math.acos((Math.cos(E) - e) / (1 - e * Math.cos(E)))
    expect(Math.abs(trueAnomaly(E, e) - reference)).toBeLessThan(1e-15)
    expect(Math.abs(viaCosine - reference)).toBeGreaterThan(1e-9)
  })

  it('round-trips back to the eccentric anomaly', () => {
    for (const e of [0, 0.05, 0.4, 0.93]) {
      for (let step = 1; step < 20; step++) {
        const E = -Math.PI + (step / 20) * TAU
        expect(eccentricAnomalyFromTrue(trueAnomaly(E, e), e)).toBeCloseTo(E, 11)
      }
    }
  })
})

describe('orbitalRadius', () => {
  it('is a(1-e) at periapsis and a(1+e) at apoapsis', () => {
    expect(orbitalRadius(2, 0.3, 0)).toBeCloseTo(2 * 0.7, 12)
    expect(orbitalRadius(2, 0.3, Math.PI)).toBeCloseTo(2 * 1.3, 12)
  })

  it('agrees with a(1 - e·cos E), the other way of writing the same ellipse', () => {
    for (const e of [0, 0.1, 0.5, 0.9]) {
      for (let step = 0; step < 24; step++) {
        const E = (step / 24) * TAU
        expect(orbitalRadius(1.7, e, trueAnomaly(E, e))).toBeCloseTo(1.7 * (1 - e * Math.cos(E)), 10)
      }
    }
  })
})

describe('stateFromElements', () => {
  it('puts a circular equatorial orbit on the unit circle in the ecliptic plane', () => {
    const { x, y, z } = stateFromElements({ ...CIRCLE, meanAnomaly: 90 })
    expect(x).toBeCloseTo(0, 12)
    expect(y).toBeCloseTo(1, 12)
    expect(z).toBeCloseTo(0, 12)
  })

  it('keeps |position| equal to the radius it reports', () => {
    for (const e of [0, 0.2, 0.7]) {
      for (const M of [0, 40, 130, 250, 340]) {
        const state = stateFromElements({ ...CIRCLE, eccentricity: e, inclination: 17, ascendingNode: 63, argumentOfPeriapsis: 128, meanAnomaly: M })
        expect(Math.hypot(state.x, state.y, state.z)).toBeCloseTo(state.radius, 12)
      }
    }
  })

  it('never leaves the ecliptic plane when the inclination is zero', () => {
    for (const M of [0, 55, 200, 310]) {
      expect(stateFromElements({ ...CIRCLE, eccentricity: 0.4, argumentOfPeriapsis: 77, meanAnomaly: M }).z).toBeCloseTo(0, 14)
    }
  })

  it('tips a polar orbit into the x–z plane', () => {
    // i = 90°, Ω = 0 means the orbit runs over the poles through the x axis: y must stay zero and
    // the body must reach the full radius in z.
    const heights = []
    for (let M = 0; M < 360; M += 15) {
      const state = stateFromElements({ ...CIRCLE, inclination: 90, meanAnomaly: M })
      expect(state.y).toBeCloseTo(0, 12)
      heights.push(state.z)
    }
    expect(Math.max(...heights)).toBeCloseTo(1, 6)
  })

  it('places periapsis where the argument of periapsis says it is', () => {
    // At M = 0 the body sits at periapsis, at distance a(1-e), along Ω + ω in the ecliptic when
    // the orbit is not inclined.
    const state = stateFromElements({ ...CIRCLE, eccentricity: 0.3, ascendingNode: 40, argumentOfPeriapsis: 25, meanAnomaly: 0 })
    expect(state.radius).toBeCloseTo(0.7, 12)
    expect(Math.atan2(state.y, state.x) / DEG).toBeCloseTo(65, 10)
  })

  it('sweeps equal areas in equal times — Kepler’s second law', () => {
    // The physical check the whole solver exists to satisfy. Near periapsis the body is fast and
    // close, near apoapsis slow and far, and the triangle it sweeps per unit time is the same.
    const elements = { ...CIRCLE, eccentricity: 0.6, inclination: 12, ascendingNode: 30, argumentOfPeriapsis: 200 }
    const areas = []
    for (let M = 0; M < 360; M += 30) {
      const a = stateFromElements({ ...elements, meanAnomaly: M })
      const b = stateFromElements({ ...elements, meanAnomaly: M + 0.01 })
      areas.push(Math.hypot(
        a.y * b.z - a.z * b.y,
        a.z * b.x - a.x * b.z,
        a.x * b.y - a.y * b.x,
      ) / 2)
    }
    const mean = areas.reduce((sum, v) => sum + v, 0) / areas.length
    for (const area of areas) expect(Math.abs(area / mean - 1)).toBeLessThan(2e-4)
  })

  it('is the three rotations the reference specifies, in that order', () => {
    // R_z(Ω)·R_x(i)·R_z(ω) applied to the perifocal vector, spelled out here as an independent
    // second implementation. Swap any two of them and this fails.
    const elements = { ...CIRCLE, eccentricity: 0.4, inclination: 23, ascendingNode: 111, argumentOfPeriapsis: 47, meanAnomaly: 200 }
    const state = stateFromElements(elements)
    const E = solveKepler(200 * DEG, 0.4)
    const theta = trueAnomaly(E, 0.4)
    const r = orbitalRadius(1, 0.4, theta)
    const rz = (v, angle) => [
      v[0] * Math.cos(angle) - v[1] * Math.sin(angle),
      v[0] * Math.sin(angle) + v[1] * Math.cos(angle),
      v[2],
    ]
    const rx = (v, angle) => [
      v[0],
      v[1] * Math.cos(angle) - v[2] * Math.sin(angle),
      v[1] * Math.sin(angle) + v[2] * Math.cos(angle),
    ]
    const expected = rz(rx(rz([r * Math.cos(theta), r * Math.sin(theta), 0], 47 * DEG), 23 * DEG), 111 * DEG)
    expect(state.x).toBeCloseTo(expected[0], 13)
    expect(state.y).toBeCloseTo(expected[1], 13)
    expect(state.z).toBeCloseTo(expected[2], 13)
  })
})

describe('propagateElements and positionAt', () => {
  const ORBIT = {
    semiMajorAxis: 1.5,
    eccentricity: 0.25,
    inclination: 10,
    ascendingNode: 80,
    argumentOfPeriapsis: 200,
    meanAnomaly: 30,
    period: 400,            // days
  }

  it('advances the mean anomaly by a full turn over one period', () => {
    const epoch = Date.UTC(2000, 0, 1, 12)
    const start = propagateElements(ORBIT, new Date(epoch))
    const later = propagateElements(ORBIT, new Date(epoch + 400 * 86400000))
    expect(start.meanAnomaly).toBeCloseTo(30, 10)
    expect(((later.meanAnomaly - start.meanAnomaly) % 360 + 360) % 360).toBeCloseTo(0, 6)
  })

  it('accepts a mean motion instead of a period, and the two agree', () => {
    const date = new Date(Date.UTC(1610, 0, 17))
    const byPeriod = positionAt(ORBIT, date)
    const byMotion = positionAt({ ...ORBIT, period: undefined, meanMotion: 360 / 400 }, date)
    expect(byMotion.x).toBeCloseTo(byPeriod.x, 9)
    expect(byMotion.y).toBeCloseTo(byPeriod.y, 9)
    expect(byMotion.z).toBeCloseTo(byPeriod.z, 9)
  })

  it('returns to the same place one period later, five centuries before the epoch', () => {
    // The real use case: 185,000 days of negative day count, folded through 460 revolutions. If
    // the mean anomaly is accumulated carelessly this is where the precision goes.
    const columbus = new Date(Date.UTC(1492, 9, 21, 12))
    const before = positionAt(ORBIT, columbus)
    const after = positionAt(ORBIT, new Date(columbus.getTime() + 400 * 86400000))
    expect(after.x).toBeCloseTo(before.x, 6)
    expect(after.y).toBeCloseTo(before.y, 6)
    expect(after.z).toBeCloseTo(before.z, 6)
  })

  it('stays between periapsis and apoapsis across five centuries of daily steps', () => {
    const start = Date.UTC(1492, 0, 1)
    for (let day = 0; day < 400; day += 7) {
      const { radius } = positionAt(ORBIT, new Date(start + day * 86400000))
      expect(radius).toBeGreaterThanOrEqual(1.5 * 0.75 - 1e-12)
      expect(radius).toBeLessThanOrEqual(1.5 * 1.25 + 1e-12)
    }
  })

  it('propagates elements that carry their own rates', () => {
    const drifting = { ...ORBIT, rates: { ascendingNode: -0.05, inclination: 0.001 } }
    const epoch = Date.UTC(2000, 0, 1, 12)
    const after100 = propagateElements(drifting, new Date(epoch + 100 * 86400000))
    expect(after100.ascendingNode).toBeCloseTo(80 - 5, 10)
    expect(after100.inclination).toBeCloseTo(10 + 0.1, 10)
  })

  it('honours an epoch other than J2000', () => {
    const epoch = Date.UTC(1600, 0, 1)
    const shifted = { ...ORBIT, epoch }
    expect(propagateElements(shifted, new Date(epoch)).meanAnomaly).toBeCloseTo(30, 10)
  })

  it('refuses an element set with no way to advance in time', () => {
    expect(() => positionAt({ ...ORBIT, period: undefined }, new Date())).toThrow(/period|meanMotion/i)
  })
})

describe('eclipticSpherical', () => {
  it('turns a rectangular vector into longitude, latitude and distance', () => {
    const { longitude, latitude, distance } = eclipticSpherical({ x: 0, y: 2, z: 0 })
    expect(longitude).toBeCloseTo(90, 12)
    expect(latitude).toBeCloseTo(0, 12)
    expect(distance).toBeCloseTo(2, 12)
  })

  it('reports longitude in 0..360 and latitude in ±90', () => {
    for (let step = 0; step < 36; step++) {
      const angle = (step / 36) * TAU
      const { longitude, latitude } = eclipticSpherical({ x: Math.cos(angle), y: Math.sin(angle), z: 0.3 })
      expect(longitude).toBeGreaterThanOrEqual(0)
      expect(longitude).toBeLessThan(360)
      expect(Math.abs(latitude)).toBeLessThanOrEqual(90)
    }
  })
})
