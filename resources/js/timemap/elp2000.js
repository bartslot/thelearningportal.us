/**
 * elp2000.js — the moon by ELP 2000-82B.
 *
 * `moon-kepler.js` is an ellipse with three perturbation terms bolted on, and it is good to about
 * five arcminutes. That is fine for drawing a moon in a sky. It is not fine for saying what the
 * moon was doing on a particular night in 1492, which is what a history map is for.
 *
 * ELP is the moon's equivalent of VSOP87: a semi-analytical solution of the real problem, fitted
 * to JPL's DE200/LE200, in which the ellipse is only the first of several thousand terms. What the
 * extra terms are is worth knowing, because they are the reasons the moon is hard:
 *
 *   main problem       the sun's pull on the earth-moon pair, solved properly rather than as
 *                      three named corrections. This is most of the improvement.
 *   earth figure       the earth is not a point mass; its equatorial bulge torques the orbit
 *   planetary          Venus and Jupiter tug on the moon directly, and on the earth differently
 *   moon figure        the moon is not a point mass either, and it keeps one face toward us
 *   tides              the tidal bulge the moon raises pulls back on the moon
 *   relativity         a real, measurable term, and small enough that it took lunar laser
 *                      ranging to confirm it
 *
 * FRAME: the same one VSOP87A uses — the mean dynamical ecliptic and inertial equinox of J2000 —
 * so the moon, the sun and the planets all come out in one frame and can be compared without a
 * conversion. Anything heading for the map is precessed to the equinox of date by
 * `celestial-frames.js`, exactly as the planets are.
 *
 * TIME: Julian centuries of Terrestrial Time from J2000. Every function here takes a UTC Date and
 * applies ΔT internally, because at this accuracy the difference is enormous — the moon moves 0.55
 * arcseconds per second of time, so the 200-second ΔT at 1492 is 110 arcseconds, forty times the
 * accuracy of the theory. Getting the ephemeris right and the time scale wrong would have thrown
 * away the entire exercise.
 *
 * ACCURACY, MEASURED against JPL Horizons (DE441), geocentric and geometric, over 22 dates from
 * 1400 to 2050:
 *
 *     1492 Columbus   21.3″ = 0.35′        1596 Barentsz   12.9″ = 0.21′
 *     1519 Magellan   18.4″ = 0.31′        1642 Tasman     12.1″ = 0.20′
 *     J2000            0.13″               worst (1415)    33.8″ = 0.56′
 *
 * Distance is right to under 4 km out of 384,400.
 *
 * THE ERROR IS SECULAR, AND THAT IS INFORMATIVE. It is 0.13″ at J2000 and grows smoothly backwards
 * — era means of 0.55″ after 1830, 8.3″ from 1596 to 1805, 25.4″ before 1550. That shape is a t²
 * divergence, which is what a small difference in adopted TIDAL ACCELERATION between LE200 (which
 * ELP was fitted to) and DE441 (which Horizons runs) must produce. It is not noise, and it is not
 * something a truncation change would fix.
 *
 * WHICH MEANS THE MOON'S REAL HISTORICAL LIMIT IS ΔT, NOT THIS. See `delta-t.js`: before about
 * 1600, published ΔT models disagree by tens of seconds, and the moon moves 0.55 arcseconds per
 * second. A better lunar theory does not help with that.
 *
 * The term assembly and every constant here is a port of the archive's own elp82b.f, not a
 * recollection.
 */

import { deltaTSeconds } from './delta-t.js'
import { eclipticToSubPoint } from './celestial-frames.js'
import { eclipticSpherical } from './orbit.js'
import { ELP_DISTANCE, ELP_DISTANCE_SCALE, ELP_LATITUDE, ELP_LONGITUDE, ELP_W1 } from './elp2000-data.js'

const ARCSEC_TO_RAD = Math.PI / 648000
const DEG = Math.PI / 180
const JD_UNIX_EPOCH = 2440587.5
const JD_J2000 = 2451545
const DAYS_PER_CENTURY = 36525

/** Metres per kilometre, so the module's public distances match the rest of the app. */
const KM = 1000

/**
 * The rotation from ELP's inertial frame to the J2000 ecliptic, as elp82b.f applies it at the end
 * of the computation. Two small quantities p and q, each a quintic in time, drive an orthogonal
 * matrix; they are the same laser-fitted frame tie the theory was published with.
 */
const P_POLY = [0.10180391e-4, 0.47020439e-6, -0.5417367e-9, -0.2507948e-11, 0.463486e-14]
const Q_POLY = [-0.113469002e-3, 0.12372674e-6, 0.1265417e-8, -0.1371808e-11, -0.320334e-14]

/** Julian centuries of TT since J2000, from a UTC Date. */
export const centuriesTT = (date) => (
  ((date.getTime() + deltaTSeconds(date) * 1000) / 86400000 + JD_UNIX_EPOCH - JD_J2000) / DAYS_PER_CENTURY
)

/** Sum one ELP series: Σ amplitude · t^power · sin(c0 + c1 t + c2 t² + c3 t³ + c4 t⁴). */
const sumSeries = (terms, t) => {
  const powers = [1, t, t * t, t * t * t, t * t * t * t]
  let total = 0
  for (let n = 0; n < terms.length; n++) {
    const term = terms[n]
    const argument = term[2] + term[3] * t + term[4] * powers[2] + term[5] * powers[3] + term[6] * powers[4]
    total += term[0] * powers[term[1]] * Math.sin(argument)
  }
  return total
}

/**
 * Geocentric position of the moon, referred to the mean dynamical ecliptic and inertial equinox of
 * J2000.
 *
 * @param {Date} [date] UTC; ΔT is applied internally
 * @returns {{x: number, y: number, z: number, longitude: number, latitude: number,
 *            distance: number}} metres, and degrees
 */
export const moonEclipticJ2000 = (date = new Date()) => moonEclipticAtCenturies(centuriesTT(date))

/**
 * The same, from the time argument the theory is written in: Julian centuries of TT since J2000.
 *
 * Separated from the Date-taking form so the tests can compare against Horizons' TDB vector tables
 * with no time-scale conversion in the way, and a failure is unambiguously the series rather than
 * ΔT. Callers with a date want `moonEclipticJ2000`.
 *
 * @param {number} t Julian centuries of TT from J2000
 */
export const moonEclipticAtCenturies = (t) => {
  const powers = [1, t, t * t, t * t * t, t * t * t * t]

  // The series give longitude and latitude in arcseconds and distance in kilometres. Longitude is
  // measured from the moon's own mean longitude W1, which is why it has to be added back.
  const meanLongitude = ELP_W1.reduce((sum, coefficient, power) => sum + coefficient * powers[power], 0)
  const longitude = sumSeries(ELP_LONGITUDE, t) * ARCSEC_TO_RAD + meanLongitude
  const latitude = sumSeries(ELP_LATITUDE, t) * ARCSEC_TO_RAD
  const distanceKm = sumSeries(ELP_DISTANCE, t) * ELP_DISTANCE_SCALE

  // Spherical to rectangular, still in ELP's own inertial frame.
  const horizontal = distanceKm * Math.cos(latitude)
  const x1 = horizontal * Math.cos(longitude)
  const x2 = horizontal * Math.sin(longitude)
  const x3 = distanceKm * Math.sin(latitude)

  // ...then the frame tie to J2000.
  const p = P_POLY.reduce((sum, c, power) => sum + c * powers[power], 0) * t
  const q = Q_POLY.reduce((sum, c, power) => sum + c * powers[power], 0) * t
  const scale = 2 * Math.sqrt(1 - p * p - q * q)
  const pq = 2 * p * q
  const p2 = 1 - 2 * p * p
  const q2 = 1 - 2 * q * q
  const pScaled = p * scale
  const qScaled = q * scale

  const x = (p2 * x1 + pq * x2 + pScaled * x3) * KM
  const y = (pq * x1 + q2 * x2 - qScaled * x3) * KM
  const z = (-pScaled * x1 + qScaled * x2 + (p2 + q2 - 1) * x3) * KM

  return { x, y, z, ...eclipticSpherical({ x, y, z }) }
}

/** Earth's mean radius, for expressing the moon's distance the way the render layers want it. */
const EARTH_RADIUS_M = 6371008.8

/**
 * The sub-lunar point: where on earth the moon is directly overhead, and how far away it is.
 *
 * Same shape as `moonPosition` in `moon.js` and `moon-kepler.js`, so all three can be compared
 * value for value. `fromJ2000` is TRUE here, unlike the classical modules, because ELP is referred
 * to the J2000 equinox rather than the equinox of date.
 *
 * @returns {{lng: number, lat: number, distance: number}} degrees and metres
 */
export const moonPosition = (date = new Date()) => {
  const { longitude, latitude, distance } = moonEclipticJ2000(date)
  return eclipticToSubPoint({ longitude, latitude, distance }, date, { fromJ2000: true })
}

/** The moon as a planet-space vector in earth radii, +Y north, +X at 0°N 0°E. */
export const moonVector = (date = new Date()) => {
  const { lng, lat, distance } = moonPosition(date)
  const radii = distance / EARTH_RADIUS_M
  return [
    radii * Math.cos(lat * DEG) * Math.cos(lng * DEG),
    radii * Math.sin(lat * DEG),
    radii * Math.cos(lat * DEG) * Math.sin(lng * DEG),
  ]
}

/**
 * Illuminated fraction of the disc: 0 at new moon, 1 at full.
 *
 * FULL is when the earth and the sun lie in the same direction as seen FROM the moon, so the face
 * turned toward us is the lit one. The opposite convention gives a moon that reports full at new,
 * and it survives every visual check because a crescent still looks like a crescent. The test for
 * it asserts the geometry rather than the number.
 */
export const moonPhase = (date, sunDirection) => {
  const moon = moonVector(date)
  const length = Math.hypot(...moon)
  const toEarth = moon.map((v) => -v / length)
  const cosPhaseAngle = toEarth[0] * sunDirection[0]
    + toEarth[1] * sunDirection[1]
    + toEarth[2] * sunDirection[2]
  return (1 + cosPhaseAngle) / 2
}
