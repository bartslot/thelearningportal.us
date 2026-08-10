import { describe, it, expect, vi } from 'vitest'
import { readCapabilities, fitTexture, GL_PARAMS } from '../capabilities.js'

/** A fake GL context that answers only what it is told to, the way a real driver does. */
const fakeGl = ({ params = {}, extensions = [], webgl2 = true } = {}) => ({
  getParameter: vi.fn((pname) => params[pname]),
  getExtension: vi.fn((name) => (extensions.includes(name) ? { MAX_TEXTURE_MAX_ANISOTROPY_EXT: 0x84ff } : null)),
  getSupportedExtensions: vi.fn(() => extensions),
  ...(webgl2 ? { texStorage3D: () => {}, texImage3D: () => {} } : {}),
})

const fakeEnv = (over = {}) => ({
  navigator: { hardwareConcurrency: 8, deviceMemory: 8, ...over.navigator },
  window: {
    devicePixelRatio: 2,
    innerWidth: 1440,
    innerHeight: 900,
    matchMedia: () => ({ matches: false }),
    ...over.window,
  },
})

describe('readCapabilities', () => {
  it('reads the ceiling that matters and the version that gates the pyramid', () => {
    const gl = fakeGl({
      params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 4096, [GL_PARAMS.MAX_RENDERBUFFER_SIZE]: 4096, [GL_PARAMS.MAX_TEXTURE_IMAGE_UNITS]: 16 },
    })
    const profile = readCapabilities({ gl, ...fakeEnv() })
    expect(profile.maxTextureSize).toBe(4096)
    expect(profile.webglVersion).toBe(2)
    expect(profile.textureArrays).toBe(true)
  })

  it('calls a context without texStorage3D WebGL1, and says texture arrays are out', () => {
    const gl = fakeGl({ webgl2: false, params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 4096 } })
    const profile = readCapabilities({ gl, ...fakeEnv() })
    expect(profile.webglVersion).toBe(1)
    expect(profile.textureArrays).toBe(false)
  })

  it('reports no WebGL as version 0 instead of throwing', () => {
    const profile = readCapabilities({ gl: null, ...fakeEnv() })
    expect(profile.webglVersion).toBe(0)
    expect(profile.maxTextureSize).toBe(0)
  })

  it('survives a driver that throws out of getParameter', () => {
    const gl = { getParameter: () => { throw new Error('context lost') }, getExtension: () => null, getSupportedExtensions: () => [] }
    const profile = readCapabilities({ gl, ...fakeEnv() })
    expect(profile.maxTextureSize).toBe(0)
    expect(profile.errors.length).toBeGreaterThan(0)
  })

  it('leaves deviceMemory undefined when the browser does not implement it', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384 } })
    const profile = readCapabilities({ gl, ...fakeEnv({ navigator: { deviceMemory: undefined } }) })
    expect(profile.deviceMemoryGb).toBeUndefined()
    expect('deviceMemoryGb' in profile).toBe(true)
  })

  it('picks up a reduced-motion preference', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384 } })
    const matchMedia = (q) => ({ matches: q.includes('prefers-reduced-motion') })
    const profile = readCapabilities({ gl, ...fakeEnv({ window: { matchMedia } }) })
    expect(profile.reducedMotion).toBe(true)
  })

  it('picks up a save-data request from the connection', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384 } })
    const profile = readCapabilities({ gl, ...fakeEnv({ navigator: { connection: { saveData: true, effectiveType: '3g' } } }) })
    expect(profile.saveData).toBe(true)
    expect(profile.effectiveType).toBe('3g')
  })

  it('records the renderer string but marks it advisory, because selection must not sniff', () => {
    const gl = fakeGl({
      params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384, [GL_PARAMS.UNMASKED_RENDERER_WEBGL]: 'ANGLE (Google, SwiftShader)' },
      extensions: ['WEBGL_debug_renderer_info'],
    })
    const profile = readCapabilities({ gl, ...fakeEnv() })
    expect(profile.renderer).toContain('SwiftShader')
    expect(profile.rendererIsAdvisory).toBe(true)
  })

  it('reads once: a second call on the same context does not re-query the driver', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 8192 } })
    readCapabilities({ gl, ...fakeEnv() })
    const first = gl.getParameter.mock.calls.length
    readCapabilities({ gl, ...fakeEnv() })
    expect(gl.getParameter.mock.calls.length).toBe(first)
  })
})

describe('an environment where a measurement would mean nothing', () => {
  // The browser pane runs pages hidden: no rAF at all, timers clamped to about one a second, and
  // innerWidth of 0. None of it errors. A profile read there is not a device profile, and the
  // dangerous outcome is not a crash — it is a confident number nobody questions.
  const paneWindow = (over = {}) => ({
    devicePixelRatio: 2,
    innerWidth: 0,
    innerHeight: 0,
    matchMedia: () => ({ matches: false }),
    ...over,
  })

  it('marks a profile read from a zero-area viewport as unmeasurable', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384 } })
    const profile = readCapabilities({ gl, ...fakeEnv({ window: paneWindow() }) })
    expect(profile.measurable).toBe(false)
  })

  it('marks a profile read from a hidden document as unmeasurable', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384 } })
    const profile = readCapabilities({
      gl,
      ...fakeEnv({ window: paneWindow({ innerWidth: 1440, innerHeight: 900 }) }),
      document: { hidden: true },
    })
    expect(profile.hidden).toBe(true)
    expect(profile.measurable).toBe(false)
  })

  it('calls an ordinary visible page measurable', () => {
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 16384 } })
    const profile = readCapabilities({ gl, ...fakeEnv(), document: { hidden: false } })
    expect(profile.measurable).toBe(true)
  })

  it('still trusts the static values, because a driver limit is not a measurement', () => {
    // MAX_TEXTURE_SIZE is a fact about the driver whether or not anything is on screen. Throwing it
    // away because timing is unavailable would discard the one check that matters most.
    const gl = fakeGl({ params: { [GL_PARAMS.MAX_TEXTURE_SIZE]: 4096 } })
    const profile = readCapabilities({ gl, ...fakeEnv({ window: paneWindow() }) })
    expect(profile.maxTextureSize).toBe(4096)
    expect(profile.webglVersion).toBe(2)
  })
})

describe('fitTexture', () => {
  it('clamps to the device ceiling and says so', () => {
    expect(fitTexture(8192, { maxTextureSize: 4096 })).toEqual({ size: 4096, clamped: true, requested: 8192 })
  })

  it('leaves a size that already fits alone', () => {
    expect(fitTexture(2048, { maxTextureSize: 4096 })).toEqual({ size: 2048, clamped: false, requested: 2048 })
  })

  it('clamps down to a power of two rather than to an odd ceiling', () => {
    // A driver reporting a non-power-of-two ceiling is rare but legal, and half a texture is worse
    // than a smaller whole one.
    expect(fitTexture(8192, { maxTextureSize: 3000 }).size).toBe(2048)
  })

  it('returns zero for a device that cannot bind a texture at all', () => {
    expect(fitTexture(1024, { maxTextureSize: 0 })).toEqual({ size: 0, clamped: true, requested: 1024 })
  })
})
