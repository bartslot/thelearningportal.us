/**
 * vsop87.js — the planets by the real theory, not a two-body approximation.
 *
 * `planets.js` solves a Keplerian ellipse from a fitted element set. That is honest work and it is
 * good to about a third of a degree, but the underlying picture is wrong: the planets do not move
 * on ellipses. They pull on each other, and over five centuries the difference is visible.
 *
 * VSOP87 is the analytical solution of that actual problem — the position of each planet as a sum
 * of periodic terms derived from the perturbation theory, fitted to JPL's DE200 numerical
 * integration. Version A gives heliocentric RECTANGULAR coordinates in the dynamical ecliptic and
 * equinox of J2000, which is the same frame `planets.js` produces, so the two are directly
 * comparable and interchangeable.
 *
 *     coordinate = Σ_α T^α · Σ_n A_n · cos(B_n + C_n · T)
 *
 * with T in Julian millennia of TERRESTRIAL TIME from J2000.
 *
 * TIME IS NOW LOAD-BEARING. At a third of a degree it did not matter whether the input Date was
 * read as TT or as UT. At arcsecond level it matters a great deal: ΔT at 1492 is about 200
 * seconds, and Mercury moves 0.17 arcseconds per second of time, so treating UTC as TT throws it
 * 34 arcseconds — thirty times the accuracy of the theory. So this module converts, using
 * `delta-t.js`. Everything here takes a UTC Date, as the rest of the app does, and applies ΔT
 * internally. `planets.js` deliberately does not, and says so.
 *
 * ACCURACY, MEASURED. Against JPL Horizons (DE441), heliocentric and geometric, worst case over 22
 * dates from 1400 to 2050, in arcseconds:
 *
 *     Mercury 0.13    Venus 0.27    Earth 0.36    Mars 0.65
 *     Jupiter 0.40    Saturn 1.25   Uranus 6.47   Neptune 8.40
 *
 * At Columbus's landfall specifically, every inner planet is inside half an arcsecond and the
 * worst of all eight is Neptune at 6.9. For comparison, the Keplerian element set in `planets.js`
 * is 36 to 1281 times worse on the same dates.
 *
 * TRUNCATION IS NOT THE LIMIT. The table here drops terms below 1e-9 au, which costs 0.011
 * arcseconds measured against the theory's own published check values — a hundred times finer than
 * the theory's disagreement with DE441. Carrying more terms would buy nothing measurable.
 *
 * The outer planets are the weak point, and that is the theory rather than this file: VSOP87 was
 * fitted to DE200, and DE200's Uranus and Neptune were themselves the least well determined.
 *
 * WHAT THIS IS NOT. It is not a numerical integration and it is not DE441. If a lesson ever needs
 * to be right about an occultation to the second, this is not the tool; but it is three orders of
 * magnitude better than an ellipse, which is the question that was actually on the table.
 */

import { deltaTSeconds } from './delta-t.js'
import { eclipticToSubPoint } from './celestial-frames.js'
import { eclipticSpherical } from './orbit.js'
import { VSOP87A } from './vsop87a-data.js'

const DEG = Math.PI / 180

/** Julian Date of the Unix epoch — JS time is a linear map onto JD, with no leap seconds. */
const JD_UNIX_EPOCH = 2440587.5
const JD_J2000 = 2451545
const DAYS_PER_MILLENNIUM = 365250

/** Astronomical unit in metres, IAU 2012. */
export const AU_METRES = 149597870700

/** The bodies this table carries, in order out from the sun. */
export const VSOP87_BODIES = Object.keys(VSOP87A)

/**
 * Julian Date in Terrestrial Time from a UTC Date.
 *
 * The whole reason `delta-t.js` exists. TT runs ahead of UT by ΔT, which is 200 seconds at the
 * time of Columbus and 69 at the time of writing, and the series below are functions of TT.
 */
export const julianDateTT = (date) => (
  (date.getTime() + deltaTSeconds(date) * 1000) / 86400000 + JD_UNIX_EPOCH
)

/** Julian millennia of TT since J2000 — the argument every VSOP87 series is written in. */
export const millenniaTT = (date) => (julianDateTT(date) - JD_J2000) / DAYS_PER_MILLENNIUM

/** Evaluate one coordinate's nested series: Σ_α T^α Σ_n A cos(B + C T). */
const evaluateSeries = (powers, T) => {
  let total = 0
  let power = 1
  for (const terms of powers) {
    let sum = 0
    for (let n = 0; n < terms.length; n++) {
      const term = terms[n]
      sum += term[0] * Math.cos(term[1] + term[2] * T)
    }
    total += sum * power
    power *= T
  }
  return total
}

/**
 * Heliocentric position from the time argument the theory is actually written in: Julian millennia
 * of TT since J2000.
 *
 * Separated from the Date-taking form on purpose. It lets the tests compare against the published
 * check values and against Horizons' TDB vector tables with NO time-scale conversion in the way,
 * so a failure there is unambiguously the series and not ΔT. Callers with a date want
 * `heliocentric`.
 *
 * @param {string} name one of VSOP87_BODIES
 * @param {number} T Julian millennia of TT from J2000
 * @returns {{x: number, y: number, z: number, radius: number}} au
 */
export const heliocentricAtMillennia = (name, T) => {
  const series = VSOP87A[name]
  if (!series) throw new RangeError(`vsop87: no series for "${name}" (have ${VSOP87_BODIES.join(', ')})`)

  const x = evaluateSeries(series[0], T)
  const y = evaluateSeries(series[1], T)
  const z = evaluateSeries(series[2], T)
  return { x, y, z, radius: Math.hypot(x, y, z) }
}

/**
 * Heliocentric position in au, referred to the dynamical ecliptic and equinox of J2000.
 *
 * Note this is the EARTH, not the Earth/Moon barycentre — VSOP87A publishes both, and the earth
 * itself is the one an observer stands on. `planets.js` has only the barycentre, which is one of
 * the smaller reasons this is more accurate.
 *
 * @param {string} name one of VSOP87_BODIES
 * @param {Date} [date] UTC; ΔT is applied internally
 * @returns {{x: number, y: number, z: number, radius: number}} au
 */
export const heliocentric = (name, date = new Date()) =>
  heliocentricAtMillennia(name, millenniaTT(date))

/**
 * Geocentric position: the difference of two heliocentric vectors, which is why the earth's own
 * series has to be as good as the target's.
 *
 * GEOMETRIC, not apparent — no light-time, no aberration. Those are `apparentGeocentric`.
 *
 * @returns {{x, y, z, longitude, latitude, distance}} au and degrees, J2000 ecliptic
 */
export const geocentric = (name, date = new Date()) => {
  if (name === 'earth') throw new RangeError('vsop87: the earth is not visible from itself')
  const planet = heliocentric(name, date)
  const earth = heliocentric('earth', date)
  const vector = { x: planet.x - earth.x, y: planet.y - earth.y, z: planet.z - earth.z }
  return { ...vector, ...eclipticSpherical(vector) }
}

/** Speed of light, au per day. Light takes 8.3 minutes to reach us from the sun. */
const LIGHT_AU_PER_DAY = 173.144632674

/**
 * Apparent geocentric position: where the planet APPEARS, which is not where it is.
 *
 * Two corrections, both larger than the theory's own error and so both worth making once the
 * theory is this good:
 *
 *  - LIGHT-TIME. Jupiter is 35 to 50 minutes away. You see where it was, and over 40 minutes it
 *    moves. Solved by iterating: guess the distance, step the planet back by the light-time, and
 *    repeat. It converges in two passes because the distance barely changes.
 *  - ABERRATION. The earth is moving at 30 km/s across the line of sight, which tilts every
 *    incoming ray by up to 20.5 arcseconds — the same reason rain slants on a moving train. Taken
 *    here as the classical first-order term in the earth's velocity, good to a milliarcsecond.
 *
 * Skipping either would put a 20-arcsecond floor under an ephemeris good to one.
 *
 * @returns {{x, y, z, longitude, latitude, distance, lightTimeDays}} au and degrees, J2000 ecliptic
 */
/**
 * Position corrected for LIGHT-TIME only, referred to J2000 — what astronomers call the
 * astrometric place.
 *
 * Jupiter is 35 to 50 minutes away and Mercury 4 to 12. You do not see where a planet is, you see
 * where it was, and over that interval it moves — up to half an arcminute for Mercury. Solved by
 * iterating: measure the distance, step the planet back by the light travel time, measure again.
 * It converges in two passes because the distance barely changes over its own light-time.
 *
 * @returns {{x, y, z, longitude, latitude, distance, lightTimeDays}} au and degrees, J2000 ecliptic
 */
export const astrometricGeocentric = (name, date = new Date()) => {
  if (name === 'earth') throw new RangeError('vsop87: the earth is not visible from itself')
  const earth = heliocentric('earth', date)

  let lightTimeDays = 0
  let planet = heliocentric(name, date)
  for (let pass = 0; pass < 4; pass++) {
    const distance = Math.hypot(planet.x - earth.x, planet.y - earth.y, planet.z - earth.z)
    const next = distance / LIGHT_AU_PER_DAY
    if (Math.abs(next - lightTimeDays) < 1e-11) break
    lightTimeDays = next
    planet = heliocentricAtMillennia(name, millenniaTT(date) - lightTimeDays / DAYS_PER_MILLENNIUM)
  }

  const vector = { x: planet.x - earth.x, y: planet.y - earth.y, z: planet.z - earth.z }
  return { ...vector, ...eclipticSpherical(vector), lightTimeDays }
}

/** The earth's velocity in au/day, by central difference over two minutes. */
export const earthVelocity = (date) => {
  const step = 120000
  const before = heliocentric('earth', new Date(date.getTime() - step))
  const after = heliocentric('earth', new Date(date.getTime() + step))
  const perDay = 86400000 / (2 * step)
  return {
    x: (after.x - before.x) * perDay,
    y: (after.y - before.y) * perDay,
    z: (after.z - before.z) * perDay,
  }
}

/**
 * Apparent position: astrometric, plus ABERRATION.
 *
 * The earth is moving at 30 km/s across the line of sight, which tilts every incoming ray by up to
 * 20.5 arcseconds — the same reason rain slants on a moving train. Skipping it would put a
 * 20-arcsecond floor under an ephemeris good to one, so at this accuracy it stops being optional.
 * Taken as the classical first-order term in the earth's velocity, good to a milliarcsecond.
 *
 * Still referred to J2000; precession and nutation to the equinox of date happen in
 * `celestial-frames.js`, where every body in the sky goes through the same code.
 *
 * @returns {{x, y, z, longitude, latitude, distance, lightTimeDays}} au and degrees, J2000 ecliptic
 */
export const apparentGeocentric = (name, date = new Date()) => {
  const astrometric = astrometricGeocentric(name, date)
  const velocity = earthVelocity(date)
  const vector = {
    x: astrometric.x + velocity.x * astrometric.lightTimeDays,
    y: astrometric.y + velocity.y * astrometric.lightTimeDays,
    z: astrometric.z + velocity.z * astrometric.lightTimeDays,
  }
  return { ...vector, ...eclipticSpherical(vector), lightTimeDays: astrometric.lightTimeDays }
}

/**
 * The sun as seen from the earth — the reverse of the earth's own heliocentric vector, which is why
 * no separate solar theory is needed.
 *
 * ABERRATION ONLY, AND THAT IS THE SUBTLE PART. For a planet, light-time and aberration are two
 * separate corrections: the planet has moved since the light left it, AND the earth's motion tilts
 * the incoming ray. For the sun there is no first effect to apply — in heliocentric coordinates the
 * sun sits at the origin and does not go anywhere while its light is in transit. Retarding the
 * EARTH instead, which is the tempting shortcut, applies the earth's velocity a second time and
 * doubles the aberration to 41 arcseconds. It looks plausible, it is the right order of magnitude,
 * and it is wrong; the test for it asserts the 20.5 arcseconds that v/c actually gives.
 */
export const apparentSun = (date = new Date()) => {
  const earth = heliocentric('earth', date)
  const lightTimeDays = Math.hypot(earth.x, earth.y, earth.z) / LIGHT_AU_PER_DAY
  const velocity = earthVelocity(date)
  const vector = {
    x: -earth.x + velocity.x * lightTimeDays,
    y: -earth.y + velocity.y * lightTimeDays,
    z: -earth.z + velocity.z * lightTimeDays,
  }
  return { ...vector, ...eclipticSpherical(vector), lightTimeDays }
}

/**
 * Where on earth the planet is directly overhead.
 *
 * `fromJ2000` is true: VSOP87A is referred to the J2000 equinox, and sidereal time is measured
 * from the equinox of date. Seven degrees at 1492 rides on that flag.
 */
export const subPoint = (name, date = new Date()) => {
  const { longitude, latitude, distance } = name === 'sun'
    ? apparentSun(date)
    : apparentGeocentric(name, date)
  return eclipticToSubPoint({ longitude, latitude, distance: distance * AU_METRES }, date, { fromJ2000: true })
}

/** Angle from the sun, in degrees. Under about 15° a planet is lost in the glare. */
export const solarElongation = (name, date = new Date()) => {
  const planet = apparentGeocentric(name, date)
  const sun = apparentSun(date)
  const dot = planet.x * sun.x + planet.y * sun.y + planet.z * sun.z
  const lengths = Math.hypot(planet.x, planet.y, planet.z) * Math.hypot(sun.x, sun.y, sun.z)
  return Math.acos(Math.max(-1, Math.min(1, dot / lengths))) / DEG
}
