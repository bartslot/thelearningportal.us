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
const J2000 = Date.UTC(2000, 0, 1, 12) // the epoch these series are measured from

/** Days since J2000, fractional. */
const daysSinceEpoch = (date) => (date.getTime() - J2000) / 86400000

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
 * Earth's distance from the sun, in metres.
 *
 * The orbit is an ellipse, so this runs from 1.471e11 at perihelion in early January to 1.521e11 at
 * aphelion in early July — a 3.4% swing in the sun's apparent SIZE. That is invisible in lighting
 * and decisive in an eclipse: it is one of the two terms that settles whether the moon covers the
 * disc completely or leaves a ring of sun around it. See eclipse.js.
 */
export const solarDistance = (date = new Date()) => {
  const meanAnomaly = ((357.528 + 0.9856003 * daysSinceEpoch(date)) % 360) * DEG
  const astronomicalUnits = 1.00014 - 0.01671 * Math.cos(meanAnomaly) - 0.00014 * Math.cos(2 * meanAnomaly)
  return astronomicalUnits * 1.495978707e11
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
