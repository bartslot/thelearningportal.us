import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { acquireEquirectTexture, __equirectCacheSize } from '../equirect-texture.js'

/**
 * Two layers now want the same cloud field: the deck draws it, and the ground shades itself with
 * its shadow. Loading it twice is not merely wasteful — it is a correctness problem. Two Image
 * loads finish at different frames, so for a moment the shadows are on the ground of a sky that is
 * not there yet, and two GL textures mean two chances to disagree about wrap and filtering.
 *
 * So the field is acquired, not loaded, and released rather than deleted.
 */

const glStub = () => {
  const calls = { createTexture: 0, deleteTexture: 0 }
  return {
    TEXTURE_2D: 20, RGBA: 22, UNSIGNED_BYTE: 23, TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25,
    TEXTURE_MIN_FILTER: 26, TEXTURE_MAG_FILTER: 27, REPEAT: 28, CLAMP_TO_EDGE: 29,
    LINEAR_MIPMAP_LINEAR: 30, LINEAR: 31, UNPACK_FLIP_Y_WEBGL: 32,
    createTexture: () => { calls.createTexture++; return { id: calls.createTexture } },
    deleteTexture: () => { calls.deleteTexture++ },
    bindTexture: () => {}, texImage2D: () => {}, texParameteri: () => {},
    generateMipmap: () => {}, pixelStorei: () => {},
    calls,
  }
}

let images = []

beforeEach(() => {
  images = []
  vi.stubGlobal('Image', class {
    constructor() { images.push(this); this.onload = null; this.onerror = null; this._src = null }
    set src(value) { this._src = value }
    get src() { return this._src }
    finish() { this.onload && this.onload() }
    fail() { this.onerror && this.onerror() }
  })
})

afterEach(() => { vi.unstubAllGlobals() })

describe('sharing one equirectangular texture between layers', () => {
  it('fetches the image once however many layers ask for it', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    const b = acquireEquirectTexture(gl, '/clouds.webp')
    expect(images).toHaveLength(1)
    images[0].finish()
    expect(gl.calls.createTexture).toBe(1)
    expect(a.texture).toBe(b.texture)
    a.release(); b.release()
  })

  it('tells every holder when it arrives, including ones that asked first', () => {
    const gl = glStub()
    const first = vi.fn()
    const second = vi.fn()
    const a = acquireEquirectTexture(gl, '/clouds.webp', first)
    const b = acquireEquirectTexture(gl, '/clouds.webp', second)
    expect(first).not.toHaveBeenCalled()
    images[0].finish()
    expect(first).toHaveBeenCalledTimes(1)
    expect(second).toHaveBeenCalledTimes(1)
    a.release(); b.release()
  })

  it('tells a latecomer immediately rather than leaving it waiting for a load that already ran', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    images[0].finish()
    const late = vi.fn()
    const b = acquireEquirectTexture(gl, '/clouds.webp', late)
    expect(late).toHaveBeenCalledTimes(1)
    expect(b.ready).toBe(true)
    a.release(); b.release()
  })

  it('keeps the texture while any layer still holds it', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    const b = acquireEquirectTexture(gl, '/clouds.webp')
    images[0].finish()
    a.release()
    expect(gl.calls.deleteTexture).toBe(0)
    expect(b.ready).toBe(true)
    b.release()
    expect(gl.calls.deleteTexture).toBe(1)
  })

  it('ignores a second release from the same holder, so one careless layer cannot free another\'s texture', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    const b = acquireEquirectTexture(gl, '/clouds.webp')
    images[0].finish()
    a.release()
    a.release()
    expect(gl.calls.deleteTexture).toBe(0)
    b.release()
    expect(gl.calls.deleteTexture).toBe(1)
  })

  it('forgets the entry once the last holder lets go, so a style reload starts clean', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    images[0].finish()
    a.release()
    expect(__equirectCacheSize(gl)).toBe(0)
    const b = acquireEquirectTexture(gl, '/clouds.webp')
    expect(images).toHaveLength(2)
    b.release()
  })

  it('keeps separate contexts separate — a texture belongs to the context that made it', () => {
    const one = glStub()
    const two = glStub()
    const a = acquireEquirectTexture(one, '/clouds.webp')
    const b = acquireEquirectTexture(two, '/clouds.webp')
    expect(images).toHaveLength(2)
    images[0].finish()
    images[1].finish()
    expect(a.texture).not.toBe(b.texture)
    a.release(); b.release()
  })

  it('keeps different fields apart', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    const b = acquireEquirectTexture(gl, '/wind.webp')
    expect(images).toHaveLength(2)
    a.release(); b.release()
  })

  it('survives a failed load with nothing but a missing texture', () => {
    // Every one of these fields is decoration. A 404 costs the effect, never the map.
    const gl = glStub()
    const onReady = vi.fn()
    const a = acquireEquirectTexture(gl, '/missing.webp', onReady)
    expect(() => images[0].fail()).not.toThrow()
    expect(a.ready).toBe(false)
    expect(onReady).not.toHaveBeenCalled()
    expect(() => a.release()).not.toThrow()
  })

  it('releases a holder that never saw its image arrive', () => {
    const gl = glStub()
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    a.release()
    expect(__equirectCacheSize(gl)).toBe(0)
    // The load lands after everyone has gone. It must not resurrect a texture nobody holds.
    expect(() => images[0].finish()).not.toThrow()
    expect(gl.calls.createTexture).toBe(0)
  })

  it('wraps in longitude and clamps at the poles', () => {
    // REPEAT on T folds the Arctic onto the Antarctic at the seam — a thin bright band across
    // both poles that reads as an artefact of the projection rather than a bug.
    const gl = glStub()
    const params = []
    gl.texParameteri = (_t, name, value) => params.push([name, value])
    const a = acquireEquirectTexture(gl, '/clouds.webp')
    images[0].finish()
    expect(params).toContainEqual([gl.TEXTURE_WRAP_S, gl.REPEAT])
    expect(params).toContainEqual([gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE])
    a.release()
  })
})
