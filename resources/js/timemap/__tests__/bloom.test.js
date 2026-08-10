import { describe, it, expect, vi } from 'vitest'
import { createBloomLayer, BLOOM_LAYER_ID, bloomTargetSizes } from '../bloom.js'

/**
 * The bloom pass is not a globe overlay, so it is not in `globe-layers.test.js`.
 *
 * It shares none of that contract: it draws a full-screen quad in clip space, so it never touches
 * the projection prelude, compiles one program set for every projection, and has nothing to read
 * out of the render arguments. What it has instead is a framebuffer lifecycle, and that is what
 * costs people days — leaking a texture per style reload, drawing into MapLibre's buffer and
 * leaving the binding pointed somewhere else, or rebuilding every target on every frame.
 */

const glStub = ({ webgl2 = true } = {}) => {
  const calls = {
    deleteProgram: 0, deleteBuffer: 0, deleteTexture: 0, deleteFramebuffer: 0,
    drawArrays: 0, texImage2D: 0, bindFramebuffer: [], viewport: [], blendFunc: [],
  }
  const state = { framebuffer: 'maplibre-fbo', viewport: [0, 0, 1280, 720] }
  const gl = {
    ARRAY_BUFFER: 1, STATIC_DRAW: 3, FLOAT: 4, TRIANGLE_STRIP: 5, TRIANGLES: 51,
    VERTEX_SHADER: 7, FRAGMENT_SHADER: 8, COMPILE_STATUS: 9, LINK_STATUS: 10,
    DEPTH_TEST: 11, BLEND: 12, CULL_FACE: 13, STENCIL_TEST: 14, SCISSOR_TEST: 15,
    TEXTURE_2D: 20, TEXTURE0: 21, TEXTURE1: 22, RGBA: 30, UNSIGNED_BYTE: 31,
    TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25, TEXTURE_MIN_FILTER: 26, TEXTURE_MAG_FILTER: 27,
    CLAMP_TO_EDGE: 29, LINEAR: 33, ONE: 34, ONE_MINUS_SRC_ALPHA: 35, ZERO: 36,
    FRAMEBUFFER: 40, COLOR_ATTACHMENT0: 41, FRAMEBUFFER_COMPLETE: 42,
    FRAMEBUFFER_BINDING: 50, VIEWPORT: 51, CURRENT_PROGRAM: 52, ARRAY_BUFFER_BINDING: 53,
    ACTIVE_TEXTURE: 54, BLEND_SRC_RGB: 55, BLEND_DST_RGB: 56,

    createBuffer: () => ({}), bindBuffer: () => {}, bufferData: () => {},
    createShader: () => ({}), shaderSource: () => {}, compileShader: () => {}, deleteShader: () => {},
    createProgram: () => ({}), attachShader: () => {}, linkProgram: () => {},
    getShaderParameter: () => true, getProgramParameter: () => true,
    getAttribLocation: (_p, n) => n, getUniformLocation: (_p, n) => n,
    useProgram: () => {}, enableVertexAttribArray: () => {}, vertexAttribPointer: () => {},
    enable: () => {}, disable: () => {}, depthMask: () => {},
    uniform1f: () => {}, uniform1i: () => {}, uniform2f: () => {}, uniform3f: () => {},
    blendFunc: (s, d) => calls.blendFunc.push([s, d]),
    createTexture: () => ({}), bindTexture: () => {}, activeTexture: () => {},
    texParameteri: () => {},
    texImage2D: () => { calls.texImage2D++ },
    copyTexImage2D: () => {},
    createFramebuffer: () => ({}),
    bindFramebuffer: (_t, fb) => { calls.bindFramebuffer.push(fb); state.framebuffer = fb },
    framebufferTexture2D: () => {},
    checkFramebufferStatus: () => 42,
    viewport: (...v) => { calls.viewport.push(v); state.viewport = v },
    drawArrays: () => { calls.drawArrays++ },
    deleteProgram: () => { calls.deleteProgram++ },
    deleteBuffer: () => { calls.deleteBuffer++ },
    deleteTexture: () => { calls.deleteTexture++ },
    deleteFramebuffer: () => { calls.deleteFramebuffer++ },
    getParameter: (name) => {
      if (name === 50) return 'maplibre-fbo'
      if (name === 51) return [0, 0, 1280, 720]
      if (name === 54) return 21
      if (name === 55) return 34
      if (name === 56) return 35
      return null
    },
  }
  if (webgl2) gl.texStorage2D = () => {}
  return { gl, calls, state }
}

const mapStub = (width = 1280, height = 720) => ({
  getCanvas: () => ({ width, height }),
  triggerRepaint: vi.fn(),
})

const v5Args = () => ({ farZ: 1e7, nearZ: 1, fov: 0.6435, shaderData: { variantName: 'globe' } })

describe('bloomTargetSizes', () => {
  it('halves for the first level and eighths for the second', () => {
    expect(bloomTargetSizes(1280, 720)).toEqual([{ width: 640, height: 360 }, { width: 160, height: 90 }])
  })

  it('never produces a zero-sized target, however small the canvas gets', () => {
    // A pane collapsing to a sliver during a resize would otherwise ask for a 0×0 texture, which
    // is an INVALID_VALUE on every driver and takes the whole map's GL context down with it.
    for (const [w, h] of [[1, 1], [3, 2], [7, 900], [0, 0]]) {
      for (const size of bloomTargetSizes(w, h)) {
        expect(size.width).toBeGreaterThanOrEqual(1)
        expect(size.height).toBeGreaterThanOrEqual(1)
      }
    }
  })
})

describe('the bloom layer', () => {
  it('keeps a stable id, prefixed like every other layer here', () => {
    expect(createBloomLayer().id).toBe(BLOOM_LAYER_ID)
    expect(BLOOM_LAYER_ID).toBe('tm-bloom')
  })

  it('draws its passes: bright, two blurs per level, and the composite', () => {
    const { gl, calls } = glStub()
    const layer = createBloomLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    // 1 bright + (H + V) × 2 levels + 1 composite
    expect(calls.drawArrays).toBe(6)
  })

  it('puts MapLibre\'s framebuffer and viewport back exactly as it found them', () => {
    /**
     * The whole pass runs by binding other framebuffers. Leave the binding on the last blur target
     * and every layer MapLibre draws after this one goes into a 160×90 texture nobody looks at —
     * the map simply stops updating, with no error and a perfectly good last frame still on screen.
     */
    const { gl, calls, state } = glStub()
    const layer = createBloomLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(state.framebuffer).toBe('maplibre-fbo')
    expect(calls.viewport.at(-1)).toEqual([0, 0, 1280, 720])
  })

  it('puts the blend function back, so the next layer is not additive', () => {
    const { gl, calls } = glStub()
    const layer = createBloomLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    // Additive for its own composite, then MapLibre's premultiplied default restored.
    expect(calls.blendFunc).toContainEqual([34, 34])
    expect(calls.blendFunc.at(-1)).toEqual([34, 35])
  })

  it('allocates its targets once and reuses them frame after frame', () => {
    const { gl, calls } = glStub()
    const layer = createBloomLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    const afterFirst = calls.texImage2D
    layer.render(gl, v5Args())
    layer.render(gl, v5Args())
    expect(calls.texImage2D).toBe(afterFirst)
    expect(afterFirst).toBeGreaterThan(0)
  })

  it('rebuilds them when the canvas changes size, or the bloom is stretched', () => {
    const { gl, calls } = glStub()
    const map = mapStub()
    let size = { width: 1280, height: 720 }
    map.getCanvas = () => size
    const layer = createBloomLayer()
    layer.onAdd(map, gl)
    layer.render(gl, v5Args())
    const afterFirst = calls.texImage2D
    size = { width: 1920, height: 1080 }
    layer.render(gl, v5Args())
    expect(calls.texImage2D).toBeGreaterThan(afterFirst)
    expect(calls.deleteTexture).toBeGreaterThan(0)
  })

  it('costs nothing at zero strength', () => {
    const { gl, calls } = glStub()
    const layer = createBloomLayer({ strength: 0 })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.drawArrays).toBe(0)
    expect(calls.texImage2D).toBe(0)   // and does not even allocate
  })

  it('frees every program, buffer, texture and framebuffer on removal', () => {
    const { gl, calls } = glStub()
    const layer = createBloomLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    layer.onRemove()
    expect(calls.deleteProgram).toBe(3)          // bright, blur, composite
    expect(calls.deleteBuffer).toBe(1)
    expect(calls.deleteTexture).toBe(5)          // scene + two targets per level
    expect(calls.deleteFramebuffer).toBe(4)
  })

  it('survives being removed before it was ever added', () => {
    expect(() => createBloomLayer().onRemove()).not.toThrow()
  })

  it('repaints when its options change', () => {
    const { gl } = glStub()
    const map = mapStub()
    const layer = createBloomLayer()
    layer.onAdd(map, gl)
    layer.setOptions({ strength: 0.4 })
    expect(map.triggerRepaint).toHaveBeenCalled()
    expect(layer.getOptions().strength).toBe(0.4)
  })
})
