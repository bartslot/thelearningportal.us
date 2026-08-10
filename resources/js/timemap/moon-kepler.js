/**
 * moon-kepler.js — the moon, through the general Kepler machinery.
 *
 * `moon.js` already computes the moon, with its own three-pass iteration written out inline in
 * degrees. This is the same ephemeris rebuilt on `orbit.js`: identical elements, identical
 * perturbation terms, but the eccentric anomaly comes from the safeguarded solver every other body
 * will use. The point is not that the moon moves — at e = 0.055 the old iteration was already
 * converged — it is that the moon becomes a test case for machinery the planets depend on. If the
 * general solver can reproduce a body whose position is checked against four independent
 * astronomical facts, it can be trusted on a body that is checked against nothing but an ephemeris.
 *
 * ELEMENTS: the classical low-precision set (Schlyter), referred to the ecliptic and mean equinox
 * OF DATE — the node and perigee terms carry precession inside them. So nothing here is precessed;
 * `fromJ2000` stays false all the way through. The JPL planetary elements in `planets.js` are the
 * opposite case, and that difference is the single most dangerous thing in this directory.
 *
 * THE EPOCH IS NOT J2000, and this is the trap. Schlyter counts days from "2000 January 0.0", an
 * astronomer's way of writing 1999-12-31 00:00 UT — a day and a half BEFORE J2000, which is
 * 2000-01-01 12:00 UT. The moon covers 19.6° in a day and a half. Reading these constants as
 * J2000 constants therefore puts it around twenty degrees wrong, forty moon-widths, and `moon.js`
 * did exactly that. Nothing caught it, because every check in `moon.test.js` — the distance range,
 * the latitude bound, the westward drift rate, the phase geometry — is invariant under a shift in
 * time. A wrong moon that is wrong CONSISTENTLY passes all of them. Only an absolute position,
 * checked against an ephemeris, can see it, and there is one in this module's test file.
 *
 * Note that the other two series here are on their own epochs and must stay there: the obliquity
 * and GMST formulas in `celestial-frames.js` are genuinely referred to J2000.
 *
 * PERTURBATIONS: the moon is not a two-body problem and never was. The sun pulls its orbit about
 * hard enough that pure Kepler is over a degree wrong — two moon-widths — so the three largest
 * terms are added after the ellipse is solved:
 *
 *   evection (1.27°)         the sun stretching the orbit's eccentricity back and forth
 *   variation (0.66°)        the moon speeding up and slowing down through each quarter
 *   annual equation (0.19°)  the earth's own elliptical year, felt second-hand
 *
 * These are corrections to the ANSWER, not to the elements, which is why they sit outside
 * `orbit.js`: a general solver has no business knowing that one particular satellite has a sun
 * leaning on it.
 *
 * ACCURACY, measured against JPL Horizons at six epochs from 1492 to 2026 (see the test file, which
 * asserts these rather than repeating them): worst 0.090° in ecliptic longitude, 0.080° in
 * latitude, 0.092° in the sub-lunar point, and 1101 km in distance. Under a fifth of a moon-width
 * in every direction, and — the surprise — no worse at 1492 than today. The linear elements hold up
 * across five centuries better than they have any right to.
 */

import { eclipticToSubPoint } from './celestial-frames.js'
import { eclipticSpherical, positionAt, wrapDegrees } from './orbit.js'

const DEG = Math.PI / 180
const EARTH_RADIUS_M = 6371008.8

/**
 * "2000 January 0.0" — the epoch the classical lunar and solar element series below are counted
 * from. It is 1999-12-31 00:00 UT, one and a half days before J2000. See the note at the top of
 * the file for what happens if this is confused with J2000.
 */
export const SCHLYTER_EPOCH = Date.UTC(1999, 11, 31, 0)

const sind = (deg) => Math.sin(deg * DEG)
const cosd = (deg) => Math.cos(deg * DEG)

/**
 * The moon's orbit as six numbers plus their drift, in the shape `orbit.js` takes.
 *
 * The semi-major axis is in EARTH RADII, so every distance out of this module is too until it is
 * multiplied up at the end. That is the unit the classical elements are published in, and
 * converting late rather than early keeps them recognisable against the source.
 */
export const MOON_ELEMENTS = {
  semiMajorAxis: 60.2666,
  eccentricity: 0.054900,
  inclination: 5.1454,
  ascendingNode: 125.1228,
  argumentOfPeriapsis: 318.0634,
  meanAnomaly: 115.3654,
  meanMotion: 13.0649929509,
  epoch: SCHLYTER_EPOCH,
  rates: {
    // The node regresses once round in 18.6 years (which is what makes eclipse seasons drift) and
    // the perigee advances once round in 8.85. Both are far too fast to treat as constant.
    ascendingNode: -0.0529538083,
    argumentOfPeriapsis: 0.1643573223,
  },
}

/** The sun's mean anomaly and mean longitude, needed only to know where the sun is pulling from. */
const solarAngles = (days) => {
  const meanAnomaly = 356.0470 + 0.9856002585 * days
  return { meanAnomaly, meanLongitude: 282.9404 + 0.0000470935 * days + meanAnomaly }
}

/**
 * Geocentric ecliptic position of the moon, referred to the ecliptic and mean equinox of date.
 *
 * @param {Date} [date]
 * @returns {{longitude: number, latitude: number, distance: number}} degrees, degrees, and metres
 */
export const moonEcliptic = (date = new Date()) => {
  const days = (date.getTime() - SCHLYTER_EPOCH) / 86400000
  const state = positionAt(MOON_ELEMENTS, date)
  const { longitude, latitude, distance } = eclipticSpherical(state)

  const sun = solarAngles(days)
  const meanAnomaly = MOON_ELEMENTS.meanAnomaly + MOON_ELEMENTS.meanMotion * days
  const meanLongitude = MOON_ELEMENTS.ascendingNode + MOON_ELEMENTS.rates.ascendingNode * days
    + MOON_ELEMENTS.argumentOfPeriapsis + MOON_ELEMENTS.rates.argumentOfPeriapsis * days
    + meanAnomaly
  const elongation = meanLongitude - sun.meanLongitude   // how far from the sun, along the ecliptic
  const argumentOfLatitude = meanLongitude - (MOON_ELEMENTS.ascendingNode + MOON_ELEMENTS.rates.ascendingNode * days)

  return {
    longitude: wrapDegrees(longitude
      - 1.274 * sind(meanAnomaly - 2 * elongation)   // evection
      + 0.658 * sind(2 * elongation)                 // variation
      - 0.186 * sind(sun.meanAnomaly)),              // annual equation
    latitude: latitude - 0.173 * sind(argumentOfLatitude - 2 * elongation),
    distance: (distance
      - 0.58 * cosd(meanAnomaly - 2 * elongation)
      - 0.46 * cosd(2 * elongation)) * EARTH_RADIUS_M,
  }
}

/**
 * The sub-lunar point: where on earth the moon is directly overhead, and how far away it is.
 *
 * Same shape as `moonPosition` in `moon.js`, so the two can be compared value for value.
 *
 * @param {Date} [date]
 * @returns {{lng: number, lat: number, distance: number}} degrees and metres
 */
export const moonPosition = (date = new Date()) => {
  const { longitude, latitude, distance } = moonEcliptic(date)
  // fromJ2000 is false: these elements already track the equinox of date. Setting it would precess
  // a position that has been precessed once already, and put the moon 7° wrong at 1492.
  return eclipticToSubPoint({ longitude, latitude, distance }, date, { fromJ2000: false })
}

/** The moon as a planet-space vector in earth radii, +Y north, +X at 0°N 0°E. */
export const moonVector = (date = new Date()) => {
  const { lng, lat, distance } = moonPosition(date)
  const radii = distance / EARTH_RADIUS_M
  return [
    radii * cosd(lat) * cosd(lng),
    radii * sind(lat),
    radii * cosd(lat) * sind(lng),
  ]
}

/**
 * Illuminated fraction of the disc: 0 at new moon, 1 at full.
 *
 * FULL is when the earth and the sun lie in the same direction as seen FROM the moon, so the face
 * turned toward us is the lit one. The opposite convention gives a moon that reports full at new,
 * and it survives every visual check because a crescent still looks like a crescent. It has
 * happened here before. The test for it asserts the geometry, not the number.
 *
 * @param {Date} date
 * @param {number[]} sunDirection unit vector TO the sun, same planet-space frame as `moonVector`
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
