import { describe, it, expect, vi } from 'vitest'
import { createSunLayer, SUN_LAYER_ID, visibleDiscFraction } from '../sun-disc.js'
import { sunDistance, sunAngularRadius, solarPosition, subsolarPoint } from '../sun.js'

/**
 * The sun, drawn.
 *
 * Two things are easy to get wrong here and neither raises an error:
 *
 *  - The SIZE. The disc must subtend half a degree. It is drawn far closer than 150 million km
 *    because the projection cannot reach that far, and the standing trap is to derive the size from
 *    the shortened draw distance — which inflates the sun to fill the sky, looks deliberate, and is
 *    wrong by three orders of magnitude.
 *  - The OCCLUSION. A sun behind the planet must go out. There is no depth test doing it: the glare
 *    is composited over everything on purpose, so the geometry has to be computed rather than
 *    inherited from the depth buffer.
 */

const utc = (y, m, d, h = 12) => new Date(Date.UTC(y, m - 1, d, h))

describe('sunDistance', () => {
  it('is nearest in early January and furthest in early July', () => {
    // Perihelion ~3 Jan, aphelion ~4 Jul. The seasons are the TILT, not this — the difference is
    // only 3%, which is why a model that got them backwards would still look plausible.
    expect(sunDistance(utc(2026, 1, 3))).toBeLessThan(sunDistance(utc(2026, 7, 4)))
  })

  it('stays inside the real range, 0.983 to 1.017 AU', () => {
    const AU = 1.495978707e11
    for (let month = 1; month <= 12; month++) {
      const r = sunDistance(utc(2026, month, 15)) / AU
      expect(r).toBeGreaterThan(0.982)
      expect(r).toBeLessThan(1.018)
    }
  })
})

describe('sunAngularRadius', () => {
  it('is a quarter of a degree — the disc is half a degree across, same as the moon', () => {
    const degrees = (date) => sunAngularRadius(date) * 2 * 180 / Math.PI
    // Measured range is 0.5243° at aphelion to 0.5422° at perihelion.
    expect(degrees(utc(2026, 1, 3))).toBeGreaterThan(0.538)
    expect(degrees(utc(2026, 1, 3))).toBeLessThan(0.546)
    expect(degrees(utc(2026, 7, 4))).toBeGreaterThan(0.521)
    expect(degrees(utc(2026, 7, 4))).toBeLessThan(0.528)
  })

  it('is bigger when the sun is nearer, which is the only reason it varies at all', () => {
    expect(sunAngularRadius(utc(2026, 1, 3))).toBeGreaterThan(sunAngularRadius(utc(2026, 7, 4)))
  })
})

describe('visibleDiscFraction', () => {
  const R = 0.3     // the blocker, in radians
  const r = 0.0047  // the sun

  it('is fully visible when the discs do not touch', () => {
    expect(visibleDiscFraction(R + r + 0.01, r, R)).toBe(1)
  })

  it('is fully hidden when the sun is well inside the blocker', () => {
    expect(visibleDiscFraction(R - r - 0.01, r, R)).toBe(0)
  })

  it('is half covered when the sun sits exactly on the blocker\'s edge', () => {
    // The blocker's limb is 60× the sun's width across the sun, so it cuts an almost straight line.
    expect(visibleDiscFraction(R, r, R)).toBeCloseTo(0.5, 2)
  })

  it('falls monotonically as the sun slides behind the blocker', () => {
    const samples = [R + r, R + r / 2, R, R - r / 2, R - r].map((d) => visibleDiscFraction(d, r, R))
    for (let i = 1; i < samples.length; i++) expect(samples[i]).toBeLessThanOrEqual(samples[i - 1])
  })

  it('leaves a ring of sun around a blocker smaller than it — an annular eclipse', () => {
    const fraction = visibleDiscFraction(0, r, r / 2)
    expect(fraction).toBeCloseTo(0.75, 6)    // 1 - (1/2)^2
  })
})

// ── The layer ────────────────────────────────────────────────────────────────────────────────────

const glStub = () => {
  const calls = { drawElements: 0 }
  const uniforms = {}
  const gl = {
    ARRAY_BUFFER: 1, ELEMENT_ARRAY_BUFFER: 2, STATIC_DRAW: 3, FLOAT: 4, TRIANGLES: 5,
    UNSIGNED_SHORT: 6, VERTEX_SHADER: 7, FRAGMENT_SHADER: 8, COMPILE_STATUS: 9, LINK_STATUS: 10,
    DEPTH_TEST: 11, LEQUAL: 12,
    createBuffer: () => ({}), bindBuffer: () => {}, bufferData: () => {},
    createShader: () => ({}), shaderSource: () => {}, compileShader: () => {}, deleteShader: () => {},
    createProgram: () => ({}), attachShader: () => {}, linkProgram: () => {},
    getShaderParameter: () => true, getProgramParameter: () => true,
    getAttribLocation: (_p, n) => n, getUniformLocation: (_p, n) => n,
    useProgram: () => {}, enableVertexAttribArray: () => {}, vertexAttribPointer: () => {},
    enable: () => {}, disable: () => {}, depthFunc: () => {}, depthMask: () => {},
    uniform1f: (n, v) => { uniforms[n] = v },
    uniform2f: (n, x, y) => { uniforms[n] = [x, y] },
    uniform3f: (n, x, y, z) => { uniforms[n] = [x, y, z] },
    uniform4f: () => {}, uniform1i: () => {},
    uniformMatrix4fv: () => {},
    deleteProgram: () => {}, deleteBuffer: () => {},
    drawElements: () => { calls.drawElements++ },
  }
  return { gl, calls, uniforms }
}

/** A camera `radii` earth-radii from the centre, over (0°N, `lng`°E). */
const mapStub = (lng = 0, radii = 8) => ({
  getCenter: () => ({ lng, lat: 0 }),
  getZoom: () => 1,
  getCanvas: () => ({ width: 1200, height: 800 }),
  transform: {
    getCameraLngLat: () => ({ lng, lat: 0 }),
    getCameraAltitude: () => (radii - 1) * 6371008.8,
  },
  triggerRepaint: vi.fn(),
})

const v5Args = (variantName = 'globe') => ({
  farZ: 1e7, nearZ: 1, fov: 0.6435,
  shaderData: {
    variantName,
    vertexShaderPrelude: 'vec4 projectTileFor3D(vec2 p, float e);',
    define: variantName === 'globe' ? '#define GLOBE' : '',
  },
  defaultProjectionData: {
    mainMatrix: new Float32Array(16),
    tileMercatorCoords: [0, 0, 1, 1],
    clippingPlane: [0, 0, 0, 0],
    projectionTransition: 0,
    fallbackMatrix: new Float32Array(16),
  },
})

/** Renders once and hands back the uniforms the shader was given. */
const renderOnce = (options, map = mapStub()) => {
  const { gl, calls, uniforms } = glStub()
  const layer = createSunLayer(options)
  layer.onAdd(map, gl)
  layer.render(gl, v5Args())
  return { uniforms, calls, layer }
}

/**
 * Noon UTC on the equinox puts the sun over (0°N, 0°E) give or take the equation of time, so a
 * camera over 0°E is looking straight at it and a camera over 180°E has the planet in the way.
 */
const EQUINOX_NOON = utc(2026, 3, 20, 12)

describe('the sun is not drawn where the sun is', () => {
  it('places the billboard within the projection\'s reach, not at 150 million kilometres', () => {
    // Past roughly six earth radii from the centre a point's clip-space w goes negative and it is
    // dropped before rasterisation. At one AU that is 23,000 radii out: the layer would draw
    // nothing at all, with no error to explain it.
    const { uniforms } = renderOnce({ date: EQUINOX_NOON })
    expect(uniforms.a_elevation_globe).toBeGreaterThan(0)
    expect(uniforms.a_elevation_globe).toBeLessThan(20 * 6371008.8)
  })

  it('stays well inside the far plane, which bites long before the negative-w wall does', () => {
    /**
     * The wall that actually killed the first version of this layer.
     *
     * MapLibre sizes farZ to just enclose the globe — measured, the antipodal ground point sits at
     * exactly z/w = 1.000, so the far plane is drawn through the back of the planet. The moon's
     * rule of `camera + sightLine × (radius + 1.2)` lands 1.74 radii out from an eight-radius
     * camera, an altitude of 4,684 km where the measured z/w is 1.00002. Two parts in a hundred
     * thousand over, and the sun disappears completely with the draw call still issued.
     *
     * So the quad is placed relative to the CAMERA, never relative to the planet — distance along
     * the sight line changes nothing on screen. This pins that: it always sits within a quarter of
     * the camera's own height of the camera, so w lands at a fixed fraction of the camera distance
     * and the layer never has to know where either clip plane is.
     */
    const EARTH_RADIUS_M = 6371008.8
    for (const radii of [2, 4, 8, 20]) {
      const { uniforms } = renderOnce({ date: EQUINOX_NOON }, mapStub(0, radii))
      const drawRadii = 1 + uniforms.a_elevation_globe / EARTH_RADIUS_M
      expect(Math.abs(drawRadii - radii)).toBeLessThanOrEqual(0.2501 * (radii - 1))
    }
  })

  it('keeps the disc at half a degree whatever the draw distance', () => {
    const FOV = 0.6435
    for (const month of [1, 4, 7, 10]) {
      const { uniforms } = renderOnce({ date: utc(2026, month, 20, 12) })
      // u_size.y is the GLOW quad's half-height; the disc is u_disc_fraction of it.
      const halfHeight = uniforms.u_size[1] * uniforms.u_disc_fraction
      const apparentDeg = 2 * Math.atan(halfHeight * Math.tan(FOV / 2)) * 180 / Math.PI
      expect(apparentDeg).toBeGreaterThan(0.52)
      expect(apparentDeg).toBeLessThan(0.55)
    }
  })

  it('draws the glow far wider than the disc — that is the whole effect', () => {
    const { uniforms } = renderOnce({ date: EQUINOX_NOON, haloScale: 14 })
    expect(uniforms.u_disc_fraction).toBeCloseTo(1 / 14, 6)
  })
})

describe('the earth in the way', () => {
  it('shows the sun in full when the camera is on the day side', () => {
    const { uniforms } = renderOnce({ date: EQUINOX_NOON }, mapStub(0, 8))
    expect(uniforms.u_visible).toBe(1)
  })

  it('puts it out when the camera is on the night side, with the planet between', () => {
    const { uniforms } = renderOnce({ date: EQUINOX_NOON }, mapStub(180, 8))
    expect(uniforms.u_visible).toBe(0)
  })

  /**
   * The equatorial camera longitude that puts the sun's centre `beyondLimbDeg` outside the earth's
   * silhouette. Derived rather than guessed: the subsolar meridian moves with the equation of time
   * and the limb's angular radius moves with altitude, so a hard-coded longitude tests nothing.
   */
  const cameraLngPastLimb = (date, radii, beyondLimbDeg) => {
    const { lng: subsolarLng, lat: declination } = subsolarPoint(date)
    const limbDeg = Math.asin(1 / radii) * 180 / Math.PI
    // separation between "toward the earth's centre" and "toward the sun", as seen from the camera
    const separation = limbDeg + beyondLimbDeg
    const cosOffset = Math.cos((180 - separation) * Math.PI / 180) / Math.cos(declination * Math.PI / 180)
    return subsolarLng + Math.acos(Math.min(1, Math.max(-1, cosOffset))) * 180 / Math.PI
  }

  it('catches it half-risen with its centre exactly on the limb', () => {
    const lng = cameraLngPastLimb(EQUINOX_NOON, 8, 0)
    const grazing = renderOnce({ date: EQUINOX_NOON }, mapStub(lng, 8)).uniforms.u_visible
    expect(grazing).toBeGreaterThan(0.35)
    expect(grazing).toBeLessThan(0.65)
  })

  it('rises as the camera keeps turning — the sun comes out from behind the planet', () => {
    // The disc's angular radius is 0.266°, so ±0.4° either side of the limb spans the whole
    // sunrise. It has to be a ramp: a centre-hidden test would snap, and on a turning globe that
    // reads as a flicker rather than as a sunrise.
    const fractions = [-0.4, -0.15, 0, 0.15, 0.4].map((beyond) => renderOnce(
      { date: EQUINOX_NOON }, mapStub(cameraLngPastLimb(EQUINOX_NOON, 8, beyond), 8),
    ).uniforms.u_visible)

    for (let i = 1; i < fractions.length; i++) {
      expect(fractions[i]).toBeGreaterThanOrEqual(fractions[i - 1])
    }
    expect(fractions[0]).toBe(0)
    expect(fractions[fractions.length - 1]).toBe(1)
    expect(fractions.filter((f) => f > 0 && f < 1).length).toBeGreaterThanOrEqual(2)
  })

  it('hides the sun for longer from close in, because the planet looks bigger', () => {
    // From two radii out the earth subtends 30°; from twenty, under 3°. Same sun, same date.
    const near = renderOnce({ date: EQUINOX_NOON }, mapStub(160, 2)).uniforms.u_visible
    const far = renderOnce({ date: EQUINOX_NOON }, mapStub(160, 20)).uniforms.u_visible
    expect(near).toBe(0)
    expect(far).toBe(1)
  })
})

describe('the sun agrees with everything else on the globe', () => {
  it('points the billboard along the shared solar vector, not one of its own', () => {
    const { uniforms } = renderOnce({ date: EQUINOX_NOON }, mapStub(0, 8))
    const forward = uniforms.u_forward
    // The camera sits over 0°E looking inward, so "toward the sun" is straight back out along +X.
    const { declination } = solarPosition(EQUINOX_NOON)
    expect(Math.asin(forward[1]) * 180 / Math.PI).toBeCloseTo(declination, 1)
    expect(Math.hypot(...forward)).toBeCloseTo(1, 6)
  })

  it('hands the shader an orthonormal billboard basis, so the glow is round', () => {
    const { uniforms } = renderOnce({ date: EQUINOX_NOON })
    const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2]
    for (const axis of [uniforms.u_forward, uniforms.u_right, uniforms.u_up]) {
      expect(Math.hypot(...axis)).toBeCloseTo(1, 5)
    }
    expect(dot(uniforms.u_forward, uniforms.u_right)).toBeCloseTo(0, 5)
    expect(dot(uniforms.u_forward, uniforms.u_up)).toBeCloseTo(0, 5)
    expect(dot(uniforms.u_right, uniforms.u_up)).toBeCloseTo(0, 5)
  })

  it('gives the shader the camera in planet space, for the per-pixel occlusion test', () => {
    const { uniforms } = renderOnce({ date: EQUINOX_NOON }, mapStub(0, 8))
    expect(Math.hypot(...uniforms.u_camera)).toBeCloseTo(8, 2)
  })
})

describe('live controls', () => {
  it('moves with the date a lesson is set at', () => {
    const morning = renderOnce({ date: utc(2026, 3, 20, 6) }).uniforms.u_forward
    const evening = renderOnce({ date: utc(2026, 3, 20, 18) }).uniforms.u_forward
    expect(morning[0]).not.toBeCloseTo(evening[0], 2)
  })

  it('repaints when its options change, or a slider does nothing until the map is nudged', () => {
    const { gl } = glStub()
    const map = mapStub()
    const layer = createSunLayer({ date: EQUINOX_NOON })
    layer.onAdd(map, gl)
    layer.setOptions({ brightness: 0.5 })
    expect(map.triggerRepaint).toHaveBeenCalled()
    expect(layer.getOptions().brightness).toBe(0.5)
  })

  it('keeps a stable id', () => {
    expect(createSunLayer().id).toBe(SUN_LAYER_ID)
    expect(SUN_LAYER_ID).toBe('tm-sun')
  })
})
