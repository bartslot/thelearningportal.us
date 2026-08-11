/**
 * sky-camera.js — where the camera is pointing, in the coordinates the sky is drawn in.
 *
 * A sky drawn as geometry never needs this: the vertex shader projects the shell and the fragment
 * shader reads a direction off it. A sky drawn in SCREEN SPACE has no geometry to read a direction
 * from, so it has to build one — for every pixel, the ray leaving the camera through that pixel.
 * That is the whole trade, and it is worth making, because a ray has no length and therefore no
 * distance to be clipped at. See the note at the top of `starfield.js`.
 *
 * Reconstructing the ray needs four things in planet space: where the camera is, which way it looks,
 * which way is up on the screen, and which way is right. MapLibre keeps none of them in that form —
 * it has a centre, a bearing, a pitch and a projection matrix, and under the globe projection that
 * matrix is not something a fragment shader can invert on its own.
 *
 * The POSITION comes from MapLibre, because reconstructing it independently is wrong under pitch —
 * see `cameraInPlanetSpace` below. The three AXES are derived from the map, and derived without a
 * single cross product: each one comes from a finite difference on the sphere — north is the
 * direction you move when latitude increases, east is the direction you move when longitude
 * increases. That sounds fussier than `cross(up, north)` and it is deliberate: this map's planet
 * space lists its axes in an order that reverses handedness relative to the usual astronomical one,
 * so every cross product in it is a coin flip that looks correct either way round. Finite
 * differences cannot be flipped.
 *
 * The result is exact rather than approximate, and `sky-camera.test.js` checks it against MapLibre's
 * own `unproject` — if the two ever disagree about which way a pixel is looking, the sky is drawn
 * on the wrong part of the sky and nothing else would say so.
 */

import { cameraAltitudeMetres, planetSpacePosition } from './planet-mesh.js'

const DEG = Math.PI / 180

const normalise = (v) => {
  const length = Math.hypot(v[0], v[1], v[2]) || 1
  return [v[0] / length, v[1] / length, v[2] / length]
}
const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2]
const subtract = (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]]
/** b, with everything parallel to `axis` taken out of it. `axis` must already be a unit vector. */
const perpendicular = (b, axis) => {
  const along = dot(b, axis)
  return normalise([b[0] - axis[0] * along, b[1] - axis[1] * along, b[2] - axis[2] * along])
}

/**
 * The local compass at a point on the globe, by finite difference.
 *
 * A hundredth of a degree is small enough that the chord and the tangent agree to a part in ten
 * million, and large enough that float64 has no trouble with the subtraction.
 */
const compassAt = (lng, lat) => {
  const step = 0.01
  // Near the poles a step in latitude runs off the end, so it is taken on the inside instead. The
  // direction is the same either way; only the sign of the step changes.
  const northward = lat > 89.98 ? -1 : 1
  const north = normalise(subtract(
    planetSpacePosition(lng, lat + step * northward),
    planetSpacePosition(lng, lat - step * northward),
  ))
  const east = normalise(subtract(
    planetSpacePosition(lng + step, lat),
    planetSpacePosition(lng - step, lat),
  ))
  return {
    north: northward === 1 ? north : [-north[0], -north[1], -north[2]],
    east,
  }
}

/**
 * Where the camera is, in planet space.
 *
 * Taken from MapLibre's own `transform.cameraPosition` rather than worked out from the camera's
 * longitude, latitude and altitude. Those were tried first and they are WRONG UNDER PITCH: at pitch
 * 60 the reconstruction put the camera 43° of arc from where MapLibre had it, which drew an earth
 * shadow far larger than the earth and blanked most of the sky behind a curved edge that looked
 * quite deliberate. `getCameraAltitude()` returns null without an elevation source, and the pixel
 * fallback behind it does not survive a tilted camera.
 *
 * MapLibre's globe space lists the same three axes in a different order — its x is our z and its z
 * is our x, a swap and therefore a mirror, which is why this is a relabelling and not a rotation.
 * Verified against five camera arrangements: at pitch 0 the swapped vector sits exactly over the
 * map centre, and at pitch 60 it sits 45.07° behind it, which is what the geometry says to a
 * hundredth of a degree.
 */
const cameraInPlanetSpace = (map, maplibregl) => {
  const raw = map?.transform?.cameraPosition
  if (raw && Number.isFinite(raw[0]) && Number.isFinite(raw[1]) && Number.isFinite(raw[2])) {
    return [raw[2], raw[1], raw[0]]
  }
  // Only reachable if MapLibre stops exposing that vector. Correct without pitch, and better than
  // nothing with it.
  const transform = map?.transform
  const lngLat = typeof transform?.getCameraLngLat === 'function' ? transform.getCameraLngLat() : map.getCenter()
  return planetSpacePosition(lngLat.lng, lngLat.lat, cameraAltitudeMetres(map, maplibregl))
}

/**
 * The camera's frame in planet space, with the screen axes already scaled by the field of view, so
 * a shader can build a ray from normalised device coordinates with two multiplies and an add:
 *
 *     ray = normalize(forward + right * ndc.x + up * ndc.y)
 *
 * @param {object} map          MapLibre map
 * @param {object} maplibregl   the module, for MercatorCoordinate
 * @param {number} fov          vertical field of view in RADIANS, as MapLibre's render arguments give it
 * @param {number} aspect       canvas width over height
 */
export const viewRayBasis = (map, maplibregl, fov, aspect) => {
  const centre = map.getCenter()
  const bearing = (map.getBearing?.() ?? 0) * DEG

  const target = planetSpacePosition(centre.lng, centre.lat, 0)
  const origin = cameraInPlanetSpace(map, maplibregl)

  const forward = normalise(subtract(target, origin))

  // Which way is up on the screen, as a direction ON THE GROUND: the compass bearing the map is
  // turned to. Bearing 90 puts east at the top, which is MapLibre's convention and the opposite of
  // the one you get by guessing.
  const { north, east } = compassAt(centre.lng, centre.lat)
  const screenUpOnGround = [0, 1, 2].map((i) => north[i] * Math.cos(bearing) + east[i] * Math.sin(bearing))
  // Ninety degrees clockwise from that, which is screen right. With pitch this one is already
  // perpendicular to the view direction — the camera tilts in the up/forward plane and leaves the
  // right axis alone — but it is projected anyway so that the frame is orthonormal by construction.
  const screenRightOnGround = [0, 1, 2].map((i) => -north[i] * Math.sin(bearing) + east[i] * Math.cos(bearing))

  const up = perpendicular(screenUpOnGround, forward)
  const right = perpendicular(screenRightOnGround, forward)

  const halfHeight = Math.tan(fov / 2)
  const halfWidth = halfHeight * aspect
  return {
    origin,
    forward,
    up: up.map((v) => v * halfHeight),
    right: right.map((v) => v * halfWidth),
    // The unscaled axes, for anything that needs the frame itself rather than a ray.
    upUnit: up,
    rightUnit: right,
  }
}

/**
 * The ray leaving the camera through a point on the screen, in planet space.
 *
 * The same arithmetic the fragment shader does, in JavaScript, so it can be checked against
 * MapLibre's own projection without a GPU in the loop.
 *
 * @param {object} basis  from `viewRayBasis`
 * @param {number} ndcX   -1 at the left edge of the canvas, +1 at the right
 * @param {number} ndcY   -1 at the bottom, +1 at the top
 */
export const rayThrough = (basis, ndcX, ndcY) => normalise([0, 1, 2].map(
  (i) => basis.forward[i] + basis.right[i] * ndcX + basis.up[i] * ndcY,
))

/**
 * Where a ray from the camera first meets the earth, or null if it misses.
 *
 * The earth is the unit sphere at the origin in this space, which is what makes the whole
 * screen-space approach possible: the horizon is exact arithmetic at any distance, with no depth
 * buffer involved. At the range this sky is drawn from, the depth buffer has no precision left to
 * offer anyway.
 */
export const earthHit = (origin, ray) => {
  const b = dot(origin, ray)
  const c = dot(origin, origin) - 1
  const discriminant = b * b - c
  if (discriminant < 0) return null
  const distance = -b - Math.sqrt(discriminant)
  if (distance <= 0) return null
  return [0, 1, 2].map((i) => origin[i] + ray[i] * distance)
}
