/**
 * orbit.js — Keplerian orbital mechanics. Pure functions, no rendering, no MapLibre, no three.js.
 *
 * This is a HISTORY map, so the sky it draws should be the sky that was actually overhead. Six
 * numbers fix an orbit for all time — semi-major axis, eccentricity, inclination, longitude of the
 * ascending node, argument of periapsis, and where the body was at some epoch — and everything
 * else here is the machinery for turning those six plus a date into a position.
 *
 * THE ONE HARD PART is Kepler's equation, M = E - e·sin E, which has no closed-form solution and
 * has to be iterated. See `solveKepler` for the tolerance, the cap, and why an unsafeguarded Newton
 * loop is a frozen tab waiting to happen.
 *
 * Angles: every element and every returned angle is in DEGREES, because that is what element
 * tables are published in and what the rest of the timemap modules speak. Internally the trig runs
 * in radians, as it must.
 *
 * Frame: the returned x/y/z are the ecliptic frame the elements were referred to — for everything
 * in this app, the mean ecliptic and equinox of J2000. Turning that into a point on the turning
 * earth is `celestial-frames.js`, deliberately kept separate so this file has no notion of time
 * zones, sidereal rotation or the earth at all.
 *
 * Reference: Sangil Lee, "Compute the elliptical orbit" (sangillee.com/2025-01-05-elliptical-orbit-mechnics).
 * Two places where this departs from it are marked in the code: the iteration is safeguarded, and
 * the true anomaly uses the half-angle form (which Lee also recommends).
 */

const DEG = Math.PI / 180
const TAU = Math.PI * 2

/** J2000.0 — 2000-01-01 12:00 UTC. The epoch every element set in this app is referred to. */
export const J2000 = Date.UTC(2000, 0, 1, 12)

/**
 * Convergence tolerance for the eccentric anomaly, in radians.
 *
 * 1e-12 rad is 2e-7 arcseconds — around four thousand times coarser than double precision can
 * represent for an angle of order 1, so it is reliably reachable, and around ten million times
 * finer than the accuracy of any element set we feed it. Picking it well below the data's own
 * error means the solver never becomes the limiting term, and picking it well above eps means the
 * loop always terminates instead of chasing rounding noise.
 *
 * Because the derivative 1 - e·cos E lies in [1-e, 1+e] and so is at most 2, a step smaller than
 * this bounds the residual |E - e·sin E - M| by 2e-12 as well.
 */
export const KEPLER_TOLERANCE = 1e-12

/**
 * Hard iteration cap. Bisection alone halves a bracket of width at most 2 radians, so it reaches
 * 1e-12 in 41 passes from the worst possible start; Newton, when it is behaving, gets there in
 * five or six. 100 is therefore unreachable by any input that is not already broken — which is
 * exactly why exceeding it throws rather than returning a plausible-looking wrong answer.
 */
export const KEPLER_MAX_ITERATIONS = 100

/** Fold an angle in radians into (-π, π]. */
const wrapRadians = (angle) => {
  const folded = angle % TAU
  if (folded > Math.PI) return folded - TAU
  if (folded <= -Math.PI) return folded + TAU
  return folded
}

/** Fold an angle in degrees into [0, 360). */
export const wrapDegrees = (angle) => ((angle % 360) + 360) % 360

/** Fold an angle in degrees into [-180, 180). */
export const wrapDegreesSigned = (angle) => wrapDegrees(angle + 180) - 180

/**
 * Solve Kepler's equation M = E - e·sin E for the eccentric anomaly.
 *
 * Newton-Raphson, as the reference gives it:
 *
 *     E ← E - (E - e·sin E - M) / (1 - e·cos E)
 *
 * but SAFEGUARDED, which the reference leaves out and which matters. The denominator is
 * 1 - e·cos E, and near periapsis of a very eccentric orbit that is ~1-e, which for e = 0.9999 is
 * 1e-4. A bare Newton step there lands hundreds of radians away and the iteration wanders off; it
 * does not diverge to infinity, so it is not obviously broken, it simply returns a wrong angle
 * after however many passes you allowed. No planet or moon here is anywhere near that eccentric,
 * but comets are, and a solver that quietly fails on a comet is worse than one that cannot take
 * comets at all.
 *
 * The safeguard is a bracket. Since |E - M| = |e·sin E| ≤ e, the root always lies in [M-e, M+e],
 * and f(E) = E - e·sin E - M is strictly increasing there (f' = 1 - e·cos E ≥ 1-e > 0). So: take
 * the Newton step, and if it leaves the bracket, bisect instead. Quadratic convergence when Newton
 * is well behaved, guaranteed convergence when it is not.
 *
 * @param {number} meanAnomaly  M, radians. Any number of whole revolutions is fine.
 * @param {number} eccentricity e, in [0, 1). Parabolic and hyperbolic orbits are not handled.
 * @param {{tolerance?: number, maxIterations?: number}} [options]
 * @returns {number} E, radians, in (-π, π]
 */
export const solveKepler = (meanAnomaly, eccentricity, options = {}) => {
  const { tolerance = KEPLER_TOLERANCE, maxIterations = KEPLER_MAX_ITERATIONS } = options

  if (!Number.isFinite(meanAnomaly)) {
    throw new RangeError(`solveKepler: mean anomaly must be finite, got ${meanAnomaly}`)
  }
  if (!(eccentricity >= 0) || eccentricity >= 1) {
    throw new RangeError(`solveKepler: eccentricity must be in [0, 1), got ${eccentricity}`)
  }

  // Fold to one revolution first. Historical dates are hundreds of thousands of revolutions from
  // the epoch, and an unfolded M would spend all its significant digits on whole turns nobody can
  // see. It also puts the bracket below in its tightest form.
  const M = wrapRadians(meanAnomaly)
  if (eccentricity === 0) return M          // a circle: E is M, and the bracket below is degenerate

  let low = M - eccentricity
  let high = M + eccentricity

  // Seed. For mild eccentricity E ≈ M + e·sin M is already good to O(e²); for eccentric orbits
  // Danby's starter steps decisively away from periapsis, where the derivative is smallest.
  let E = eccentricity < 0.8
    ? M + eccentricity * Math.sin(M)
    : M + 0.85 * eccentricity * (Math.sign(M) || 1)
  if (E <= low || E >= high) E = (low + high) / 2

  for (let iteration = 0; iteration < maxIterations; iteration++) {
    const f = E - eccentricity * Math.sin(E) - M
    if (f > 0) high = E
    else low = E

    const derivative = 1 - eccentricity * Math.cos(E)
    const newtonStep = f / derivative

    // Converged. Tested BEFORE the bracket check below, and this order is not cosmetic: narrowing
    // the bracket has just made E one of its endpoints, so a Newton step that is correctly zero —
    // which is what happens at M = 0 and M = π, where the answer is exact — sits exactly ON the
    // endpoint and the strict inequality would throw it away. The solver would then creep to the
    // root by bisection alone and stop a tolerance short of an answer it already had.
    if (Math.abs(newtonStep) <= tolerance) return E - newtonStep

    // Out of the bracket means Newton overshot. Bisect: slower, but it cannot miss.
    let next = E - newtonStep
    if (!(next > low && next < high)) next = (low + high) / 2

    const step = Math.abs(next - E)
    E = next
    if (step <= tolerance) return E
  }

  throw new Error(
    `solveKepler: did not converge to ${tolerance} within ${maxIterations} iterations ` +
    `(M=${meanAnomaly}, e=${eccentricity})`,
  )
}

/**
 * True anomaly from eccentric anomaly, by the half-angle form:
 *
 *     tan(θ/2) = √[(1+e)/(1-e)] · tan(E/2)
 *
 * NOT the cosine form, cos θ = (cos E - e)/(1 - e·cos E). Two reasons, both real. The cosine form
 * inverted through acos always returns [0, π], so the outbound and inbound halves of the orbit
 * come back identical and the body appears to retrace its path — the quadrant is simply gone. And
 * near periapsis of an eccentric orbit cos θ approaches 1, where acos loses about half its
 * significant digits.
 *
 * Written as an atan2 of the two half-angle terms rather than an atan of their ratio, so the
 * quadrant survives and E = ±π does not divide by zero.
 *
 * @param {number} E  eccentric anomaly, radians
 * @param {number} e  eccentricity
 * @returns {number} true anomaly θ, radians, in (-π, π]
 */
export const trueAnomaly = (E, e) => 2 * Math.atan2(
  Math.sqrt(1 + e) * Math.sin(E / 2),
  Math.sqrt(1 - e) * Math.cos(E / 2),
)

/** The same relation run backwards, for tests and for anyone starting from an observed position. */
export const eccentricAnomalyFromTrue = (theta, e) => 2 * Math.atan2(
  Math.sqrt(1 - e) * Math.sin(theta / 2),
  Math.sqrt(1 + e) * Math.cos(theta / 2),
)

/**
 * Distance from the focus: r = a(1-e²)/(1 + e·cos θ), the polar equation of the ellipse.
 *
 * @param {number} a  semi-major axis, in whatever unit you want the answer in
 * @param {number} e  eccentricity
 * @param {number} theta true anomaly, radians
 */
export const orbitalRadius = (a, e, theta) => (a * (1 - e * e)) / (1 + e * Math.cos(theta))

const rotateZ = ([x, y, z], angle) => {
  const c = Math.cos(angle)
  const s = Math.sin(angle)
  return [x * c - y * s, x * s + y * c, z]
}

const rotateX = ([x, y, z], angle) => {
  const c = Math.cos(angle)
  const s = Math.sin(angle)
  return [x, y * c - z * s, y * s + z * c]
}

/**
 * @typedef {object} OrbitalElements
 * @property {number} semiMajorAxis        a. The unit you give it is the unit you get back.
 * @property {number} eccentricity         e, in [0, 1)
 * @property {number} inclination          i, degrees from the reference plane
 * @property {number} ascendingNode        Ω, degrees
 * @property {number} argumentOfPeriapsis  ω, degrees
 * @property {number} meanAnomaly          M₀, degrees, at `epoch`
 * @property {number} [period]             T, days. Give this or `meanMotion`.
 * @property {number} [meanMotion]         degrees per day. Give this or `period`.
 * @property {number} [epoch]              milliseconds UTC; defaults to J2000
 * @property {object} [rates]              per-day drift of any of the six, for element sets that
 *                                         carry secular terms (the moon's node and perigee do)
 */

/**
 * The six elements evaluated at a date, by linear propagation of whatever rates they carry.
 *
 * Linear is all a published element set supports; where the truth is not linear — the moon's
 * evection, the outer planets' great inequality — the body's own module adds the terms on top.
 * See `moon-kepler.js` and `planets.js`.
 *
 * @param {OrbitalElements} elements
 * @param {Date} date
 * @returns {OrbitalElements} the same shape, with every angle evaluated at `date`
 */
export const propagateElements = (elements, date) => {
  const { period, meanMotion, epoch = J2000, rates = {} } = elements

  if (meanMotion === undefined && !period) {
    throw new Error('propagateElements: elements need either a period (days) or a meanMotion (deg/day)')
  }
  const motion = meanMotion !== undefined ? meanMotion : 360 / period
  const days = (date.getTime() - epoch) / 86400000

  return {
    ...elements,
    semiMajorAxis: elements.semiMajorAxis + (rates.semiMajorAxis || 0) * days,
    eccentricity: elements.eccentricity + (rates.eccentricity || 0) * days,
    inclination: elements.inclination + (rates.inclination || 0) * days,
    ascendingNode: elements.ascendingNode + (rates.ascendingNode || 0) * days,
    argumentOfPeriapsis: elements.argumentOfPeriapsis + (rates.argumentOfPeriapsis || 0) * days,
    meanAnomaly: elements.meanAnomaly + motion * days,
    epoch: date.getTime(),
    rates: {},
  }
}

/**
 * Position from a set of elements already evaluated at the moment of interest.
 *
 * Perifocal coordinates [r·cos θ, r·sin θ, 0], then the three rotations the reference specifies,
 * in that order:
 *
 *     p = R_z(Ω) · R_x(i) · R_z(ω) · [r·cos θ, r·sin θ, 0]ᵀ
 *
 * R_z(ω) swings periapsis round within the orbital plane, R_x(i) tips the plane, R_z(Ω) turns the
 * tipped plane about the reference pole. Any other order gives a different orbit.
 *
 * @param {OrbitalElements} elements  with `meanAnomaly` already at the wanted date
 * @returns {{x: number, y: number, z: number, radius: number, trueAnomaly: number,
 *            eccentricAnomaly: number, meanAnomaly: number}} ecliptic rectangular coordinates in
 *          the unit of `semiMajorAxis`; the three anomalies in degrees
 */
export const stateFromElements = (elements, options = {}) => {
  const {
    semiMajorAxis, eccentricity, inclination, ascendingNode, argumentOfPeriapsis, meanAnomaly,
  } = elements

  const E = solveKepler(meanAnomaly * DEG, eccentricity, options)
  const theta = trueAnomaly(E, eccentricity)
  const radius = orbitalRadius(semiMajorAxis, eccentricity, theta)

  const perifocal = [radius * Math.cos(theta), radius * Math.sin(theta), 0]
  const [x, y, z] = rotateZ(
    rotateX(rotateZ(perifocal, argumentOfPeriapsis * DEG), inclination * DEG),
    ascendingNode * DEG,
  )

  return {
    x,
    y,
    z,
    radius,
    trueAnomaly: theta / DEG,
    eccentricAnomaly: E / DEG,
    meanAnomaly: wrapDegreesSigned(meanAnomaly),
  }
}

/**
 * Where a body with these elements is at this date, in the ecliptic frame the elements are
 * referred to.
 *
 * @param {OrbitalElements} elements
 * @param {Date} [date]
 * @returns {{x: number, y: number, z: number, radius: number, trueAnomaly: number,
 *            eccentricAnomaly: number, meanAnomaly: number}}
 */
export const positionAt = (elements, date = new Date(), options = {}) =>
  stateFromElements(propagateElements(elements, date), options)

/**
 * Rectangular ecliptic coordinates as longitude, latitude and distance — the form ephemerides are
 * published in, and the form to compare two implementations in.
 *
 * @returns {{longitude: number, latitude: number, distance: number}} degrees, degrees, input unit
 */
export const eclipticSpherical = ({ x, y, z }) => ({
  longitude: wrapDegrees(Math.atan2(y, x) / DEG),
  latitude: Math.atan2(z, Math.hypot(x, y)) / DEG,
  distance: Math.hypot(x, y, z),
})

/** Angle between two vectors, in degrees. The unit of "how wrong is this" for a sky position. */
export const angularSeparation = (a, b) => {
  const dot = a.x * b.x + a.y * b.y + a.z * b.z
  const cross = Math.hypot(
    a.y * b.z - a.z * b.y,
    a.z * b.x - a.x * b.z,
    a.x * b.y - a.y * b.x,
  )
  return Math.atan2(cross, dot) / DEG
}
