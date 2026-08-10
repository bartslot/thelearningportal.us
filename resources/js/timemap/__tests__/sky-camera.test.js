import { describe, it, expect } from 'vitest'
import { viewRayBasis, rayThrough, earthHit } from '../sky-camera.js'

/**
 * The camera frame, checked against things that can be stated without reference to the code.
 *
 * The failure this guards against is a sky that is subtly rotated — up and right swapped, or a
 * bearing applied the wrong way round. None of those look wrong. A sky is a sky at any angle, and
 * the only way to catch it is to assert on directions that have names: north, east, down.
 */

const DEG = Math.PI / 180
const FOV = 0.6435011087932844   // MapLibre's default, in radians

const EARTH_RADIUS_M = 6371008.8
const DEG_ = Math.PI / 180

/**
 * A map whose camera is at a stated longitude, latitude and radius.
 *
 * `cameraPosition` is given in MapLibre's globe axes, which list ours in the order z, y, x — the
 * swap this module has to undo. Writing the stub in MapLibre's convention rather than ours is the
 * point: a test that fed it planet space would pass with the swap removed.
 */
const mapAt = ({ lng = 0, lat = 0, bearing = 0, pitch = 0, radii = 2.41 } = {}) => {
  const planet = [
    Math.cos(lat * DEG_) * Math.cos(lng * DEG_) * radii,
    Math.sin(lat * DEG_) * radii,
    Math.cos(lat * DEG_) * Math.sin(lng * DEG_) * radii,
  ]
  return {
    getCenter: () => ({ lng, lat }),
    getBearing: () => bearing,
    getPitch: () => pitch,
    getZoom: () => 2,
    transform: {
      cameraPosition: [planet[2], planet[1], planet[0]],
      getCameraLngLat: () => ({ lng, lat }),
      getCameraAltitude: () => (radii - 1) * EARTH_RADIUS_M,
    },
  }
}

/** The same map with a camera somewhere else entirely — a pitched view looks like this. */
const mapLookingFrom = ({ centre, camera }) => ({
  ...mapAt({ lng: centre[0], lat: centre[1] }),
  getCenter: () => ({ lng: centre[0], lat: centre[1] }),
  transform: {
    cameraPosition: [camera[2], camera[1], camera[0]],
    getCameraLngLat: () => ({ lng: centre[0], lat: centre[1] }),
    getCameraAltitude: () => 9e6,
  },
})

const maplibreStub = {
  MercatorCoordinate: {
    fromLngLat: () => ({ meterInMercatorCoordinateUnits: () => 1 / 4e7 }),
  },
}

const close = (actual, expected, digits = 5) =>
  actual.forEach((v, i) => expect(v).toBeCloseTo(expected[i], digits))

describe('the camera frame over the Gulf of Guinea', () => {
  // Centre at 0°N 0°E: the point (1, 0, 0) in planet space, with north at +Y and east at +Z.
  const basis = () => viewRayBasis(mapAt(), maplibreStub, FOV, 1)

  it('looks straight down at the point under it', () => {
    close(basis().forward, [-1, 0, 0])
  })

  it('puts north at the top of the screen and east on the right', () => {
    close(basis().upUnit, [0, 1, 0])
    close(basis().rightUnit, [0, 0, 1])
  })

  it('turns the world, not the camera, when the map is rotated', () => {
    // Bearing 90 means east is up. If the sign were flipped this would put WEST at the top, and
    // the map would still look like a map.
    const turned = viewRayBasis(mapAt({ bearing: 90 }), maplibreStub, FOV, 1)
    close(turned.upUnit, [0, 0, 1])       // east
    close(turned.rightUnit, [0, -1, 0])   // south
  })

  it('reads the camera out of MapLibre rather than rebuilding it', () => {
    // MapLibre lists the same axes as x, y, z where this map lists them z, y, x. Undo the swap
    // wrongly and the camera goes to the far side of the planet, which does not look like an error
    // — it looks like an earth-shaped shadow across most of the sky.
    close(viewRayBasis(mapAt({ lng: 0, lat: 0, radii: 3 }), maplibreStub, FOV, 1).origin, [3, 0, 0])
    close(viewRayBasis(mapAt({ lng: 90, lat: 0, radii: 3 }), maplibreStub, FOV, 1).origin, [0, 0, 3])
    close(viewRayBasis(mapAt({ lng: 0, lat: 90, radii: 3 }), maplibreStub, FOV, 1).origin, [0, 3, 0])
  })

  it('looks where a pitched camera looks, not straight down', () => {
    /**
     * The failure this is here for: with pitch, the camera is nowhere near above the map centre —
     * at pitch 60 it sits some 45° of arc behind it. Working its position out from the camera's own
     * longitude and altitude got that wrong by more than 40°, which put an analytic horizon far
     * larger than the earth across the screen and discarded most of the sky behind it.
     */
    const basis = viewRayBasis(
      mapLookingFrom({ centre: [0, 0], camera: [2.75, -1.93, 0] }),  // behind and below the target
      maplibreStub, FOV, 1,
    )
    // The view direction leans north, because the camera is south of what it is looking at.
    expect(basis.forward[1]).toBeGreaterThan(0.5)
    expect(basis.forward[0]).toBeLessThan(0)
    // And the horizon is where a camera at that radius sees it, not where one overhead would.
    const grazing = Math.asin(1 / Math.hypot(2.75, 1.93, 0)) / DEG
    expect(grazing).toBeGreaterThan(17)
    expect(grazing).toBeLessThan(19)
  })

  it('keeps the frame orthonormal at every bearing', () => {
    for (const bearing of [0, 37, 90, 180, 305]) {
      const b = viewRayBasis(mapAt({ bearing }), maplibreStub, FOV, 1)
      const dot = (p, q) => p[0] * q[0] + p[1] * q[1] + p[2] * q[2]
      expect(Math.hypot(...b.forward)).toBeCloseTo(1, 6)
      expect(Math.hypot(...b.upUnit)).toBeCloseTo(1, 6)
      expect(Math.hypot(...b.rightUnit)).toBeCloseTo(1, 6)
      expect(dot(b.forward, b.upUnit)).toBeCloseTo(0, 6)
      expect(dot(b.forward, b.rightUnit)).toBeCloseTo(0, 6)
      expect(dot(b.upUnit, b.rightUnit)).toBeCloseTo(0, 6)
    }
  })
})

describe('rays through the screen', () => {
  const basis = viewRayBasis(mapAt(), maplibreStub, FOV, 1.5)

  it('sends the middle of the screen straight ahead', () => {
    close(rayThrough(basis, 0, 0), basis.forward)
  })

  it('opens to the field of view the projection was given, and no other', () => {
    // Top edge against bottom edge is the full vertical field of view. Getting this wrong scales
    // the whole sky: the stars are in the right places relative to each other and the wrong size
    // relative to the earth, which reads as the earth being the wrong size.
    const top = rayThrough(basis, 0, 1)
    const bottom = rayThrough(basis, 0, -1)
    const between = Math.acos(top[0] * bottom[0] + top[1] * bottom[1] + top[2] * bottom[2])
    expect(between / DEG).toBeCloseTo(FOV / DEG, 4)
  })

  it('stretches horizontally with the aspect ratio, not vertically', () => {
    const left = rayThrough(basis, -1, 0)
    const right = rayThrough(basis, 1, 0)
    const horizontal = Math.acos(left[0] * right[0] + left[1] * right[1] + left[2] * right[2])
    // A wide canvas sees more sky sideways. The relation is through the tangent, not the angle.
    expect(Math.tan(horizontal / 2)).toBeCloseTo(1.5 * Math.tan(FOV / 2), 5)
  })

  it('points a rotated screen the same way the map is rotated', () => {
    const turned = viewRayBasis(mapAt({ bearing: 90 }), maplibreStub, FOV, 1)
    // Up the screen is now east, so a ray through the top of the screen leans east of straight down.
    expect(rayThrough(turned, 0, 1)[2]).toBeGreaterThan(0)
    expect(Math.abs(rayThrough(turned, 0, 1)[1])).toBeLessThan(1e-6)
  })
})

describe('what the earth is in the way of', () => {
  const origin = [3, 0, 0]   // three earth radii out, over 0°N 0°E

  it('finds the planet dead ahead', () => {
    const hit = earthHit(origin, [-1, 0, 0])
    expect(hit).not.toBeNull()
    close(hit, [1, 0, 0])
  })

  it('lets a ray past the limb through to the sky', () => {
    // The horizon from three radii out is at asin(1/3) = 19.47° off the centre line. Just outside
    // it there must be sky, and just inside it must be earth — this is the edge the whole layer
    // hangs on, because it is what stops an opaque sky painting over the planet.
    const at = (deg) => {
      const a = deg * DEG
      return earthHit(origin, [-Math.cos(a), Math.sin(a), 0])
    }
    expect(at(19)).not.toBeNull()
    expect(at(20)).toBeNull()
  })

  it('ignores the earth behind the camera', () => {
    expect(earthHit(origin, [1, 0, 0])).toBeNull()
  })

  it('still works when the camera is far outside any sky shell that could be drawn', () => {
    // Twenty earth radii — three times past the distance where MapLibre's globe projection drops
    // geometry, and four times past where the old sky sphere sat. The arithmetic does not care.
    const far = [20, 0, 0]
    expect(earthHit(far, [-1, 0, 0])).not.toBeNull()
    const grazing = Math.asin(1 / 20) / DEG
    expect(earthHit(far, [-Math.cos((grazing + 0.5) * DEG), Math.sin((grazing + 0.5) * DEG), 0])).toBeNull()
  })
})
