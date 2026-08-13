import { describe, it, expect, vi, afterEach } from 'vitest'
import { acquireEquirectTexture, __equirectCacheSize } from '../equirect-texture.js'

/**
 * The shared field cache, and the one thing it must NOT share.
 *
 * Two layers wanting the same image should get one texture — that is the whole point, and a second
 * copy of a panorama is the kind of waste nobody notices until video memory runs out on a school
 * tablet. But two layers wanting the same image with DIFFERENT FILTERING want two textures, and
 * handing them one is worse than loading it twice: the sky, sampled through mipmaps, collapses into
 * a few soft grey blocks, and it does so silently, in whichever layer happened to ask second.
 */

const glStub = () => {
  const calls = { created: 0, deleted: 0, mipmaps: 0, minFilters: [], uploads: [] }
  const gl = {
    TEXTURE_2D: 1, RGBA: 2, UNSIGNED_BYTE: 3, TEXTURE_WRAP_S: 4, TEXTURE_WRAP_T: 5,
    TEXTURE_MIN_FILTER: 6, TEXTURE_MAG_FILTER: 7, REPEAT: 8, CLAMP_TO_EDGE: 9,
    LINEAR: 10, LINEAR_MIPMAP_LINEAR: 11, UNPACK_FLIP_Y_WEBGL: 12, MAX_TEXTURE_SIZE: 13,
    getParameter: () => 4096,
    createTexture: () => { calls.created++; return { id: calls.created } },
    deleteTexture: () => { calls.deleted++ },
    // R8/RED for a one-byte mask under WebGL2, LUMINANCE under WebGL1.
    R8: 14, RED: 15, LUMINANCE: 16,
    texStorage2D: () => {},
    bindTexture: () => {}, pixelStorei: () => {},
    texImage2D: (_t, _l, internal, format) => { calls.uploads.push({ internal, format }) },
    texParameteri: (_t, name, value) => { if (name === 6) calls.minFilters.push(value) },
    generateMipmap: () => { calls.mipmaps++ },
  }
  return { gl, calls }
}

/**
 * A stand-in for Image that completes when told. jsdom's own never loads anything, and a texture
 * cache is entirely about what happens between the request and the pixels arriving.
 */
const stubImages = (width = 1024) => {
  const pending = []
  class FakeImage {
    set src (value) { this._src = value; pending.push(this) }
    get src () { return this._src }
  }
  Object.defineProperty(FakeImage.prototype, 'width', { get () { return this._width ?? width } })
  vi.stubGlobal('Image', FakeImage)
  return {
    settle (imageWidth) {
      const queued = pending.splice(0)
      queued.forEach((image) => { if (imageWidth) image._width = imageWidth; image.onload?.() })
    },
    fail () { pending.splice(0).forEach((image) => image.onerror?.()) },
    get count () { return pending.length },
  }
}

afterEach(() => vi.unstubAllGlobals())

describe('sharing one field between layers', () => {
  it('uploads once however many layers ask for it', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    const first = acquireEquirectTexture(gl, '/clouds.webp')
    const second = acquireEquirectTexture(gl, '/clouds.webp')
    expect(images.count).toBe(1)
    images.settle()
    expect(calls.created).toBe(1)
    expect(first.texture).toBe(second.texture)
  })

  it('frees it only when the last holder lets go', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    const first = acquireEquirectTexture(gl, '/clouds.webp')
    const second = acquireEquirectTexture(gl, '/clouds.webp')
    images.settle()
    first.release()
    expect(calls.deleted).toBe(0)
    expect(second.ready).toBe(true)
    second.release()
    expect(calls.deleted).toBe(1)
  })

  it('ignores a second release, so a layer removed twice cannot free someone else\'s texture', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    const handle = acquireEquirectTexture(gl, '/clouds.webp')
    images.settle()
    handle.release()
    handle.release()
    const other = acquireEquirectTexture(gl, '/clouds.webp')
    images.settle()
    other.release()
    expect(calls.deleted).toBe(2)
  })
})

describe('filtering is part of the identity', () => {
  it('does not hand a mipmapped ground texture to the sky', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    const ground = acquireEquirectTexture(gl, '/milkyway.webp')
    const sky = acquireEquirectTexture(gl, '/milkyway.webp', null, { mipmap: false })
    images.settle()
    expect(calls.created).toBe(2)
    expect(ground.texture).not.toBe(sky.texture)
    expect(__equirectCacheSize(gl)).toBe(2)
  })

  it('builds mipmaps for a field on the ground and none for one seen from inside', () => {
    const ground = glStub()
    const groundImages = stubImages()
    acquireEquirectTexture(ground.gl, '/clouds.webp')
    groundImages.settle()
    expect(ground.calls.mipmaps).toBe(1)
    expect(ground.calls.minFilters).toEqual([ground.gl.LINEAR_MIPMAP_LINEAR])

    const sky = glStub()
    const skyImages = stubImages()
    acquireEquirectTexture(sky.gl, '/milkyway.webp', null, { mipmap: false })
    skyImages.settle()
    expect(sky.calls.mipmaps).toBe(0)
    expect(sky.calls.minFilters).toEqual([sky.gl.LINEAR])
  })
})

describe('things that go wrong quietly', () => {
  it('uploads nothing when everyone has let go before the image arrived', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    acquireEquirectTexture(gl, '/clouds.webp').release()
    images.settle()
    // A texture created here would have no owner and no path to deletion.
    expect(calls.created).toBe(0)
  })

  it('refuses an image wider than the driver allows, rather than going silently black', () => {
    const { gl, calls } = glStub()   // this stub reports a 4096 maximum
    const images = stubImages()
    const handle = acquireEquirectTexture(gl, '/milkyway-16k.webp', null, { mipmap: false })
    images.settle(16384)
    expect(calls.created).toBe(0)
    expect(handle.ready).toBe(false)
    handle.release()
  })

  it('survives a load that never arrives', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    const handle = acquireEquirectTexture(gl, '/missing.webp', () => { throw new Error('must not be called') })
    images.fail()
    expect(handle.ready).toBe(false)
    expect(calls.created).toBe(0)
    expect(() => handle.release()).not.toThrow()
  })

  it('tells a late arrival the texture is already there', () => {
    const { gl } = glStub()
    const images = stubImages()
    acquireEquirectTexture(gl, '/clouds.webp')
    images.settle()
    const onReady = vi.fn()
    acquireEquirectTexture(gl, '/clouds.webp', onReady)
    expect(onReady).toHaveBeenCalledTimes(1)
  })
})

/**
 * WHICH FORMAT THE UPLOAD ACTUALLY USES, which the picture cannot tell you.
 *
 * The cloud atlas is a coverage mask: one byte of real data per texel, stored in four by an RGBA
 * upload. Asking for one channel cuts it to 8 MiB resident from 32.
 *
 * The acceptance test for that change was that the rendered picture must not move, and it did not —
 * byte-identical on all three cameras. But an RGBA upload produces exactly the same picture, so
 * "identical" is equally consistent with the change having silently not happened, and the saving
 * would be imaginary while the budget file claimed it. Only the format argument distinguishes them,
 * and nothing on screen ever will.
 */
describe('how many channels a field is uploaded with', () => {
  it('uploads a one-channel field as R8 where WebGL2 offers it', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    acquireEquirectTexture(gl, '/cloud-patches.webp', null, { channels: 1 })
    images.settle()
    expect(calls.uploads).toEqual([{ internal: gl.R8, format: gl.RED }])
  })

  it('falls back to LUMINANCE on WebGL1, which is also one byte and still reads as .r', () => {
    const { gl, calls } = glStub()
    delete gl.texStorage2D                    // the WebGL2 tell used by the module
    const images = stubImages()
    acquireEquirectTexture(gl, '/cloud-patches.webp', null, { channels: 1 })
    images.settle()
    expect(calls.uploads).toEqual([{ internal: gl.LUMINANCE, format: gl.LUMINANCE }])
  })

  it('leaves every other field on RGBA', () => {
    const { gl, calls } = glStub()
    const images = stubImages()
    acquireEquirectTexture(gl, '/clouds-field.webp')
    images.settle()
    expect(calls.uploads).toEqual([{ internal: gl.RGBA, format: gl.RGBA }])
  })

  it('does not hand an R8 texture to a caller that asked for colour', () => {
    // Channel count is part of the cache key. Without that, whichever layer asked first decides the
    // format for everyone, and the second one silently samples a texture with no green or blue.
    const { gl, calls } = glStub()
    const images = stubImages()
    acquireEquirectTexture(gl, '/same.webp', null, { channels: 1 })
    acquireEquirectTexture(gl, '/same.webp')
    images.settle()
    expect(calls.created).toBe(2)
    expect(calls.uploads).toEqual([
      { internal: gl.R8, format: gl.RED },
      { internal: gl.RGBA, format: gl.RGBA },
    ])
  })
})
