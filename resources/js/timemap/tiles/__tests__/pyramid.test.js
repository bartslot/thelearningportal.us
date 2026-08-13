import { describe, it, expect, vi } from 'vitest'
import { cameraFromMap, createTilePyramid } from '../index.js'
import { memoryStore } from '../cache.js'

/**
 * The façade, which is all three overlay layers will actually touch. What is pinned here is the
 * contract in docs/tiled-lod.md: onAdd / update / bind / onRemove, and the promise that none of it
 * throws into a render loop.
 */

const gl2Stub = () => {
  const calls = { deleteTexture: 0, texSubImage3D: 0, texImage2D: 0 }
  const gl = {
    TEXTURE_2D: 3553, TEXTURE_2D_ARRAY: 35866, RGBA8: 32856, RGBA: 6408, UNSIGNED_BYTE: 5121,
    TEXTURE_MIN_FILTER: 10241, TEXTURE_MAG_FILTER: 10240, TEXTURE_WRAP_S: 10242, TEXTURE_WRAP_T: 10243,
    NEAREST: 9728, LINEAR: 9729, CLAMP_TO_EDGE: 33071, MAX_ARRAY_TEXTURE_LAYERS: 35071, TEXTURE0: 33984,
    createTexture: () => ({}),
    deleteTexture: () => { calls.deleteTexture++ },
    bindTexture: () => {}, activeTexture: () => {},
    texStorage3D: () => {},
    texSubImage3D: () => { calls.texSubImage3D++ },
    texImage2D: () => { calls.texImage2D++ },
    texParameteri: () => {}, pixelStorei: () => {},
    getParameter: () => 256,
    uniform1i: () => {}, uniform1f: () => {}, uniform4f: () => {},
  }
  return { gl, calls }
}

const SOURCE = { id: 'test', url: 'https://example.test/{z}/{x}/{y}.png', minzoom: 0, maxzoom: 8, tileSize: 4 }

const camera = (over = {}) => ({
  center: { lng: 4.9, lat: 52.4 },
  zoom: 4,
  canvasWidth: 800,
  canvasHeight: 600,
  projection: 'globe',
  ...over,
})

const pyramidWith = (over = {}) => {
  const { gl, calls } = gl2Stub()
  const fetcher = over.fetch ?? vi.fn(async () => new ArrayBuffer(8))
  const pyramid = createTilePyramid({
    source: SOURCE,
    layers: 32,
    maxTiles: 16,
    store: memoryStore(),
    fetch: fetcher,
    decode: async () => ({ data: new Uint8ClampedArray(4 * 4 * 4), bytes: 64 }),
    ...over,
  })
  return { pyramid, gl, calls, fetcher }
}

describe('the lifecycle a custom layer expects', () => {
  it('streams the tiles the view needs, once each', async () => {
    const { pyramid, gl, fetcher } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    await pyramid.idle()

    expect(fetcher.mock.calls.length).toBeGreaterThan(0)
    const urls = fetcher.mock.calls.map(([url]) => url)
    expect(new Set(urls).size).toBe(urls.length)
    urls.forEach((url) => expect(url).toMatch(/^https:\/\/example\.test\/\d+\/\d+\/\d+\.png$/))
  })

  it('puts what arrives into the atlas', async () => {
    const { pyramid, gl, calls } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    await pyramid.idle()
    pyramid.update(camera())
    expect(calls.texSubImage3D).toBeGreaterThan(0)
    expect(pyramid.stats().slotsUsed).toBeGreaterThan(0)
  })

  it('asks for nothing at all the second time the camera has not moved', async () => {
    // Not even a cache lookup: once a tile is in the atlas the frame is answered on the GPU, which
    // is the cheapest a repeat frame can be.
    const { pyramid, gl, fetcher } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    await pyramid.idle()
    const before = pyramid.stats().requests

    fetcher.mockClear()
    pyramid.update(camera())
    await pyramid.idle()
    expect(fetcher).not.toHaveBeenCalled()
    expect(pyramid.stats().requests).toBe(before)
  })

  it('restores a tile the GPU dropped from memory, without going back to the network', async () => {
    /**
     * The atlas is much smaller than the memory cache, so panning away and back evicts slots long
     * before it evicts decoded tiles. That gap is where the cache earns its keep — and it is only
     * visible if the pyramid checks the cache before it fetches.
     */
    const { pyramid, gl, fetcher } = pyramidWith({ layers: 4, maxTiles: 8 })
    pyramid.onAdd(gl)
    pyramid.update(camera({ center: { lng: 4.9, lat: 52.4 } }))
    await pyramid.idle()
    pyramid.update(camera({ center: { lng: -74, lat: 40.7 } }))   // across the Atlantic
    await pyramid.idle()

    fetcher.mockClear()
    pyramid.update(camera({ center: { lng: 4.9, lat: 52.4 } }))   // and back
    await pyramid.idle()
    expect(fetcher).not.toHaveBeenCalled()
    expect(pyramid.stats().memoryHits).toBeGreaterThan(0)
  })

  it('rebuilds the page tables every update, because tiles keep arriving', async () => {
    const { pyramid, gl, calls } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    const after = calls.texImage2D
    pyramid.update(camera())
    expect(calls.texImage2D).toBe(after + 2)   // fine table and ancestor table
  })

  it('frees every GL object it made', async () => {
    const { pyramid, gl, calls } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    await pyramid.idle()
    pyramid.onRemove()
    // The texture array plus both page tables.
    expect(calls.deleteTexture).toBe(3)
    expect(pyramid.stats().residentTiles).toBe(0)
  })

  it('does nothing rather than throwing when used before it was added', () => {
    const { pyramid } = pyramidWith()
    expect(() => pyramid.update(camera())).not.toThrow()
    expect(() => pyramid.bind({}, 0)).not.toThrow()
    expect(() => pyramid.onRemove()).not.toThrow()
  })

  it('stands down on a context without texture arrays instead of drawing nothing', async () => {
    const { gl } = gl2Stub()
    delete gl.texStorage3D
    const { pyramid, fetcher } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    await pyramid.idle()

    expect(pyramid.supported).toBe(false)
    // No point spending a school's bandwidth on tiles that cannot be sampled.
    expect(fetcher).not.toHaveBeenCalled()
  })
})

describe('what it reports', () => {
  it('says how far short of the detail it wanted it fell', async () => {
    const { pyramid, gl } = pyramidWith()
    pyramid.onAdd(gl)

    pyramid.update(camera({ zoom: 2 }))
    expect(pyramid.stats().shortfall).toBe(0)

    // Past the source's last level there is nothing more to fetch, and the layer needs to know.
    pyramid.update(camera({ zoom: 14 }))
    expect(pyramid.stats().shortfall).toBeGreaterThan(0)
    expect(pyramid.stats().starved).toBe(true)
  })

  it('reports selection, cache and atlas together, so one call answers the questions', async () => {
    const { pyramid, gl } = pyramidWith()
    pyramid.onAdd(gl)
    pyramid.update(camera())
    await pyramid.idle()
    const stats = pyramid.stats()
    for (const field of [
      'tileCount', 'maxLevel', 'worstPixelsPerTexel', 'starved', 'shortfall',
      'requests', 'networkFetches', 'hitRate', 'bytes', 'residentTiles', 'slots', 'slotsUsed',
    ]) {
      expect(stats).toHaveProperty(field)
    }
  })
})

describe('the camera adapter', () => {
  const mapStub = () => ({
    getCenter: () => ({ lng: 4.9, lat: 52.4 }),
    getZoom: () => 6,
    getCanvas: () => ({ width: 1200, height: 800 }),
    transform: { getCameraAltitude: () => 4.2e5 },
  })

  it('reads the projection from the render arguments, not from a guess', () => {
    // The variant flips mid-flight as the map crosses the globe/mercator threshold, and it is the
    // render arguments that know — asking the map is one frame behind.
    const globe = cameraFromMap(mapStub(), { fov: 0.6435, shaderData: { variantName: 'globe' } })
    const flat = cameraFromMap(mapStub(), { fov: 0.6435, shaderData: { variantName: 'mercator' } })
    expect(globe.projection).toBe('globe')
    expect(flat.projection).toBe('mercator')
  })

  it('carries the canvas size and the real camera altitude through', () => {
    const view = cameraFromMap(mapStub(), { fov: 0.6435, shaderData: { variantName: 'globe' } })
    expect(view.canvasWidth).toBe(1200)
    expect(view.canvasHeight).toBe(800)
    expect(view.zoom).toBe(6)
    expect(view.altitudeMetres).toBe(4.2e5)
  })

  it('copes with a map that will not give an altitude', () => {
    const map = mapStub()
    map.transform = {}
    const view = cameraFromMap(map, { shaderData: { variantName: 'globe' } })
    expect(view.altitudeMetres).toBeNull()   // selection derives one from the zoom instead
  })
})
