/**
 * celestial.js — where the sky actually was.
 *
 * This map already knows where the sun and the moon were on any date. The stars were the one thing
 * still made up: a panorama pasted on at an arbitrary angle, scenery rather than sky. This is the
 * arithmetic that puts them back where they belong, so that a lesson set on the night Columbus made
 * landfall shows the sky over that ocean on that night.
 *
 * THREE ROTATIONS SIT BETWEEN A CATALOGUE AND THE SCREEN.
 *
 *  1. The catalogue is quoted for the epoch J2000 — a fixed grid, frozen in the year 2000.
 *  2. The earth's axis swings round a 26,000-year circle, so the grid of the DATE is tilted away
 *     from the J2000 one. This is precession, and over the span a history map covers it is not a
 *     subtlety: in 2700 BC the pole star was Thuban, not Polaris, thirty degrees away round the
 *     circle. Ignore it and every ancient sky is wrong by a constellation.
 *  3. The earth turns. Which meridian faces a given right ascension is sidereal time, and it is the
 *     same quantity `moon.js` already uses to place the sub-lunar point — deliberately the same
 *     expression, because two sidereal times in one map is one too many.
 *
 * WHAT COMES OUT. `skyFrameMatrix` is the whole chain as a single 3x3, mapping a direction in
 * PLANET SPACE — the convention `planet-mesh.js` defines, +Y north, +X at 0°N 0°E — to a direction
 * on the J2000 grid, which is what the panorama is drawn in. The shader multiplies by it to look up
 * the sky, and multiplies by its TRANSPOSE to place a catalogue star. One uniform, two directions,
 * and no way for the two to drift apart.
 *
 * A NOTE ON MIRRORS. Getting a sky backwards is the easiest mistake in this file and the hardest to
 * see: a mirrored sky still looks like a sky, still turns the right way as the camera orbits, and
 * raises no error anywhere. It shipped here once already. So the UV convention below is not
 * reasoned about — it was MEASURED against the panorama itself, by sampling where the galactic
 * centre ought to be under each of the four candidate conventions. The right one is brighter than
 * the galactic poles by a factor of 26 and brighter than the anticentre by a factor of 26; the
 * three wrong ones score 2.1, 1.1 and 0.5. `celestial.test.js` keeps that measurement running
 * against a thumbnail of the shipped image.
 */

const DEG = Math.PI / 180
const J2000 = Date.UTC(2000, 0, 1, 12)
const ARCSEC = 1 / 3600

/**
 * How far either side of J2000 this is worth believing.
 *
 * The precession series below is a cubic fitted near the epoch. Its linear term carries almost all
 * of the motion, so it degrades gently rather than falling apart, but by a few thousand years out
 * the error is of order a degree — two moon-widths, visible if you are looking for it and invisible
 * if you are not. Beyond that, use a long-term model (Vondrák 2011) instead of trusting this.
 *
 * Sidereal time has its own limit that no model fixes: for ancient dates the earth's rotation angle
 * is genuinely uncertain by hours, because the length of the day has changed. Which constellation
 * is overhead at a stated hour in 2700 BC is not a knowable quantity. Which constellations exist,
 * and where they sit relative to each other and to the pole, is.
 */
export const SKY_EPOCH_LIMIT_YEARS = 5000

/** Days since the J2000 epoch, fractional. */
const daysSinceEpoch = (date) => (date.getTime() - J2000) / 86400000

/**
 * Greenwich mean sidereal time, in degrees.
 *
 * The same expression `moon.js` uses to turn a right ascension into a longitude. Sidereal days are
 * about four minutes shorter than solar ones — the earth has to turn slightly further than a full
 * rotation to face the sun again — and using the solar day instead drifts the sky a full turn over
 * a year while looking perfectly convincing on any single night.
 */
export const gmstDegrees = (date = new Date()) => {
  const hours = (18.697374558 + 24.06570982441908 * daysSinceEpoch(date)) % 24
  return ((hours * 15) % 360 + 360) % 360
}

/**
 * The three precession angles, IAU 1976 (Lieske et al.), in degrees.
 *
 * zeta and z rotate about the pole and theta tips it; between them they carry the J2000 grid onto
 * the grid of the date.
 */
const precessionAngles = (date) => {
  const T = daysSinceEpoch(date) / 36525
  return {
    zeta: (2306.2181 * T + 0.30188 * T * T + 0.017998 * T * T * T) * ARCSEC,
    z: (2306.2181 * T + 1.09468 * T * T + 0.018203 * T * T * T) * ARCSEC,
    theta: (2004.3109 * T - 0.42665 * T * T - 0.041833 * T * T * T) * ARCSEC,
  }
}

/** J2000 coordinates to the mean equinox of date. The standard rigorous reduction. */
const precess = (raDeg, decDeg, date) => {
  const { zeta, z, theta } = precessionAngles(date)
  const ra = (raDeg + zeta) * DEG
  const dec = decDeg * DEG
  const th = theta * DEG
  const a = Math.cos(dec) * Math.sin(ra)
  const b = Math.cos(th) * Math.cos(dec) * Math.cos(ra) - Math.sin(th) * Math.sin(dec)
  const c = Math.sin(th) * Math.cos(dec) * Math.cos(ra) + Math.cos(th) * Math.sin(dec)
  return { ra: Math.atan2(a, b) / DEG + z, dec: Math.asin(Math.min(1, Math.max(-1, c))) / DEG }
}

/** The mean equinox of date back to J2000 — `precess` run backwards. */
const unprecess = (raDeg, decDeg, date) => {
  const { zeta, z, theta } = precessionAngles(date)
  const ra = (raDeg - z) * DEG
  const dec = decDeg * DEG
  const th = theta * DEG
  const a = Math.cos(dec) * Math.sin(ra)
  const b = Math.cos(th) * Math.cos(dec) * Math.cos(ra) + Math.sin(th) * Math.sin(dec)
  const c = -Math.sin(th) * Math.cos(dec) * Math.cos(ra) + Math.cos(th) * Math.sin(dec)
  return { ra: Math.atan2(a, b) / DEG - zeta, dec: Math.asin(Math.min(1, Math.max(-1, c))) / DEG }
}

const wrap360 = (deg) => ((deg % 360) + 360) % 360

/**
 * Where a catalogue star sits in planet space at a given moment.
 *
 * Precess the J2000 coordinates to the date, then subtract sidereal time: right ascension minus
 * GMST is the longitude the star stands over. After that it is the map's own coordinate system and
 * everything else on this globe agrees with it.
 *
 * @param {number} raDeg   right ascension, J2000, degrees
 * @param {number} decDeg  declination, J2000, degrees
 * @returns {number[]} unit vector, +Y north, +X at 0°N 0°E
 */
export const j2000ToPlanet = (raDeg, decDeg, date = new Date()) => {
  const now = precess(raDeg, decDeg, date)
  const lng = (now.ra - gmstDegrees(date)) * DEG
  const lat = now.dec * DEG
  return [Math.cos(lat) * Math.cos(lng), Math.sin(lat), Math.cos(lat) * Math.sin(lng)]
}

/**
 * The inverse: which patch of the J2000 sky a direction in planet space is pointing at. This is the
 * one the panorama lookup needs — a view ray goes in, a place on the sky comes out.
 *
 * @param {number[]} dir  unit vector in planet space
 * @returns {{ra: number, dec: number}} degrees, J2000
 */
export const planetToJ2000 = (dir, date = new Date()) => {
  const length = Math.hypot(dir[0], dir[1], dir[2]) || 1
  const lng = Math.atan2(dir[2] / length, dir[0] / length) / DEG
  const lat = Math.asin(Math.min(1, Math.max(-1, dir[1] / length))) / DEG
  const back = unprecess(wrap360(lng + gmstDegrees(date)), lat, date)
  return { ra: wrap360(back.ra), dec: back.dec }
}

/**
 * Where a point on the J2000 sky lands in the panorama.
 *
 * MEASURED, not derived — see the note at the top of this file. The image runs right ascension
 * left to right across the full turn, and declination top to bottom from the north celestial pole.
 */
export const panoramaUV = (raDeg, decDeg) => [wrap360(raDeg) / 360, (90 - decDeg) / 180]

/**
 * The same lookup in GLSL, taking a direction rather than angles, so the shader and the tests
 * cannot disagree about which way round the sky is.
 *
 * A direction on the J2000 grid has right ascension atan2(z, x) and declination asin(y), in the
 * planet-space axis convention this map uses throughout.
 */
export const PANORAMA_UV_GLSL = /* glsl */`
  vec2 panoramaUV(vec3 skyDir) {
    return vec2(atan(skyDir.z, skyDir.x) / 6.28318530718 + 1.0,
                0.5 - asin(clamp(skyDir.y, -1.0, 1.0)) / 3.14159265359);
  }
`

/**
 * The whole chain as one 3x3, column-major, ready for `uniformMatrix3fv`.
 *
 * Built by running the scalar transform on the three basis vectors rather than by multiplying three
 * rotation matrices out by hand. That is not laziness: every sign error this file could contain
 * lives in those products, and this way the matrix is correct by construction if `planetToJ2000`
 * is — which the tests check against Thuban, Polaris and the galactic centre.
 *
 * @returns {Float32Array} maps planet space to the J2000 sky; its transpose does the reverse
 */
export const skyFrameMatrix = (date = new Date()) => {
  const matrix = new Float32Array(9)
  const basis = [[1, 0, 0], [0, 1, 0], [0, 0, 1]]
  basis.forEach((axis, column) => {
    const { ra, dec } = planetToJ2000(axis, date)
    matrix[column * 3] = Math.cos(dec * DEG) * Math.cos(ra * DEG)
    matrix[column * 3 + 1] = Math.sin(dec * DEG)
    matrix[column * 3 + 2] = Math.cos(dec * DEG) * Math.sin(ra * DEG)
  })
  return matrix
}
