/**
 * planets.js — the eight major planets, on the same Kepler machinery as the moon.
 *
 * What this unlocks is the reason for the whole exercise: a lesson can put the sky where a person
 * actually saw it. Galileo's four points of light beside Jupiter on 7 January 1610, the conjunction
 * an annalist wrote down, Venus crossing the sun while Cook watched from Tahiti — all of those are
 * dated, and a dated sky is computable.
 *
 * THERE IS NOW A BETTER OPTION, and this file is kept for the cases where it is still the right
 * one. `vsop87.js` solves the real perturbation theory and is 36 to 1281 times more accurate over
 * the historical range. Prefer it for anything a lesson will show. What this module still has is
 * independence and size: eight small element sets rather than a megabyte of series, no ΔT
 * dependency, and a plain Keplerian ellipse that anyone can read. It is also the general machinery
 * — `orbit.js` will solve a comet or a spacecraft, and VSOP87 will not.
 *
 * ELEMENTS: E. M. Standish, "Keplerian Elements for Approximate Positions of the Major Planets"
 * (JPL Solar System Dynamics), Table 2a and 2b — the set fitted for 3000 BC to 3000 AD rather than
 * the tighter 1800-2050 set, because history is the point here. Referred to the mean ecliptic and
 * equinox of J2000, so anything heading for the map has to be precessed; see `celestial-frames.js`,
 * and note that the moon's elements are the opposite case.
 *
 * SECULAR TERMS ARE ALREADY IN THIS TABLE, which is the thing worth saying plainly, because
 * extrapolating a frozen J2000 snapshot five centuries and presenting it as exact is the obvious
 * way to get this wrong. Every element here carries a rate per century — Saturn's longitude of
 * perihelion moves 2.75° over the interval back to Columbus, and would be silently missing
 * otherwise. Jupiter through Neptune need Table 2b on top of that, for the great inequality: the
 * long resonances between the gas giants, which no linear rate can express. Dropping those terms
 * at 1492 costs Uranus 0.86°, Neptune 0.59°, Saturn 0.36° and Jupiter 0.15° of mean anomaly.
 *
 * ACCURACY IS MEASURED, NOT CLAIMED. `__tests__/planets.test.js` compares every planet against JPL
 * Horizons at six dates from 1492 to 2026 and asserts these, so they cannot rot. Worst case over
 * those six epochs, in degrees:
 *
 *                 heliocentric longitude   geocentric, as an observer sees it
 *     Mercury            0.006                        0.004
 *     Venus              0.003                        0.013
 *     Earth              0.003                          -
 *     Mars               0.032                        0.017
 *     Jupiter            0.141                        0.146
 *     Saturn             0.320                        0.348
 *     Uranus             0.202                        0.201
 *     Neptune            0.084                        0.082
 *
 * So: better than 0.02° for the four rocky planets, and worst of all 0.35° for Saturn — about
 * two thirds of a moon-width. Six dates are a spot check, not a bound; Standish quotes the table
 * itself as good to roughly 600 arcseconds (0.17°) for the inner planets and up to about 1000
 * arcseconds for the outer ones across the full 3000 BC to 3000 AD span, which is the number to
 * quote if a guarantee is ever needed rather than a measurement.
 */

import { eclipticToSubPoint } from './celestial-frames.js'
import { eclipticSpherical, stateFromElements, wrapDegreesSigned } from './orbit.js'

const DEG = Math.PI / 180

/** Astronomical unit in metres, IAU 2012 definition. */
export const AU_METRES = 149597870700

/**
 * Table 2a: value at J2000 and rate per Julian century, for 3000 BC to 3000 AD.
 * Order: semi-major axis (au), eccentricity, inclination, mean longitude, longitude of perihelion,
 * longitude of ascending node — the last four in degrees.
 *
 * `greatInequality` is Table 2b, the extra terms Jupiter through Neptune need in the mean anomaly.
 */
export const PLANET_ELEMENTS = {
  mercury: {
    semiMajorAxis: [0.38709843, 0.00000000],
    eccentricity: [0.20563661, 0.00002123],
    inclination: [7.00559432, -0.00590158],
    meanLongitude: [252.25166724, 149472.67486623],
    longitudeOfPerihelion: [77.45771895, 0.15940013],
    ascendingNode: [48.33961819, -0.12214182],
  },
  venus: {
    semiMajorAxis: [0.72332102, -0.00000026],
    eccentricity: [0.00676399, -0.00005107],
    inclination: [3.39777545, 0.00043494],
    meanLongitude: [181.97970850, 58517.81560260],
    longitudeOfPerihelion: [131.76755713, 0.05679648],
    ascendingNode: [76.67261496, -0.27274174],
  },
  // The Earth/Moon barycentre, which is what the table is fitted to. The earth itself wanders
  // 4,670 km either side of it over a month — 3.1e-5 au, under 0.004° seen from Mars at closest
  // approach, and below the accuracy of everything else here.
  earth: {
    semiMajorAxis: [1.00000018, -0.00000003],
    eccentricity: [0.01673163, -0.00003661],
    inclination: [-0.00054346, -0.01337178],
    meanLongitude: [100.46691572, 35999.37306329],
    longitudeOfPerihelion: [102.93005885, 0.31795260],
    ascendingNode: [-5.11260389, -0.24123856],
  },
  mars: {
    semiMajorAxis: [1.52371243, 0.00000097],
    eccentricity: [0.09336511, 0.00009149],
    inclination: [1.85181869, -0.00724757],
    meanLongitude: [-4.56813164, 19140.29934243],
    longitudeOfPerihelion: [-23.91744784, 0.45223625],
    ascendingNode: [49.71320984, -0.26852431],
  },
  jupiter: {
    semiMajorAxis: [5.20248019, -0.00002864],
    eccentricity: [0.04853590, 0.00018026],
    inclination: [1.29861416, -0.00322699],
    meanLongitude: [34.33479152, 3034.90371757],
    longitudeOfPerihelion: [14.27495244, 0.18199196],
    ascendingNode: [100.29282654, 0.13024619],
    greatInequality: { b: -0.00012452, c: 0.06064060, s: -0.35635438, f: 38.35125000 },
  },
  saturn: {
    semiMajorAxis: [9.54149883, -0.00003065],
    eccentricity: [0.05550825, -0.00032044],
    inclination: [2.49424102, 0.00451969],
    meanLongitude: [50.07571329, 1222.11494724],
    longitudeOfPerihelion: [92.86136063, 0.54179478],
    ascendingNode: [113.63998702, -0.25015002],
    greatInequality: { b: 0.00025899, c: -0.13434469, s: 0.87320147, f: 38.35125000 },
  },
  uranus: {
    semiMajorAxis: [19.18797948, -0.00020455],
    eccentricity: [0.04685740, -0.00001550],
    inclination: [0.77298127, -0.00180155],
    meanLongitude: [314.20276625, 428.49512595],
    longitudeOfPerihelion: [172.43404441, 0.09266985],
    ascendingNode: [73.96250215, 0.05739699],
    greatInequality: { b: 0.00058331, c: -0.97731848, s: 0.17689245, f: 7.67025000 },
  },
  neptune: {
    semiMajorAxis: [30.06952752, 0.00006447],
    eccentricity: [0.00895439, 0.00000818],
    inclination: [1.77005520, 0.00022400],
    meanLongitude: [304.22289287, 218.46515314],
    longitudeOfPerihelion: [46.68158724, 0.01009938],
    ascendingNode: [131.78635853, -0.00606302],
    greatInequality: { b: -0.00041348, c: 0.68346318, s: -0.10162547, f: 7.67025000 },
  },
}

/** The eight, in order out from the sun. */
export const PLANET_NAMES = Object.keys(PLANET_ELEMENTS)

const J2000 = Date.UTC(2000, 0, 1, 12)
const centuries = (date) => (date.getTime() - J2000) / 86400000 / 36525
const evaluate = ([value, rate], T) => value + rate * T

/**
 * The six Keplerian elements for a planet at a date, in the shape `orbit.js` takes.
 *
 * Standish's table gives mean LONGITUDE and longitude of PERIHELION, both measured from the
 * equinox rather than from the node, because those are the quantities that vary smoothly enough to
 * fit with a straight line. Kepler's equation wants the mean ANOMALY and the argument of
 * periapsis, so the last two lines convert:
 *
 *     ω = ϖ − Ω        argument of periapsis is the longitude of perihelion, less the node
 *     M = L − ϖ        mean anomaly is the mean longitude, less the longitude of perihelion
 *
 * @param {string} name  one of PLANET_NAMES
 * @param {Date} date
 * @returns {object} elements ready for `stateFromElements`, semi-major axis in au
 */
export const planetElementsAt = (name, date) => {
  const table = PLANET_ELEMENTS[name]
  if (!table) throw new RangeError(`planetElementsAt: no elements for "${name}"`)

  const T = centuries(date)
  const longitudeOfPerihelion = evaluate(table.longitudeOfPerihelion, T)
  const ascendingNode = evaluate(table.ascendingNode, T)

  let meanAnomaly = evaluate(table.meanLongitude, T) - longitudeOfPerihelion
  if (table.greatInequality) {
    // Table 2b. The gas giants trade angular momentum on beats of centuries, and a per-century
    // rate cannot express a beat, so these four carry an explicit quadratic and a sinusoid on top.
    // Leaving them out at 1492 costs Uranus 0.86°, Neptune 0.59°, Saturn 0.36°, Jupiter 0.15° —
    // asserted in the tests, because a term that quietly stops being applied is invisible.
    const { b, c, s, f } = table.greatInequality
    meanAnomaly += b * T * T + c * Math.cos(f * T * DEG) + s * Math.sin(f * T * DEG)
  }

  return {
    semiMajorAxis: evaluate(table.semiMajorAxis, T),
    eccentricity: evaluate(table.eccentricity, T),
    inclination: evaluate(table.inclination, T),
    ascendingNode,
    argumentOfPeriapsis: longitudeOfPerihelion - ascendingNode,
    meanAnomaly: wrapDegreesSigned(meanAnomaly),
  }
}

/**
 * Heliocentric position, in au, referred to the mean ecliptic and equinox of J2000.
 *
 * @param {string} name
 * @param {Date} [date]
 */
export const heliocentricPlanet = (name, date = new Date()) =>
  stateFromElements(planetElementsAt(name, date))

/**
 * Geocentric position — what someone standing on the earth is looking along. The difference of two
 * heliocentric vectors, which is why the earth's own elements have to be as good as the target's.
 *
 * @param {string} name
 * @param {Date} [date]
 * @returns {{x: number, y: number, z: number, longitude: number, latitude: number,
 *            distance: number}} au and degrees, J2000 ecliptic
 */
export const geocentricPlanet = (name, date = new Date()) => {
  if (name === 'earth') throw new RangeError('geocentricPlanet: the earth is not visible from itself')
  const planet = heliocentricPlanet(name, date)
  const earth = heliocentricPlanet('earth', date)
  const vector = { x: planet.x - earth.x, y: planet.y - earth.y, z: planet.z - earth.z }
  return { ...vector, ...eclipticSpherical(vector) }
}

/**
 * Where on earth the planet is directly overhead.
 *
 * `fromJ2000` is TRUE here and false in `moon-kepler.js`, and that is not an inconsistency: these
 * elements are referred to the J2000 equinox and the moon's are referred to the equinox of date.
 * Getting it the wrong way round is seven degrees at 1492 with nothing on screen to show for it.
 *
 * @param {string} name
 * @param {Date} [date]
 * @returns {{lng: number, lat: number, distance: number}} degrees and metres
 */
export const planetSubPoint = (name, date = new Date()) => {
  const { longitude, latitude, distance } = geocentricPlanet(name, date)
  return eclipticToSubPoint(
    { longitude, latitude, distance: distance * AU_METRES },
    date,
    { fromJ2000: true },
  )
}

/**
 * Elongation from the sun in degrees: 0° when the planet is lost in the glare, 180° when it rides
 * highest at midnight. The number that decides whether a lesson can honestly say it was visible.
 */
export const solarElongation = (name, date = new Date()) => {
  const planet = geocentricPlanet(name, date)
  const earth = heliocentricPlanet('earth', date)
  const toSun = { x: -earth.x, y: -earth.y, z: -earth.z }
  const dot = planet.x * toSun.x + planet.y * toSun.y + planet.z * toSun.z
  const lengths = Math.hypot(planet.x, planet.y, planet.z) * Math.hypot(toSun.x, toSun.y, toSun.z)
  return Math.acos(Math.max(-1, Math.min(1, dot / lengths))) / DEG
}
