/**
 * delta-t.js — the gap between the clock and the earth.
 *
 * ΔT = TT − UT. Ephemeris series run on Terrestrial Time, which ticks uniformly. Sidereal rotation
 * runs on UT, which is defined by the earth itself and is not uniform: tides have been slowing the
 * planet for as long as there has been a moon, and the accumulated difference is not small. At the
 * time of Columbus's landfall the two clocks were about three and a half minutes apart.
 *
 * WHO APPLIES IT, AND WHY THEY DIFFER. This is the one place in the directory where two modules
 * deliberately disagree, and the reason is the size of their own error bars.
 *
 *   APPLIED, by `vsop87.js` and `elp2000.js`. Those are good to about an arcsecond, and ΔT at 1492
 *   is 200 seconds — 110 arcseconds of moon and 34 of Mercury. Ignoring it would swamp the theory
 *   completely and make the whole exercise pointless.
 *
 *   NOT APPLIED, by `celestial-frames.js`, `sun.js`, `moon.js` and `moon-kepler.js`. Those are
 *   good to a tenth of a degree at best, where ΔT is worth 0.031° for the moon and less for
 *   everything else — an order of magnitude inside their own noise. Applying it there would add a
 *   dependency and change nothing measurable.
 *
 * SIZE OF THE ERROR, if you skip it. Feeding UT to a series that wants TT evaluates the body ΔT
 * early, so the position error is ΔT times the body's own apparent motion:
 *
 *     moon      0.55°/hour  →  0.031° at 1492 (ΔT ≈ 200 s), about a sixteenth of a moon's width
 *     sun       0.041°/hour →  0.0023° at 1492
 *     planets   under 0.04°/hour → below 0.003° at 1492
 *
 * A WARNING ABOUT PRECISION. ΔT before telescopes is not a computed quantity, it is a fit to
 * observed eclipses, and the observations run out. Different published models disagree by tens of
 * seconds in the fifteenth century. So for the moon before roughly 1600 the limit on knowing where
 * it was is not the ephemeris at all — it is ΔT, and no better lunar theory will move it.
 *
 * Polynomials: Espenak & Meeus, as published with the NASA Five Millennium Canon of Solar Eclipses.
 */

/** Decimal year, the argument every branch below is expressed in. */
const decimalYear = (date) => date.getUTCFullYear() + (date.getUTCMonth() + 0.5) / 12

const poly = (t, ...coefficients) => coefficients.reduce((sum, c, power) => sum + c * t ** power, 0)

/**
 * ΔT = TT − UT, in seconds, for any date from 2000 BC onward (and, less usefully, beyond 2150,
 * where it is an extrapolation nobody should lean on).
 *
 * @param {Date} date
 * @returns {number} seconds. Positive means TT runs ahead of UT.
 */
export const deltaTSeconds = (date) => {
  const y = decimalYear(date)

  if (y < -500) return -20 + 32 * ((y - 1820) / 100) ** 2
  if (y < 500) {
    return poly(y / 100, 10583.6, -1014.41, 33.78311, -5.952053, -0.1798452, 0.022174192, 0.0090316521)
  }
  if (y < 1600) {
    return poly((y - 1000) / 100, 1574.2, -556.01, 71.23472, 0.319781, -0.8503463, -0.005050998, 0.0083572073)
  }
  if (y < 1700) {
    const t = y - 1600
    return poly(t, 120, -0.9808, -0.01532) + t ** 3 / 7129
  }
  if (y < 1800) {
    const t = y - 1700
    return poly(t, 8.83, 0.1603, -0.0059285, 0.00013336) - t ** 4 / 1174000
  }
  if (y < 1860) {
    return poly(y - 1800, 13.72, -0.332447, 0.0068612, 0.0041116, -0.00037436, 0.0000121272, -0.0000001699, 0.000000000875)
  }
  if (y < 1900) {
    const t = y - 1860
    return poly(t, 7.62, 0.5737, -0.251754, 0.01680668, -0.0004473624) + t ** 5 / 233174
  }
  if (y < 1920) return poly(y - 1900, -2.79, 1.494119, -0.0598939, 0.0061966, -0.000197)
  if (y < 1941) return poly(y - 1920, 21.20, 0.84493, -0.076100, 0.0020936)
  if (y < 1961) {
    const t = y - 1950
    return 29.07 + 0.407 * t - t ** 2 / 233 + t ** 3 / 2547
  }
  if (y < 1986) {
    const t = y - 1975
    return 45.45 + 1.067 * t - t ** 2 / 260 - t ** 3 / 718
  }
  if (y < 2005) {
    return poly(y - 2000, 63.86, 0.3345, -0.060374, 0.0017275, 0.000651814, 0.00002373599)
  }
  if (y < 2050) return poly(y - 2000, 62.92, 0.32217, 0.005589)
  if (y < 2150) return -20 + 32 * ((y - 1820) / 100) ** 2 - 0.5628 * (2150 - y)
  return -20 + 32 * ((y - 1820) / 100) ** 2
}
