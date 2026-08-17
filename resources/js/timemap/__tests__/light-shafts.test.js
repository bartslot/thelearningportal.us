import { describe, it, expect, vi } from 'vitest'
import { createLightShaftLayer, LIGHT_SHAFT_LAYER_ID, shaftFalloff, SHAFT_EDGE_MARGIN } from '../light-shafts.js'

/**
 * Screen-space light shafts.
 *
 * Two failure modes carry the weight here and neither raises anything:
 *
 *  - The sun leaves the frame and the shafts do not stop, they ROTATE. Every pixel keeps stepping
 *    toward a point beyond the edge, so the screen fills with parallel streaks from nowhere. It
 *    reads as a broken blur rather than as a sun that has set.
 *  - The pass runs when there is nothing to smear, paying for a full-screen grab and two passes to
 *    add exactly zero.
 */

const glStub = () => {
  const calls = {
    deleteProgram: 0, deleteBuffer: 0, deleteTexture: 0, deleteFramebuffer: 0,
    drawArrays: 0, texImage2D: 0, copyTexImage2D: 0, blendFunc: [], bindFramebuffer: [], viewport: [],
  }
  const uniforms = {}
  const gl = {
    ARRAY_BUFFER: 1, STATIC_DRAW: 3, FLOAT: 4, TRIANGLE_STRIP: 5,
    VERTEX_SHADER: 7, FRAGMENT_SHADER: 8, COMPILE_STATUS: 9, LINK_STATUS: 10,
    DEPTH_TEST: 11, BLEND: 12, TEXTURE_2D: 20, RGBA: 30, UNSIGNED_BYTE: 31,
    TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25, TEXTURE_MIN_FILTER: 26, TEXTURE_MAG_FILTER: 27,
    CLAMP_TO_EDGE: 29, LINEAR: 33, ONE: 34, ONE_MINUS_SRC_ALPHA: 35,
    FRAMEBUFFER: 40, COLOR_ATTACHMENT0: 41, FRAMEBUFFER_BINDING: 50, VIEWPORT: 51, TEXTURE0: 100,

    createBuffer: () => ({}), bindBuffer: () => {}, bufferData: () => {},
    createShader: () => ({}), shaderSource: () => {}, compileShader: () => {}, deleteShader: () => {},
    createProgram: () => ({}), attachShader: () => {}, linkProgram: () => {},
    getShaderParameter: () => true, getProgramParameter: () => true,
    getAttribLocation: (_p, n) => n, getUniformLocation: (_p, n) => n,
    useProgram: () => {}, enableVertexAttribArray: () => {}, vertexAttribPointer: () => {},
    enable: () => {}, disable: () => {}, depthMask: () => {},
    uniform1f: (n, v) => { uniforms[n] = v },
    uniform2f: (n, x, y) => { uniforms[n] = [x, y] },
    uniform3f: (n, x, y, z) => { uniforms[n] = [x, y, z] },
    uniform1i: () => {},
    blendFunc: (s, d) => calls.blendFunc.push([s, d]),
    createTexture: () => ({}), bindTexture: () => {}, activeTexture: () => {}, texParameteri: () => {},
    texImage2D: () => { calls.texImage2D++ },
    copyTexImage2D: () => { calls.copyTexImage2D++ },
    createFramebuffer: () => ({}),
    bindFramebuffer: (_t, fb) => calls.bindFramebuffer.push(fb),
    framebufferTexture2D: () => {},
    viewport: (...v) => calls.viewport.push(v),
    drawArrays: () => { calls.drawArrays++ },
    deleteProgram: () => { calls.deleteProgram++ },
    deleteBuffer: () => { calls.deleteBuffer++ },
    deleteTexture: () => { calls.deleteTexture++ },
    deleteFramebuffer: () => { calls.deleteFramebuffer++ },
    getParameter: (n) => (n === 50 ? 'maplibre-fbo' : n === 51 ? [0, 0, 1280, 720] : null),
  }
  return { gl, calls, uniforms }
}

const mapStub = () => ({ getCanvas: () => ({ width: 1280, height: 720 }), triggerRepaint: vi.fn() })
const onScreenSun = { u: 0.42, v: 0.55, onScreen: true, visible: 1 }

describe('shaftFalloff', () => {
  it('is full strength for a sun in the middle of the frame', () => {
    expect(shaftFalloff({ u: 0.5, v: 0.5, onScreen: true, visible: 1 })).toBe(1)
  })

  it('is nothing at all once the sun is off screen', () => {
    // Not "very little". Anything above zero keeps every pixel stepping toward a point beyond the
    // edge, which fills the frame with parallel streaks coming from nowhere.
    expect(shaftFalloff({ u: 1.4, v: 0.5, onScreen: false, visible: 1 })).toBe(0)
  })

  it('fades out as the sun approaches the edge, rather than snapping', () => {
    const inside = shaftFalloff({ u: 0.5, v: 0.5, onScreen: true, visible: 1 })
    const nearEdge = shaftFalloff({ u: 1.05, v: 0.5, onScreen: true, visible: 1 })
    // Exactly at the margin the effect is OFF, not merely faint: any residue keeps every pixel
    // stepping toward a point beyond the edge.
    const wellOut = shaftFalloff({ u: 1.5 + SHAFT_EDGE_MARGIN, v: 0.5, onScreen: true, visible: 1 })
    expect(nearEdge).toBeLessThan(inside)
    expect(wellOut).toBeLessThan(nearEdge)
    expect(wellOut).toBe(0)
  })

  it('dims with the sun the planet is covering, so a sunset takes the beams with it', () => {
    const half = shaftFalloff({ u: 0.5, v: 0.5, onScreen: true, visible: 0.5 })
    expect(half).toBeCloseTo(0.5, 6)
    expect(shaftFalloff({ u: 0.5, v: 0.5, onScreen: true, visible: 0 })).toBe(0)
  })
})

describe('the light-shaft layer', () => {
  const build = (options = {}) => {
    const { gl, calls, uniforms } = glStub()
    const layer = createLightShaftLayer({ source: () => onScreenSun, ...options })
    layer.onAdd(mapStub(), gl)
    return { layer, gl, calls, uniforms }
  }

  it('keeps a stable id', () => {
    expect(createLightShaftLayer().id).toBe(LIGHT_SHAFT_LAYER_ID)
    expect(LIGHT_SHAFT_LAYER_ID).toBe('tm-light-shafts')
  })

  it('draws its three passes: mask, radial blur, composite', () => {
    const { layer, gl, calls } = build()
    layer.render()
    expect(calls.drawArrays).toBe(3)
    expect(calls.copyTexImage2D).toBe(1)
  })

  it('costs NOTHING when the sun is off screen — not even the frame grab', () => {
    const { gl, calls } = glStub()
    const layer = createLightShaftLayer({ source: () => ({ u: 2, v: 2, onScreen: false, visible: 1 }) })
    layer.onAdd(mapStub(), gl)
    layer.render()
    expect(calls.drawArrays).toBe(0)
    expect(calls.copyTexImage2D).toBe(0)
    expect(calls.texImage2D).toBe(0)
  })

  it('costs nothing at zero strength', () => {
    const { layer, calls } = build({ strength: 0 })
    layer.render()
    expect(calls.drawArrays).toBe(0)
    expect(calls.texImage2D).toBe(0)
  })

  it('smears from where the sun actually is, not from the middle', () => {
    const { layer, uniforms } = build()
    layer.render()
    expect(uniforms.u_sun).toEqual([onScreenSun.u, onScreenSun.v])
  })

  it('corrects for aspect, so the rays are radial and not elliptical', () => {
    const { layer, uniforms } = build()
    layer.render()
    expect(uniforms.u_aspect).toBeCloseTo(1280 / 720, 6)
  })

  it('folds the falloff into the composite rather than leaving it to the caller', () => {
    const { gl, uniforms } = glStub()
    const layer = createLightShaftLayer({
      strength: 0.8,
      source: () => ({ u: 0.5, v: 0.5, onScreen: true, visible: 0.25 }),
    })
    layer.onAdd(mapStub(), gl)
    layer.render()
    expect(uniforms.u_strength).toBeCloseTo(0.8 * 0.25, 6)
  })

  it('puts MapLibre\'s framebuffer, viewport and blend back', () => {
    const { layer, calls } = build()
    layer.render()
    expect(calls.bindFramebuffer.at(-1)).toBe('maplibre-fbo')
    expect(calls.viewport.at(-1)).toEqual([0, 0, 1280, 720])
    expect(calls.blendFunc).toContainEqual([34, 34])       // additive for its own composite
    expect(calls.blendFunc.at(-1)).toEqual([34, 35])       // MapLibre's premultiplied default
  })

  it('allocates once and reuses, then rebuilds on a resize', () => {
    const { gl, calls } = glStub()
    const map = mapStub()
    let size = { width: 1280, height: 720 }
    map.getCanvas = () => size
    const layer = createLightShaftLayer({ source: () => onScreenSun })
    layer.onAdd(map, gl)
    layer.render()
    const afterFirst = calls.texImage2D
    layer.render()
    expect(calls.texImage2D).toBe(afterFirst)
    size = { width: 1920, height: 1080 }
    layer.render()
    expect(calls.texImage2D).toBeGreaterThan(afterFirst)
  })

  it('frees everything on removal', () => {
    const { layer, calls } = build()
    layer.render()
    layer.onRemove()
    expect(calls.deleteProgram).toBe(3)        // mask, shaft, composite
    expect(calls.deleteBuffer).toBe(1)
    expect(calls.deleteTexture).toBe(3)        // scene grab, mask, shafts
    expect(calls.deleteFramebuffer).toBe(2)    // the grab has none: it is only copied into
  })

  it('survives being removed before it was ever added', () => {
    expect(() => createLightShaftLayer().onRemove()).not.toThrow()
  })

  it('works with no source at all rather than throwing on a missing dependency', () => {
    const { gl, calls } = glStub()
    const layer = createLightShaftLayer()
    layer.onAdd(mapStub(), gl)
    expect(() => layer.render()).not.toThrow()
    expect(calls.drawArrays).toBe(0)
  })
})
