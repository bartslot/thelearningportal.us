/**
 * eclipse.js — the moon's shadow on the earth, for a given moment.
 *
 * Every other lighting effect on this globe is a look. An eclipse is a DATE. It is the one piece of
 * sky geometry a history lesson can name — 1560 over Portugal, 1919 over Príncipe — and having the
 * shadow land where it actually landed is worth the arithmetic.
 *
 * WHAT IS BEING COMPUTED. Not a shadow volume: the fraction of the sun's DISC that the moon is
 * covering, as seen from each point on the ground. Both bodies are circles in the sky, so this is
 * the overlap of two circles, and the interesting cases are all degenerate — the moon entirely
 * inside the sun (annular: a ring of sun survives all the way round), entirely covering it (total),
 * or grazing.
 *
 * WHY IT HAS TO BE PER POINT. The moon is sixty earth radii away, near enough that its direction
 * genuinely differs from one side of the earth to the other. That parallax IS the narrow track:
 * compute one global moon direction and the umbra swells to cover half the planet, which still
 * looks like an eclipse and is wrong by a continent.
 *
 * WHY BOTH DISTANCES MATTER. Whether an eclipse is total or annular is decided by two numbers that
 * are both within a few percent of each other: the moon's distance (perigee to apogee moves its
 * disc by 12%) and earth's (perihelion moves the sun's by 3.4%). Fix either and half of all
 * eclipses come out the wrong kind.
 */

import { solarDistance, sunDirection } from './sun.js'
import { moonVector } from './moon.js'
import { planetSpacePosition } from './planet-mesh.js'

const SUN_RADIUS_M = 6.957e8
const MOON_RADIUS_M = 1737400
const EARTH_RADIUS_M = 6371008.8

/** The moon's radius in earth radii — the unit planet space measures everything in. */
export const MOON_RADIUS_EARTHS = MOON_RADIUS_M / EARTH_RADIUS_M

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

/**
 * The sun's angular radius, in radians, for a date.
 *
 * About 16 arcminutes, but it breathes by 3.4% over the year as earth runs from perihelion in
 * January to aphelion in July. That is not a rounding detail: it is one of the two terms that
 * decides whether a given eclipse is total or annular.
 */
export const solarAngularRadius = (date = new Date()) => Math.asin(SUN_RADIUS_M / solarDistance(date))

/**
 * The fraction of the sun's disc still showing, for two circles in the sky.
 *
 * Two circular segments, added. `x` is the distance from the sun's centre to the line where the
 * two discs cross, so `ths` and `thm` are each disc's half-angle at that chord, and a segment of
 * radius r subtending 2θ has area r²(θ - ½sin2θ). Dividing the covered area by πθs² and taking the
 * complement gives Lee's form directly.
 *
 * @param {number} sun        the sun's angular radius, radians
 * @param {number} moon       the moon's angular radius, radians
 * @param {number} separation angle between the two centres, radians
 */
export const solarVisibleFraction = (sun, moon, separation) => {
  if (separation >= sun + moon) return 1                  // no contact
  if (separation <= moon - sun) return 0                  // total: the moon covers the disc
  const ratioSquared = (moon * moon) / (sun * sun)
  if (separation <= sun - moon) return 1 - ratioSquared   // annular: a ring of sun survives

  const x = (separation * separation + sun * sun - moon * moon) / (2 * separation)
  const ths = Math.acos(clamp(x / sun, -1, 1))
  const thm = Math.acos(clamp((separation - x) / moon, -1, 1))
  return (Math.PI - ths + 0.5 * Math.sin(2 * ths)
          - thm * ratioSquared + 0.5 * ratioSquared * Math.sin(2 * thm)) / Math.PI
}

/**
 * How much sun reaches one point on the ground: 1 in the open, 0 under totality.
 *
 * @param {number[]} point  the surface point in planet space (earth = unit sphere)
 * @param {number[]} sun    direction TO the sun
 * @param {number[]} moon   the moon's POSITION in planet space, in earth radii
 * @param {number} sunRadius the sun's angular radius, radians
 */
export const eclipseAt = (point, sun, moon, sunRadius) => {
  const toMoon = [moon[0] - point[0], moon[1] - point[1], moon[2] - point[2]]
  const distance = Math.hypot(...toMoon)
  const moonRadius = Math.asin(clamp(MOON_RADIUS_EARTHS / distance, 0, 1))
  const separation = Math.acos(clamp(
    (sun[0] * toMoon[0] + sun[1] * toMoon[1] + sun[2] * toMoon[2]) / distance, -1, 1))
  return solarVisibleFraction(sunRadius, moonRadius, separation)
}

/**
 * The widest the moon's shadow can possibly reach, as an angle at earth's centre.
 *
 * Standing on the limb rather than at the centre swings the moon's apparent direction by up to
 * 1/60 of a radian, so the geocentric separation can be that much larger than the true one and
 * still produce an eclipse somewhere. Rounded up generously — a false "impossible" would delete
 * the feature silently, with a correct-looking globe and no error to explain the missing shadow.
 */
const POSSIBLE_SEPARATION = 0.035

/**
 * Is an eclipse worth looking for at all on this date?
 *
 * Eclipses are rare and the per-point test runs per FRAGMENT. This is the cheap geocentric check
 * that lets the shader skip the trigonometry on every other day in history. It is deliberately
 * conservative: it may say yes on a near miss, and must never say no during a real one.
 */
export const eclipseIsPossible = (date = new Date(), sun = null, moon = null) => {
  const sunDir = sun || sunDirection(date)
  const moonPos = moon || moonVector(date)
  const distance = Math.hypot(...moonPos)
  const separation = Math.acos(clamp(
    (sunDir[0] * moonPos[0] + sunDir[1] * moonPos[1] + sunDir[2] * moonPos[2]) / distance, -1, 1))
  return separation < POSSIBLE_SEPARATION
}

/**
 * Sweep the globe for the most eclipsed point, and say where it is.
 *
 * For tests and for telling a lesson where to point the camera — not for rendering, which does
 * this per fragment. Coarse then fine, because a two-degree grid can step straight over a track a
 * hundred kilometres wide.
 *
 * ONLY WHERE THE SUN IS UP. `eclipseAt` answers "how much of the sun's disc is the moon covering
 * along this sight line", which is the right question and is blind to whether the earth is in the
 * way. At new moon the moon sits roughly in the sun's direction, and it is SIXTY radii out — so
 * from a point on the night side it is still very nearly in front of the sun, and the geometry
 * happily reports a deep eclipse for someone standing in the dark. The first run of this put the
 * August 2026 totality over central Asia, at midnight. The renderer never sees this because its
 * daylight term is already zero there; a helper that names a place has to check.
 */
export const deepestEclipse = (date = new Date()) => {
  const sun = sunDirection(date)
  const moon = moonVector(date)
  const sunRadius = solarAngularRadius(date)

  let best = { fraction: 1, lng: 0, lat: 0 }
  const scan = (lng0, lng1, lat0, lat1, step) => {
    for (let lat = lat0; lat <= lat1; lat += step) {
      for (let lng = lng0; lng <= lng1; lng += step) {
        const point = planetSpacePosition(lng, lat, 0)
        // Above the horizon, or there is no sunlight here to take away.
        if (point[0] * sun[0] + point[1] * sun[1] + point[2] * sun[2] <= 0) continue
        const fraction = eclipseAt(point, sun, moon, sunRadius)
        if (fraction < best.fraction) best = { fraction, lng, lat }
      }
    }
  }

  scan(-180, 180, -90, 90, 2)
  if (best.fraction === 1) return best
  scan(best.lng - 3, best.lng + 3, best.lat - 3, best.lat + 3, 0.25)
  return best
}

/**
 * The same geometry in GLSL, evaluated per fragment.
 *
 * `u_eclipseSun` is 0 on every date without an eclipse, which is almost all of them, and the whole
 * block costs one comparison then.
 */
export const ECLIPSE_GLSL = /* glsl */`
  float solarVisibleFraction(float sun, float moon, float separation) {
    if (separation >= sun + moon) return 1.0;
    if (separation <= moon - sun) return 0.0;
    float ratioSquared = (moon * moon) / (sun * sun);
    if (separation <= sun - moon) return 1.0 - ratioSquared;

    float x = (separation * separation + sun * sun - moon * moon) / (2.0 * separation);
    float ths = acos(clamp(x / sun, -1.0, 1.0));
    float thm = acos(clamp((separation - x) / moon, -1.0, 1.0));
    return (3.14159265359 - ths + 0.5 * sin(2.0 * ths)
            - thm * ratioSquared + 0.5 * ratioSquared * sin(2.0 * thm)) / 3.14159265359;
  }

  // moonPos is the moon's POSITION in earth radii, not a direction — the difference is the whole
  // reason totality is a narrow track rather than a hemisphere.
  float eclipseLight(vec3 up, vec3 sunDir, vec3 moonPos, float sunRadius, float moonRadiusEarths) {
    if (sunRadius <= 0.0) return 1.0;
    vec3 toMoon = moonPos - up;
    float distance = length(toMoon);
    float moonRadius = asin(clamp(moonRadiusEarths / distance, 0.0, 1.0));
    float separation = acos(clamp(dot(sunDir, toMoon / distance), -1.0, 1.0));
    return solarVisibleFraction(sunRadius, moonRadius, separation);
  }
`
