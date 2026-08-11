import { describe, it, expect, vi } from 'vitest'
import { createCloudLayer, deckDetailFor, CLOUD_LAYER_ID } from '../clouds.js'
import { CLOUD_ALTITUDE_M } from '../cloud-field.js'
import { buildSphereMesh, latFromMercatorY, planetSpacePosition } from '../planet-mesh.js'

/**
 * The cloud deck.
 *
 * It had never drawn a pixel: written against MapLibre v4's `render(gl, matrix)`, while v5 hands an
 * options object — so `uniformMatrix4fv` was being fed `{farZ, nearZ, shaderData, …}`. These cover
 * the two halves of the repair: a mesh laid out in the space the projection prelude actually
 * consumes, and a render path that reads v5's arguments (and refuses to draw with v4's).
 */

const glStub = () => {
  const calls = { deleteProgram: 0, deleteBuffer: 0, deleteTexture: 0, drawElements: 0, uniformMatrix4fv: [] }
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
    enable: () => {}, depthFunc: () => {}, depthMask: () => {},
    uniform1f: () => {}, uniform3f: () => {}, uniform4f: () => {}, uniform1i: () => {},
    activeTexture: () => {}, bindTexture: () => {}, createTexture: () => ({}), deleteTexture: () => { calls.deleteTexture++ },
    texImage2D: () => {}, texParameteri: () => {}, generateMipmap: () => {}, pixelStorei: () => {},
    TEXTURE_2D: 20, TEXTURE0: 21, RGBA: 22, UNSIGNED_BYTE: 23, TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25,
    TEXTURE_MIN_FILTER: 26, TEXTURE_MAG_FILTER: 27, REPEAT: 28, CLAMP_TO_EDGE: 29,
    LINEAR_MIPMAP_LINEAR: 30, LINEAR: 31, UNPACK_FLIP_Y_WEBGL: 32,
    uniformMatrix4fv: (_l, _t, v) => calls.uniformMatrix4fv.push(v),
    deleteProgram: () => { calls.deleteProgram++ },
    deleteBuffer: () => { calls.deleteBuffer++ },
    drawElements: () => { calls.drawElements++ },
  }
  return { gl, calls }
}

const mapStub = () => ({
  getCenter: () => ({ lng: 0, lat: 40 }),
  getZoom: () => 2,
  transform: { getCameraLngLat: () => ({ lng: 0, lat: 40 }), getCameraAltitude: () => 5e6 },
  triggerRepaint: vi.fn(),
})

// What MapLibre v5 actually passes, trimmed to the fields the layer reads.
const v5Args = (variantName = 'globe') => ({
  farZ: 1e7, nearZ: 1, fov: 0.6,
  modelViewProjectionMatrix: new Float32Array(16),
  projectionMatrix: new Float32Array(16),
  shaderData: { variantName, vertexShaderPrelude: 'vec4 projectTileFor3D(vec2 p, float e);', define: variantName === 'globe' ? '#define GLOBE' : '' },
  defaultProjectionData: {
    mainMatrix: new Float32Array(16),
    tileMercatorCoords: [0, 0, 1, 1],
    clippingPlane: [0, 0, 0, 0],
    projectionTransition: 0,
    fallbackMatrix: new Float32Array(16),
  },
})

describe('latFromMercatorY', () => {
  it('spans the mercator square from pole edge to pole edge', () => {
    expect(latFromMercatorY(0.5)).toBeCloseTo(0, 6)
    expect(latFromMercatorY(0)).toBeCloseTo(85.0511, 3)
    expect(latFromMercatorY(1)).toBeCloseTo(-85.0511, 3)
  })
})

describe('buildSphereMesh', () => {
  it('reaches past the mercator square, because the poles are outside it', () => {
    // The regression this exists for: a mesh spanning exactly 0..1 stops at ±85.05° and leaves a
    // disc of bare imagery at each pole. Under the night shell that was a circle of lit Antarctica
    // sitting in the middle of the polar night — invisible from any view that was not over a pole,
    // which is why it shipped.
    const mesh = buildSphereMesh(4, 3)
    expect(mesh.positions.length).toBe(mesh.vertexCount * 2)
    expect(Math.min(...mesh.positions)).toBeLessThan(0)
    expect(Math.max(...mesh.positions)).toBeGreaterThan(1)

    // Longitude still spans exactly one turn: only the y range grew.
    const xs = mesh.positions.filter((_, i) => i % 2 === 0)
    expect(Math.min(...xs)).toBe(0)
    expect(Math.max(...xs)).toBe(1)
  })

  it('covers the sphere to within a metre or two of each pole', () => {
    const mesh = buildSphereMesh(4, 3)
    const ys = mesh.positions.filter((_, i) => i % 2 === 1)
    // How much latitude is left uncovered at each end, in kilometres along the surface.
    const gapKm = (lat) => (90 - Math.abs(lat)) * 111.32
    expect(gapKm(latFromMercatorY(Math.min(...ys)))).toBeLessThan(0.2)
    expect(gapKm(latFromMercatorY(Math.max(...ys)))).toBeLessThan(0.2)
  })

  it('never reaches the pole exactly, where the mercator y is infinite', () => {
    const ys = buildSphereMesh(4, 3).positions.filter((_, i) => i % 2 === 1)
    ys.forEach((y) => expect(Number.isFinite(y)).toBe(true))
    expect(Math.abs(latFromMercatorY(Math.min(...ys)))).toBeLessThan(90)
  })

  it('carries a unit-sphere direction per vertex, so noise stays even from equator to Iceland', () => {
    const mesh = buildSphereMesh(8, 6)
    expect(mesh.spheres.length).toBe(mesh.vertexCount * 3)
    for (let i = 0; i < mesh.vertexCount; i++) {
      const [x, y, z] = [mesh.spheres[i * 3], mesh.spheres[i * 3 + 1], mesh.spheres[i * 3 + 2]]
      expect(Math.hypot(x, y, z)).toBeCloseTo(1, 6)
    }
  })

  it('closes the seam: the ±180° edges land on the same point of the sphere', () => {
    const mesh = buildSphereMesh(8, 6)
    const stride = 8 + 1
    for (let row = 0; row <= 6; row++) {
      const west = (row * stride) * 3
      const east = (row * stride + 8) * 3
      for (let k = 0; k < 3; k++) expect(mesh.spheres[west + k]).toBeCloseTo(mesh.spheres[east + k], 6)
    }
  })

  it('indexes every quad as two triangles, within Uint16 range', () => {
    const mesh = buildSphereMesh(4, 3)
    // Every row gap, including the cap rows added beyond the mercator square.
    expect(mesh.indices.length).toBe(4 * (mesh.rowCount - 1) * 6)
    expect(Math.max(...mesh.indices)).toBeLessThan(mesh.vertexCount)
    // The index buffer is Uint16: an out-of-range vertex count wraps silently rather than throwing.
    expect(buildSphereMesh().vertexCount).toBeLessThan(65536)
    expect(Math.max(...buildSphereMesh().indices)).toBeLessThan(65536)
  })
})

describe('planetSpacePosition', () => {
  it('puts (0°N, 0°E) on the +X axis at unit radius', () => {
    const [x, y, z] = planetSpacePosition(0, 0, 0)
    expect(x).toBeCloseTo(1, 9)
    expect(y).toBeCloseTo(0, 9)
    expect(z).toBeCloseTo(0, 9)
  })

  it('puts the north pole on +Y, matching the mesh convention', () => {
    const [x, y, z] = planetSpacePosition(123, 90, 0)
    expect(y).toBeCloseTo(1, 9)
    expect(Math.hypot(x, z)).toBeCloseTo(0, 9)
  })

  it('measures altitude in earth radii, so the slab and the camera share one ruler', () => {
    const surface = planetSpacePosition(0, 0, 0)
    const up = planetSpacePosition(0, 0, 6371008.8)          // exactly one radius up
    expect(Math.hypot(...up) / Math.hypot(...surface)).toBeCloseTo(2, 6)
  })

  it('never lets a negative altitude put the camera inside the planet', () => {
    expect(Math.hypot(...planetSpacePosition(10, 10, -500))).toBeCloseTo(1, 9)
  })
})

describe('deckDetailFor', () => {
  /**
   * The deck was built for the globe and quietly fell apart on approach: the real field is
   * 2048x1024, so by z7 it is magnified sixteen times and by z11 two hundred and fifty six, and
   * long before that it has stopped being weather and become a grey wash over sharp ground.
   * Nothing errors — it just looks worse the closer you get, which is why it went unnoticed.
   */
  // Altitude defaults to orbit, so the frequency and amount cases below read as before.
  const at = (zoom, lat = 0, altitudeM = 1e7) => deckDetailFor(zoom, lat, altitudeM)

  it('leaves the globe view exactly as it was tuned', () => {
    // The floor matters: this is the look that was measured and approved at globe zoom, and a
    // detail pass for close range must not quietly restyle it.
    expect(at(1.4).frequency).toBe(9)
    expect(at(2).frequency).toBe(9)
    expect(at(1.4).fade).toBe(1)
    expect(at(1.4).amount).toBeCloseTo(0.14, 2)
  })

  it('raises the detail frequency as the camera comes down', () => {
    const zooms = [3, 5, 7, 9, 11]
    const freqs = zooms.map((z) => at(z).frequency)
    for (let i = 1; i < freqs.length; i++) expect(freqs[i]).toBeGreaterThanOrEqual(freqs[i - 1])
    // By mid zoom it must have actually moved, not just crept off the floor.
    expect(at(7).frequency).toBeGreaterThan(20)
  })

  it('caps the frequency so the noise never aliases into static', () => {
    for (const z of [14, 18, 22]) expect(at(z).frequency).toBeLessThanOrEqual(2600)
  })

  it('hands the noise more of the job as the field runs out of pixels', () => {
    expect(at(2).amount).toBeLessThan(at(7).amount)
    expect(at(7).amount).toBeLessThanOrEqual(at(11).amount)
    // Never so much that the real weather stops deciding where cloud is.
    expect(at(14).amount).toBeLessThan(0.6)
  })

  it('retires the deck BEFORE the camera reaches it, not while passing through', () => {
    /**
     * The bug this replaced: the fade was specified in ZOOM while the thing to avoid is the camera
     * reaching a shell at a fixed ALTITUDE. Measured, the camera crossed the 90 km deck at z10.01
     * with the deck still 50% opaque — so you flew through a half-solid shell that filled the view
     * and clipped against the near plane. Reported as "the clouds are glitchy when flying through
     * them", which is exactly what it was.
     *
     * Zoom and altitude are only loosely coupled — the mercator scale carries cos(lat) — so the
     * crossing moved a whole level with latitude: z10.03 at the equator, z9.03 at 60°, where the
     * deck was 92% opaque. No zoom pair can be right at every latitude.
     */
    const deck = CLOUD_ALTITUDE_M

    // Gone before arrival, with room to spare. This is the property that matters.
    expect(at(6, 0, deck * 1.2).fade).toBe(0)
    expect(at(6, 0, deck).fade).toBe(0)
    expect(at(6, 0, deck * 0.5).fade).toBe(0)
    expect(at(6, 0, 0).fade).toBe(0)

    // Untouched where the deck is the whole point.
    expect(at(6, 0, deck * 40).fade).toBe(1)   // globe view
    expect(at(6, 0, deck * 4).fade).toBe(1)    // voyages are read at this range: leave it alone

    // And a ramp in between rather than a switch.
    const middle = at(6, 0, deck * 2).fade
    expect(middle).toBeGreaterThan(0)
    expect(middle).toBeLessThan(1)
  })

  it('hides the deck rather than guess when the altitude is missing', () => {
    // Of the two ways to be wrong here, "no clouds" is loud and safe and "clouds always drawn" is
    // silent and is the bug this replaced. A NaN would otherwise reach a uniform.
    expect(deckDetailFor(6, 0, undefined).fade).toBe(0)
    expect(deckDetailFor(6, 0, NaN).fade).toBe(0)
  })

  it('fades on altitude alone, so it is right at every latitude', () => {
    // The old fade was a zoom pair, and the same zoom is a different altitude at Oslo than at the
    // equator. Anything driven by altitude cannot drift that way.
    const deck = CLOUD_ALTITUDE_M
    for (const altitude of [deck * 0.5, deck * 2, deck * 10]) {
      expect(at(6, 60, altitude).fade).toBe(at(6, 0, altitude).fade)
      expect(at(11, 60, altitude).fade).toBe(at(6, 0, altitude).fade)
    }
  })

  it('accounts for latitude, where a zoom level covers less ground', () => {
    // Mercator scale shrinks with cos(lat), so the same zoom is closer to the ground at Oslo.
    expect(at(7, 60).frequency).toBeGreaterThan(at(7, 0).frequency)
  })
})

describe('the render contract', () => {
  it('draws from v5 arguments', () => {
    const { gl, calls } = glStub()
    const layer = createCloudLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(1)
    // Every matrix uniform it sets is a real Float32Array — the v4 bug in one assertion.
    expect(calls.uniformMatrix4fv.length).toBeGreaterThan(0)
    calls.uniformMatrix4fv.forEach((v) => expect(v).toBeInstanceOf(Float32Array))
  })

  it('refuses to draw when handed v4\'s bare matrix instead of drawing garbage', () => {
    const { gl, calls } = glStub()
    const layer = createCloudLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, new Float32Array(16))
    expect(calls.drawElements).toBe(0)
  })

  it('compiles one program per projection variant and reuses it', () => {
    const { gl } = glStub()
    const created = vi.spyOn(gl, 'createProgram')
    const layer = createCloudLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args('globe'))
    layer.render(gl, v5Args('globe'))
    expect(created).toHaveBeenCalledTimes(1)
    layer.render(gl, v5Args('mercator'))   // crossing the globe→mercator threshold
    expect(created).toHaveBeenCalledTimes(2)
  })

  it('frees its program and buffers on removal', () => {
    const { gl, calls } = glStub()
    const layer = createCloudLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    layer.onRemove()
    expect(calls.deleteProgram).toBe(1)
    expect(calls.deleteBuffer).toBe(3)
  })

  it('only asks for another frame while it is animating', () => {
    const { gl } = glStub()
    const map = mapStub()
    const still = createCloudLayer({ animate: false })
    still.onAdd(map, gl)
    still.render(gl, v5Args())
    expect(map.triggerRepaint).not.toHaveBeenCalled()

    const drifting = createCloudLayer({ animate: true })
    drifting.onAdd(map, gl)
    drifting.render(gl, v5Args())
    expect(map.triggerRepaint).toHaveBeenCalled()
  })

  it('draws nothing at zero density, so hiding the deck costs no frames', () => {
    const { gl, calls } = glStub()
    const map = mapStub()
    const layer = createCloudLayer({ opacity: 0 })
    layer.onAdd(map, gl)
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(0)
    expect(map.triggerRepaint).not.toHaveBeenCalled()

    layer.setOptions({ opacity: 0.5 })
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(1)
  })

  it('keeps the id the voyage layers anchor themselves below', () => {
    expect(createCloudLayer().id).toBe(CLOUD_LAYER_ID)
    expect(CLOUD_LAYER_ID).toBe('tm-clouds')
  })
})
