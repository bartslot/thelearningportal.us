/**
 * sun.js — where the sun actually is, for a given moment.
 *
 * Every lit layer on the globe takes a sun direction, and until now each one was handed a made-up
 * vector chosen to make a screenshot look good. That works until two layers disagree and the
 * clouds are lit from a different side than the atmosphere, or until a lesson set in January shows
 * the Arctic in daylight. This computes the real thing.
 *
 * The subsolar point — the one place on earth where the sun is directly overhead — is fixed by two
 * quantities:
 *
 *  - DECLINATION, the sun's latitude. It swings between ±23.44° over the year, and it IS the
 *    seasons: at the June solstice it sits on the Tropic of Cancer, so the Arctic never turns away
 *    from the sun and the Antarctic never turns toward it.
 *  - The EQUATION OF TIME, which is why noon is not noon. Earth's orbit is elliptical and its axis
 *    tilted, so true solar noon drifts up to ±16 minutes either side of clock noon across the year
 *    — four degrees of longitude. Ignore it and the terminator sits visibly wrong in early November.
 *
 * Accuracy is about a tenth of a degree, which is far past what a globe at classroom scale can
 * show. Almanac-grade positions need nutation and aberration terms; those matter for eclipses, not
 * for lighting.
 */

const DEG = Math.PI / 180
/**
 * J2000.0 — 2000-01-01 12:00 UTC. Correct FOR THIS FILE, and deliberately different from moon.js.
 *
 * The series below are the Astronomical Almanac's low-precision solar formulae, which are measured
 * from J2000. moon.js uses Schlyter's elements, which count from "2000 January 0.0" — 1999-12-31
 * 00:00 UTC, a day and a half earlier. Two neighbouring files, two epochs, and nothing in either
 * one says so on its face.
 *
 * That is not a tidy-up waiting to happen: unifying them breaks whichever series does not own the
 * epoch. It cost a 20 degree moon once (see sky-absolute.test.js) and then nearly cost a 1.5 degree
 * sun when the fix was applied here too, out of symmetry rather than evidence.
 */
const EPOCH = Date.UTC(2000, 0, 1, 12)

/** Days since the epoch above, fractional. */
const daysSinceEpoch = (date) => (date.getTime() - EPOCH) / 86400000

/**
 * The sun's declination and the equation of time, from the low-precision series in the
 * Astronomical Almanac — good to ~0.01° over 1950–2050.
 *
 * @returns {{declination: number, equationOfTime: number}} degrees, and minutes
 */
export const solarPosition = (date = new Date()) => {
  const n = daysSinceEpoch(date)
  const meanLongitude = (280.460 + 0.9856474 * n) % 360
  const meanAnomaly = ((357.528 + 0.9856003 * n) % 360) * DEG
  // The ellipse and the tilt, as one correction: true position minus mean position.
  const eclipticLongitude = (meanLongitude + 1.915 * Math.sin(meanAnomaly) + 0.020 * Math.sin(2 * meanAnomaly)) * DEG
  const obliquity = (23.439 - 0.0000004 * n) * DEG

  const declination = Math.asin(Math.sin(obliquity) * Math.sin(eclipticLongitude)) / DEG

  let rightAscension = Math.atan2(Math.cos(obliquity) * Math.sin(eclipticLongitude), Math.cos(eclipticLongitude)) / DEG
  if (rightAscension < 0) rightAscension += 360
  // Equation of time: how far the real sun runs ahead of or behind the clock, in minutes.
  let equationOfTime = (meanLongitude - rightAscension)
  if (equationOfTime > 180) equationOfTime -= 360
  if (equationOfTime < -180) equationOfTime += 360
  equationOfTime *= 4    // degrees of earth rotation -> minutes of time

  return { declination, equationOfTime }
}

/**
 * The point on earth with the sun straight overhead.
 *
 * @returns {{lng: number, lat: number}} degrees
 */
export const subsolarPoint = (date = new Date()) => {
  const { declination, equationOfTime } = solarPosition(date)
  const utcHours = date.getUTCHours() + date.getUTCMinutes() / 60 + date.getUTCSeconds() / 3600
  let lng = -15 * (utcHours - 12 + equationOfTime / 60)
  // Keep it in ±180 so callers can compare longitudes without thinking about the wrap.
  lng = ((lng + 540) % 360) - 180
  return { lng, lat: declination }
}

/**
 * The direction TO the sun, as a unit vector in planet space (+Y north, +X at 0°N 0°E) — the form
 * every shader here wants. Distance is irrelevant: at 150 million kilometres the rays are parallel
 * across the whole earth, which is exactly why a single direction is enough to light all of it.
 */
export const sunDirection = (date = new Date()) => {
  const { lng, lat } = subsolarPoint(date)
  const latRad = lat * DEG
  const lngRad = lng * DEG
  return [
    Math.cos(latRad) * Math.cos(lngRad),
    Math.sin(latRad),
    Math.cos(latRad) * Math.sin(lngRad),
  ]
}
