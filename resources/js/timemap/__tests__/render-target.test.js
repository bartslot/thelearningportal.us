import { describe, it, expect } from 'vitest'
import {
  createRenderTarget, destroyRenderTarget, createMipChain, mipChainSizes,
  createScreenQuad, captureGlState, restoreGlState, beginPass, drawScreenQuad, bindTextureUnit,
} from '../render-target.js'

/**
 * The shared offscreen-target machinery.
 *
 * Almost nothing here is about rendering. It is about the two ways a screen-space pass silently
 * breaks the map around it: asking for a zero-sized texture, which is an INVALID_VALUE that takes
 * the whole GL context down, and forgetting to hand MapLibre's own framebuffer back, which sends
 * every layer drawn afterwards into a texture nobody looks at.
 */

const glStub = () => {
  const calls = {
    texImage2D: [], deleteTexture: 0, deleteFramebuffer: 0, drawArrays: 0,
    bindFramebuffer: [], viewport: [], blendFunc: [], activeTexture: [], uniform1i: [],
  }
  const gl = {
    TEXTURE_2D: 20, RGBA: 30, UNSIGNED_BYTE: 31, ARRAY_BUFFER: 1, STATIC_DRAW: 3, FLOAT: 4,
    TRIANGLE_STRIP: 5, TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25, TEXTURE_MIN_FILTER: 26,
    TEXTURE_MAG_FILTER: 27, CLAMP_TO_EDGE: 29, LINEAR: 33, ONE: 34, ONE_MINUS_SRC_ALPHA: 35,
    FRAMEBUFFER: 40, COLOR_ATTACHMENT0: 41, BLEND: 12,
    FRAMEBUFFER_BINDING: 50, VIEWPORT: 51, TEXTURE0: 100,

    createTexture: () => ({ id: 'tex' }), bindTexture: () => {}, texParameteri: () => {},
    texImage2D: (_t, _l, _if, w, h) => { calls.texImage2D.push([w, h]) },
    createFramebuffer: () => ({ id: 'fbo' }),
    bindFramebuffer: (_t, fb) => { calls.bindFramebuffer.push(fb) },
    framebufferTexture2D: () => {},
    createBuffer: () => ({ id: 'buf' }), bindBuffer: () => {}, bufferData: () => {},
    enableVertexAttribArray: () => {}, vertexAttribPointer: () => {},
    drawArrays: () => { calls.drawArrays++ },
    useProgram: () => {}, enable: () => {}, depthMask: () => {},
    activeTexture: (u) => { calls.activeTexture.push(u) },
    uniform1i: (l, v) => { calls.uniform1i.push([l, v]) },
    viewport: (...v) => { calls.viewport.push(v) },
    blendFunc: (s, d) => { calls.blendFunc.push([s, d]) },
    deleteTexture: () => { calls.deleteTexture++ },
    deleteFramebuffer: () => { calls.deleteFramebuffer++ },
    getParameter: (name) => (name === 50 ? 'maplibre-fbo' : name === 51 ? [0, 0, 1280, 720] : null),
  }
  return { gl, calls }
}

describe('createRenderTarget', () => {
  it('allocates a texture at the size asked for', () => {
    const { gl, calls } = glStub()
    const target = createRenderTarget(gl, 640, 360)
    expect(calls.texImage2D).toEqual([[640, 360]])
    expect(target.width).toBe(640)
    expect(target.framebuffer).toBeTruthy()
  })

  it('never allocates a zero-sized texture, however small the canvas gets', () => {
    // 0×0 is an INVALID_VALUE on every driver, and it does not fail politely: it takes the map's
    // whole GL context with it, so a momentary resize leaves a black canvas and no explanation.
    const { gl, calls } = glStub()
    createRenderTarget(gl, 0, 0)
    createRenderTarget(gl, 0.4, 900)
    expect(calls.texImage2D).toEqual([[1, 1], [1, 900]])
  })

  it('can skip the framebuffer, for a texture that is only ever copied into', () => {
    const { gl } = glStub()
    expect(createRenderTarget(gl, 128, 128, false).framebuffer).toBeNull()
  })
})

describe('destroyRenderTarget', () => {
  it('frees both objects', () => {
    const { gl, calls } = glStub()
    destroyRenderTarget(gl, createRenderTarget(gl, 64, 64))
    expect(calls.deleteTexture).toBe(1)
    expect(calls.deleteFramebuffer).toBe(1)
  })

  it('shrugs at nothing, so teardown paths do not need guards of their own', () => {
    const { gl } = glStub()
    expect(() => destroyRenderTarget(gl, null)).not.toThrow()
  })
})

describe('mipChainSizes', () => {
  it('halves at every level, starting at half', () => {
    expect(mipChainSizes(1280, 720, 4)).toEqual([
      { width: 640, height: 360 },
      { width: 320, height: 180 },
      { width: 160, height: 90 },
      { width: 80, height: 45 },
    ])
  })

  it('bottoms out at one pixel rather than at zero', () => {
    const sizes = mipChainSizes(8, 4, 6)
    expect(sizes.at(-1)).toEqual({ width: 1, height: 1 })
    sizes.forEach(({ width, height }) => {
      expect(width).toBeGreaterThanOrEqual(1)
      expect(height).toBeGreaterThanOrEqual(1)
    })
  })

  it('builds one target per level', () => {
    const { gl, calls } = glStub()
    expect(createMipChain(gl, 1024, 512, 5)).toHaveLength(5)
    expect(calls.texImage2D).toHaveLength(5)
  })
})

describe('restoring the context', () => {
  it('gives MapLibre back the framebuffer it was drawing into, not the default', () => {
    /**
     * Assuming `null` is the trap. With terrain on, MapLibre renders into a framebuffer of its own,
     * and unbinding to the default would put the rest of the frame on the screen instead of into
     * the terrain buffer — a map that looks right until someone turns on relief.
     */
    const { gl, calls } = glStub()
    restoreGlState(gl, captureGlState(gl))
    expect(calls.bindFramebuffer.at(-1)).toBe('maplibre-fbo')
    expect(calls.viewport.at(-1)).toEqual([0, 0, 1280, 720])
  })

  it('puts the premultiplied blend back, so the next layer is not additive', () => {
    const { gl, calls } = glStub()
    restoreGlState(gl, captureGlState(gl))
    expect(calls.blendFunc.at(-1)).toEqual([34, 35])
  })

  it('leaves texture unit 0 active, which is what every other layer assumes', () => {
    const { gl, calls } = glStub()
    restoreGlState(gl, captureGlState(gl))
    expect(calls.activeTexture.at(-1)).toBe(100)
  })
})

describe('pass helpers', () => {
  it('points a pass at its target and matches the viewport to it', () => {
    const { gl, calls } = glStub()
    const target = createRenderTarget(gl, 320, 180)
    beginPass(gl, target, { id: 'program' })
    expect(calls.bindFramebuffer.at(-1)).toBe(target.framebuffer)
    expect(calls.viewport.at(-1)).toEqual([0, 0, 320, 180])
  })

  it('draws four vertices as one strip', () => {
    const { gl, calls } = glStub()
    drawScreenQuad(gl, createScreenQuad(gl), 'a_corner')
    expect(calls.drawArrays).toBe(1)
  })

  it('binds a texture to the unit its sampler is told about', () => {
    const { gl, calls } = glStub()
    bindTextureUnit(gl, 2, { id: 'tex' }, 'u_source')
    expect(calls.activeTexture.at(-1)).toBe(102)
    expect(calls.uniform1i.at(-1)).toEqual(['u_source', 2])
  })
})
