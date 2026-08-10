/**
 * celestial-frames.js — from an orbit's own frame to a point on the turning earth.
 *
 * `orbit.js` answers "where is it, in the ecliptic frame the elements were written in". This
 * answers "and where on the map does that put it". The chain is the one `moon.js` already runs at
 * the end of `moonPosition()`, kept in one place so every body in the sky agrees:
 *
 *     ecliptic longitude/latitude
 *       → (precess to the equinox of date, if the elements were referred to J2000)
 *       → equatorial right ascension/declination, by tilting through the obliquity
 *       → sub-point longitude, by subtracting the earth's sidereal rotation
 *
 * WHY PRECESSION IS NOT OPTIONAL. Sidereal time is measured from the vernal equinox OF DATE, and
 * the equinox moves — a full turn every 26,000 years, about 1.4° per century. Pair a J2000 right
 * ascension with an of-date GMST and a 1492 lesson is SEVEN DEGREES wrong, fourteen moon-widths,
 * with nothing on screen to say so. The moon's classical element set is already referred to the
 * equinox of date and needs none of this; the JPL planetary elements are referred to J2000 and
 * need all of it. `fromJ2000` is the switch, and getting it backwards is the expensive mistake
 * here, so it is tested against a real ephemeris in both positions.
 *
 * Time: UTC throughout, as `sun.js` and `moon.js` are. The ephemeris series properly want TT and
 * the earth's rotation properly wants UT1; the gap between them is `delta-t.js`, which says how
 * much that costs (0.031° for the moon at 1492) and is deliberately not applied here.
 */

const DEG = Math.PI / 180
const ARCSEC = 1 / 3600

/** J2000.0 — 2000-01-01 12:00 UTC. */
export const J2000 = Date.UTC(2000, 0, 1, 12)

/** Days since J2000, fractional and signed. Historical dates are large and negative. */
export const daysSinceJ2000 = (date) => (date.getTime() - J2000) / 86400000

/** Julian centuries since J2000 — the argument every polynomial below is written in. */
export const centuriesSinceJ2000 = (date) => daysSinceJ2000(date) / 36525

/**
 * Obliquity of the ecliptic in degrees: the angle between the earth's equator and its orbit, and
 * so the thing that makes seasons and bounds how far from the equator anything in the ecliptic can
 * ever appear.
 *
 * IAU 1980, cubic in centuries. It is currently shrinking by about 47 arcseconds a century, which
 * over the five centuries back to Columbus is a real 0.066° — small, but free to include.
 */
export const obliquityOfEcliptic = (date) => {
  const T = centuriesSinceJ2000(date)
  return 23.439291 + (-46.8150 * T - 0.00059 * T * T + 0.001813 * T * T * T) * ARCSEC
}

/**
 * Precess ecliptic longitude/latitude from the mean equinox of J2000 to the mean equinox of date.
 *
 * Three angles (Meeus, Astronomical Algorithms ch. 21): η, how far the ecliptic plane itself has
 * tipped; Π, where that tipping is hinged; and p, the general precession in longitude, which is
 * the large term — 1.396° per century.
 *
 * Rotating longitude by p alone and leaving latitude untouched is the common shortcut and is fine
 * on the ecliptic, but it is wrong by 0.06° in latitude for the moon at 1492 because it ignores
 * that the plane has moved. The full rotation gets that to 0.001°, measured against JPL Horizons.
 *
 * @param {{longitude: number, latitude: number}} coords degrees, referred to the J2000 ecliptic
 * @param {Date} date
 * @returns {{longitude: number, latitude: number}} degrees, referred to the ecliptic of date
 */
export const precessEclipticToDate = ({ longitude, latitude }, date) => {
  const T = centuriesSinceJ2000(date)
  const eta = (47.0029 * T - 0.03302 * T * T + 0.000060 * T ** 3) * ARCSEC * DEG
  const node = (174.876384 + (-869.8089 * T + 0.03536 * T * T) * ARCSEC) * DEG
  const general = (5029.0966 * T + 1.11113 * T * T - 0.000006 * T ** 3) * ARCSEC

  const lon = longitude * DEG
  const lat = latitude * DEG
  const sinOffset = Math.sin(node - lon)

  const a = Math.cos(eta) * Math.cos(lat) * sinOffset - Math.sin(eta) * Math.sin(lat)
  const b = Math.cos(lat) * Math.cos(node - lon)
  const c = Math.cos(eta) * Math.sin(lat) + Math.sin(eta) * Math.cos(lat) * sinOffset

  return {
    longitude: wrapDegrees(general + node / DEG - Math.atan2(a, b) / DEG),
    latitude: Math.asin(c) / DEG,
  }
}

/** Fold degrees into [0, 360). */
export const wrapDegrees = (angle) => ((angle % 360) + 360) % 360

/** Fold degrees into [-180, 180) — the convention every lng in this app uses. */
export const wrapLongitude = (angle) => wrapDegrees(angle + 180) - 180

/**
 * Ecliptic to equatorial: tilt the frame by the obliquity about the line of equinoxes.
 *
 * @param {{longitude: number, latitude: number, distance?: number}} coords degrees
 * @param {Date} date
 * @returns {{rightAscension: number, declination: number, distance: number}} degrees; RA in [0,360)
 */
export const eclipticToEquatorial = ({ longitude, latitude, distance = 1 }, date) => {
  const obliquity = obliquityOfEcliptic(date) * DEG
  const lon = longitude * DEG
  const lat = latitude * DEG

  const x = Math.cos(lon) * Math.cos(lat)
  const y = Math.sin(lon) * Math.cos(lat) * Math.cos(obliquity) - Math.sin(lat) * Math.sin(obliquity)
  const z = Math.sin(lon) * Math.cos(lat) * Math.sin(obliquity) + Math.sin(lat) * Math.cos(obliquity)

  return {
    rightAscension: wrapDegrees(Math.atan2(y, x) / DEG),
    declination: Math.atan2(z, Math.hypot(x, y)) / DEG,
    distance,
  }
}

/**
 * Greenwich Mean Sidereal Time in degrees — how far the earth has turned since the vernal equinox
 * last crossed the Greenwich meridian.
 *
 * The same linear form `moon.js` uses (18.697374558 hours at J2000, advancing 24.0657098244 hours
 * per solar day, the extra four minutes being the day the earth gains against the stars each
 * year), plus the quadratic term, which is worth 2.4 seconds of time at 1492 — a hundredth of a
 * degree of longitude, and one line.
 */
export const gmstDegrees = (date) => {
  const d = daysSinceJ2000(date)
  const T = d / 36525
  const hours = 18.697374558 + 24.06570982441908 * d + 0.000026 * T * T
  return wrapDegrees(hours * 15)
}

/**
 * The point on earth with the body directly overhead. Declination is the latitude outright;
 * longitude is the right ascension minus however far Greenwich has turned past the equinox.
 *
 * @param {{rightAscension: number, declination: number, distance?: number}} equatorial degrees
 * @param {Date} date
 * @returns {{lng: number, lat: number, distance: number}} lng in [-180, 180)
 */
export const subPoint = ({ rightAscension, declination, distance = 1 }, date) => ({
  lng: wrapLongitude(rightAscension - gmstDegrees(date)),
  lat: declination,
  distance,
})

/**
 * The whole chain in one call, from an ecliptic position to a point on the map.
 *
 * @param {{longitude: number, latitude: number, distance?: number}} ecliptic degrees
 * @param {Date} date
 * @param {{fromJ2000?: boolean}} [options] set `fromJ2000` when the coordinates are referred to
 *        the J2000 equinox (any JPL element set) rather than the equinox of date (the classical
 *        lunar and solar element sets). Seven degrees at 1492 rides on this flag.
 * @returns {{lng: number, lat: number, distance: number}}
 */
export const eclipticToSubPoint = (ecliptic, date, { fromJ2000 = false } = {}) => {
  const ofDate = fromJ2000
    ? { ...precessEclipticToDate(ecliptic, date), distance: ecliptic.distance }
    : ecliptic
  return subPoint(eclipticToEquatorial(ofDate, date), date)
}
