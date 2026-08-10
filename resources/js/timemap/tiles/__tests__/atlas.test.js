import { describe, it, expect, vi } from 'vitest'
import { createTileAtlas } from '../atlas.js'
import { keyOf } from '../scheme.js'

/**
 * The atlas is where tiles stop being data and become something a shader can reach. Two things it
 * must never do, both of which are silent:
 *
 *  - Recycle a slot that the frame being drawn is still sampling. The picture does not error, it
 *    just briefly puts an ocean on a mountain.
 *  - Interpolate the page table. Slot indices are not quantities; a LINEAR filter between slot 7
 *    and slot 9 samples slot 8, which is some other piece of the planet.
 */

const gl2Stub = () => {
  const calls = {
    texStorage3D: [], texSubImage3D: [], texImage2D: [], texParameteri: [],
    deleteTexture: 0, createTexture: 0, bindTexture: [], uniform1i: {}, uniform4f: {},
  }
  const gl = {
    TEXTURE_2D: 3553, TEXTURE_2D_ARRAY: 35866, RGBA8: 32856, RGBA: 6408, UNSIGNED_BYTE: 5121,
    TEXTURE_MIN_FILTER: 10241, TEXTURE_MAG_FILTER: 10240, TEXTURE_WRAP_S: 10242, TEXTURE_WRAP_T: 10243,
    NEAREST: 9728, LINEAR: 9729, CLAMP_TO_EDGE: 33071,
    MAX_ARRAY_TEXTURE_LAYERS: 35071,
    TEXTURE0: 33984,
    createTexture: () => { calls.createTexture++; return { id: calls.createTexture } },
    deleteTexture: () => { calls.deleteTexture++ },
    bindTexture: (target, texture) => calls.bindTexture.push([target, texture]),
    activeTexture: () => {},
    texStorage3D: (...args) => calls.texStorage3D.push(args),
    texSubImage3D: (...args) => calls.texSubImage3D.push(args),
    texImage2D: (...args) => calls.texImage2D.push(args),
    texParameteri: (...args) => calls.texParameteri.push(args),
    pixelStorei: () => {},
    getParameter: (name) => (name === 35071 ? 256 : 0),
    uniform1i: (name, value) => { calls.uniform1i[name] = value },
    uniform1f: (name, value) => { calls.uniform1i[name] = value },
    uniform4f: (name, ...values) => { calls.uniform4f[name] = values },
  }
  return { gl, calls }
}

const pixels = () => new Uint8ClampedArray(4 * 4 * 4)

const atlasWith = (over = {}) => {
  const { gl, calls } = over.gl ? over : gl2Stub()
  let clock = 0
  const atlas = createTileAtlas(gl, {
    tileSize: 4,
    layers: 4,
    pageResolution: 8,
    fadeMs: 100,
    now: () => clock,
    ...over.options,
  })
  return { atlas, gl, calls, tick: (ms) => { clock += ms } }
}

const tile = (z, x, y) => ({ z, x, y, key: keyOf({ z, x, y }) })

describe('allocating the array', () => {
  it('makes one texture array with immutable storage, not one texture per tile', () => {
    const { calls } = atlasWith()
    expect(calls.texStorage3D).toHaveLength(1)
    const [target, , format, width, height, layers] = calls.texStorage3D[0]
    expect(target).toBe(35866)   // TEXTURE_2D_ARRAY
    expect(format).toBe(32856)   // RGBA8
    expect(width).toBe(4)
    expect(height).toBe(4)
    expect(layers).toBe(4)
  })

  it('will not ask for more layers than the driver allows', () => {
    const { gl } = gl2Stub()
    gl.getParameter = () => 16
    const atlas = createTileAtlas(gl, { tileSize: 4, layers: 4096 })
    expect(atlas.stats().slots).toBe(16)
  })

  it('samples the page tables with NEAREST, because a slot index is not a quantity', () => {
    const { calls } = atlasWith()
    const nearest = calls.texParameteri.filter(([, , value]) => value === 9728)
    // Two page tables, min and mag filter on each.
    expect(nearest).toHaveLength(4)
  })

  it('says so plainly on a context without texture arrays, rather than drawing nothing', () => {
    const { gl } = gl2Stub()
    delete gl.texStorage3D
    const atlas = createTileAtlas(gl, { tileSize: 4, layers: 4 })
    expect(atlas.supported).toBe(false)
    expect(() => atlas.dispose()).not.toThrow()
  })
})

describe('slots', () => {
  it('uploads a tile into a layer and remembers where it went', () => {
    const { atlas, calls } = atlasWith()
    const slot = atlas.upload(tile(3, 1, 1), pixels())
    expect(slot).toBe(0)
    expect(atlas.slotOf('3/1/1')).toBe(0)
    // texSubImage3D's zOffset argument is the layer.
    expect(calls.texSubImage3D[0][3]).toBe(0)
  })

  it('gives each tile its own layer', () => {
    const { atlas } = atlasWith()
    atlas.upload(tile(3, 1, 1), pixels())
    atlas.upload(tile(3, 2, 1), pixels())
    expect(atlas.slotOf('3/2/1')).toBe(1)
  })

  it('does not upload the same tile twice', () => {
    const { atlas, calls } = atlasWith()
    atlas.upload(tile(3, 1, 1), pixels())
    atlas.upload(tile(3, 1, 1), pixels())
    expect(calls.texSubImage3D).toHaveLength(1)
  })

  it('recycles the least recently used slot when it runs out', () => {
    const { atlas } = atlasWith()
    for (let x = 0; x < 4; x++) atlas.upload(tile(3, x, 0), pixels())
    atlas.upload(tile(3, 9, 0), pixels())
    expect(atlas.slotOf('3/0/0')).toBe(-1)
    expect(atlas.slotOf('3/9/0')).toBe(0)
  })

  it('will not recycle a slot the current frame is drawing with', () => {
    const { atlas } = atlasWith()
    for (let x = 0; x < 4; x++) atlas.upload(tile(3, x, 0), pixels())
    atlas.retain(['3/0/0', '3/1/0', '3/2/0'])
    atlas.upload(tile(3, 9, 0), pixels())
    expect(atlas.slotOf('3/0/0')).toBe(0)
    expect(atlas.slotOf('3/3/0')).toBe(-1)   // the only one free to go
  })

  it('refuses rather than evicting a retained tile when every slot is pinned', () => {
    const { atlas } = atlasWith()
    for (let x = 0; x < 4; x++) atlas.upload(tile(3, x, 0), pixels())
    atlas.retain(['3/0/0', '3/1/0', '3/2/0', '3/3/0'])
    expect(atlas.upload(tile(3, 9, 0), pixels())).toBe(-1)
    expect(atlas.slotOf('3/0/0')).toBe(0)
  })
})

describe('the page table', () => {
  const pageOf = (calls, table) => {
    // Two texImage2D calls per build, fine table first.
    const recent = calls.texImage2D.slice(-2)
    return recent[table === 'fine' ? 0 : 1].at(-1)
  }
  const at = (atlas, data, i, j) => {
    const [width] = atlas.pageSize()
    const p = (j * width + i) * 4
    return { slot: data[p], level: data[p + 1], fade: data[p + 2] / 255 }
  }

  it('sizes the grid to one texel per finest tile, so pages never straddle two tiles', () => {
    /**
     * A page texel covering parts of two tiles can only name one of them, while the shader works
     * out the position WITHIN a tile from the coordinate itself — so near every boundary it samples
     * the right place in the wrong tile. On real terrain that is a grid of displaced rectangles,
     * and it reads as a tile-seam problem rather than the indexing problem it is.
     */
    const { atlas } = atlasWith()
    atlas.upload(tile(0, 0, 0), pixels())
    atlas.upload(tile(1, 0, 0), pixels())
    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])
    // Whole world, finest level 1: exactly two page texels across and two down.
    expect(atlas.pageSize()).toEqual([2, 2])
    expect(atlas.stats().pageAligned).toBe(true)

    atlas.upload(tile(3, 0, 0), pixels())
    atlas.buildPages([tile(0, 0, 0), tile(3, 0, 0)])
    expect(atlas.pageSize()).toEqual([8, 8])
  })

  it('says so when the extent needs a finer grid than the cap allows', () => {
    // Silently capping puts the straddling artefact back with nothing to explain it.
    const { atlas } = atlasWith({ options: { pageResolution: 4 } })
    atlas.upload(tile(0, 0, 0), pixels())
    atlas.upload(tile(5, 0, 0), pixels())
    atlas.buildPages([tile(0, 0, 0), tile(5, 0, 0)])
    expect(atlas.pageSize()).toEqual([4, 4])
    expect(atlas.stats().pageAligned).toBe(false)
  })

  it('points every page at the finest resident tile covering it', () => {
    const { atlas, calls, tick } = atlasWith()
    atlas.upload(tile(0, 0, 0), pixels())
    atlas.upload(tile(1, 0, 0), pixels())     // the north-west quarter of the world
    tick(1000)                                // both fully faded in

    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])
    const fine = pageOf(calls, 'fine')

    // A 2x2 page over the whole square: (0,0) is the z1 tile's quarter, (1,1) is not.
    expect(at(atlas, fine, 0, 0).level).toBe(1)
    expect(at(atlas, fine, 1, 1).level).toBe(0)
  })

  it('falls back to the resident ancestor where a child has not arrived', () => {
    const { atlas, calls, tick } = atlasWith()
    atlas.upload(tile(0, 0, 0), pixels())
    atlas.upload(tile(1, 0, 0), pixels())
    tick(1000)

    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])
    const ancestor = pageOf(calls, 'ancestor')
    // Under the z1 tile the ancestor is the z0 tile it replaced.
    expect(at(atlas, ancestor, 0, 0).level).toBe(0)
  })

  it('fades a tile in over its ancestor instead of popping', () => {
    const { atlas, calls, tick } = atlasWith()
    atlas.upload(tile(0, 0, 0), pixels())
    tick(1000)
    atlas.upload(tile(1, 0, 0), pixels())    // brand new

    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])
    expect(at(atlas, pageOf(calls, 'fine'), 0, 0).fade).toBeCloseTo(0, 2)

    tick(50)
    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])
    const half = at(atlas, pageOf(calls, 'fine'), 0, 0).fade
    expect(half).toBeGreaterThan(0.3)
    expect(half).toBeLessThan(0.7)

    tick(100)
    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])
    expect(at(atlas, pageOf(calls, 'fine'), 0, 0).fade).toBeCloseTo(1, 2)
  })

  it('shows a tile outright when there is nothing underneath to fade from', () => {
    // Nothing to cross-fade with means fading in from black, which reads as a flash. A page with
    // no ancestor is drawn at full weight from its first frame.
    const { atlas, calls } = atlasWith()
    atlas.upload(tile(0, 0, 0), pixels())
    atlas.buildPages([tile(0, 0, 0)])
    expect(at(atlas, pageOf(calls, 'fine'), 0, 0).fade).toBeCloseTo(1, 2)
  })

  it('skips tiles that are not resident yet rather than pointing at a stale slot', () => {
    const { atlas, calls, tick } = atlasWith()
    atlas.upload(tile(0, 0, 0), pixels())
    tick(1000)
    atlas.buildPages([tile(0, 0, 0), tile(1, 0, 0)])   // the z1 tile was never uploaded
    expect(at(atlas, pageOf(calls, 'fine'), 0, 0).level).toBe(0)
  })

  it('reports the mercator rectangle it covers, so the shader can find its way in', () => {
    const { atlas } = atlasWith()
    atlas.upload(tile(2, 1, 1), pixels())
    atlas.buildPages([tile(2, 1, 1)])
    const [x0, y0, invW, invH] = atlas.pageExtent()
    expect(x0).toBeCloseTo(0.25, 6)
    expect(y0).toBeCloseTo(0.25, 6)
    expect(invW).toBeCloseTo(4, 6)
    expect(invH).toBeCloseTo(4, 6)
  })
})

describe('the shader side', () => {
  it('offers the three functions the overlay layers were promised', () => {
    const { atlas } = atlasWith()
    expect(atlas.glsl).toContain('vec4 tiledSample(vec2')
    expect(atlas.glsl).toContain('vec4 tiledSampleSphere(vec3')
    expect(atlas.glsl).toContain('float tiledShortfall()')
  })

  it('samples the array, not a flat texture', () => {
    const { atlas } = atlasWith()
    expect(atlas.glsl).toContain('sampler2DArray u_tm_tiles')
  })

  it('declares a precision for the sampler, which ES 3.00 does not default', () => {
    // Found by compiling this for real: without it the driver rejects the shader with "No
    // precision specified", the program never links, and the layer draws nothing with no clue why.
    const { atlas } = atlasWith()
    expect(atlas.glsl).toMatch(/precision\s+\w+\s+sampler2DArray\s*;/)
    expect(atlas.glsl.indexOf('precision')).toBeLessThan(atlas.glsl.indexOf('uniform sampler2DArray'))
  })

  it('binds every sampler it declares to the unit it was given', () => {
    const { atlas, calls } = atlasWith()
    atlas.bind({ tiles: 'u_tm_tiles', page: 'u_tm_page', ancestor: 'u_tm_pageAncestor', extent: 'u_tm_pageExtent', shortfall: 'u_tm_shortfall' }, 2)
    expect(calls.uniform1i.u_tm_tiles).toBe(2)
    expect(calls.uniform1i.u_tm_page).toBe(3)
    expect(calls.uniform1i.u_tm_pageAncestor).toBe(4)
    expect(calls.uniform4f.u_tm_pageExtent).toHaveLength(4)
  })

  it('tolerates a program that does not declare every uniform', () => {
    // getUniformLocation returns null for anything the compiler optimised away, and a layer that
    // only wants the mask never mentions the shortfall.
    const { atlas } = atlasWith()
    expect(() => atlas.bind({ tiles: 'u_tm_tiles', page: null, ancestor: null, extent: null }, 0)).not.toThrow()
  })
})

describe('giving the memory back', () => {
  it('deletes the array and both page tables on removal', () => {
    const { atlas, calls } = atlasWith()
    atlas.upload(tile(3, 1, 1), pixels())
    atlas.dispose()
    expect(calls.deleteTexture).toBe(3)
    expect(atlas.slotOf('3/1/1')).toBe(-1)
  })

  it('survives being disposed twice, and disposed before anything was uploaded', () => {
    const { atlas } = atlasWith()
    atlas.dispose()
    expect(() => atlas.dispose()).not.toThrow()
  })
})
