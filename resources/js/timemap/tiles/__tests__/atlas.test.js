import { describe, it, expect, vi } from 'vitest'
import { createTileAtlas } from '../atlas.js'
import { keyOf, tileForLngLat } from '../scheme.js'
import { mercatorYFromLat } from '../../planet-mesh.js'

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
    deleteTexture: 0, createTexture: 0, bindTexture: [], uniform1i: {}, uniform2f: {}, uniform4f: {},
  }
  const gl = {
    TEXTURE_2D: 3553, TEXTURE_2D_ARRAY: 35866, RGBA8: 32856, RGBA: 6408, UNSIGNED_BYTE: 5121,
    TEXTURE_MIN_FILTER: 10241, TEXTURE_MAG_FILTER: 10240, TEXTURE_WRAP_S: 10242, TEXTURE_WRAP_T: 10243,
    NEAREST: 9728, LINEAR: 9729, CLAMP_TO_EDGE: 33071,
    MAX_ARRAY_TEXTURE_LAYERS: 35071,
    TEXTURE0: 33984,
    RG8: 33323, RG: 33319,
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
    uniform2f: (name, ...values) => { calls.uniform2f[name] = values },
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

  it('uses a two-channel array when the source only needs sixteen bits', () => {
    // Half the VRAM for the same data. On a school Chromebook that is the difference between
    // holding twice as much of the world resident and not.
    const { gl, calls } = gl2Stub()
    const atlas = createTileAtlas(gl, { tileSize: 256, layers: 128, channels: 'rg' })
    expect(calls.texStorage3D[0][2]).toBe(gl.RG8)
    expect(atlas.stats().atlasBytes).toBe(128 * 256 * 256 * 2)

    const four = createTileAtlas(gl2Stub().gl, { tileSize: 256, layers: 128 })
    expect(four.stats().atlasBytes).toBe(128 * 256 * 256 * 4)
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

  it('names the tile that actually contains a known point on the earth', () => {
    /**
     * THE ABSOLUTE POSITIONAL ANCHOR, and it belongs here because this is where the positional bug
     * was. The straddle put the right place in the wrong tile, and every other test in this file
     * compares a page entry against another page entry — a pyramid displaced by a uniform offset
     * satisfies all of them.
     *
     * So: take real coordinates, work out independently which tile covers them, then walk the page
     * table exactly as the shader does and check it points at that tile's slot. If the page grid,
     * the extent, or the tile bounds were offset by anything at all, this disagrees.
     */
    const { atlas, calls } = atlasWith({ options: { pageResolution: 64 } })
    const LEVEL = 5
    const places = [
      { name: 'Amsterdam', lng: 4.895, lat: 52.37 },
      { name: 'Sydney', lng: 151.209, lat: -33.868 },
      { name: 'the origin', lng: 0, lat: 0 },
    ]

    // One tile per place, plus a coarse tile underneath so the page has something to fall back to.
    const tiles = places.map((place) => {
      const found = tileForLngLat(place.lng, place.lat, LEVEL)
      return { z: found.z, x: found.x, y: found.y, key: keyOf(found) }
    })
    atlas.upload(tile(0, 0, 0), pixels())
    tiles.forEach((t) => atlas.upload(t, pixels()))
    atlas.buildPages([tile(0, 0, 0), ...tiles])

    const fine = calls.texImage2D.slice(-2)[0].at(-1)
    const [width, height] = atlas.pageSize()
    const [x0, y0, invWidth, invHeight] = atlas.pageExtent()

    places.forEach((place, index) => {
      // The shader's own lookup: mercator coordinate -> page space -> page texel.
      const mx = (place.lng + 180) / 360
      const my = mercatorYFromLat(place.lat)
      const i = Math.min(width - 1, Math.floor((mx - x0) * invWidth * width))
      const j = Math.min(height - 1, Math.floor((my - y0) * invHeight * height))
      const slot = fine[(j * width + i) * 4]
      const level = fine[(j * width + i) * 4 + 1]

      expect(level, `${place.name} level`).toBe(LEVEL)
      expect(slot, `${place.name} slot`).toBe(atlas.slotOf(tiles[index].key))
    })
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
  it('offers the functions the overlay layers were promised', () => {
    const { atlas } = atlasWith()
    expect(atlas.glsl).toContain('vec4 tiledSample(vec2')
    expect(atlas.glsl).toContain('vec4 tiledSampleSphere(vec3')
    expect(atlas.glsl).toContain('float tiledShortfall()')
    expect(atlas.glsl).toContain('float tiledHeight(vec2')
    expect(atlas.glsl).toContain('float tiledDepth(vec2')
    expect(atlas.glsl).toContain('vec3 tiledNormal(vec2')
  })

  it('hands consumers one polar-cap signal rather than letting each invent a latitude test', () => {
    /**
     * The sphere has ground the tile grid does not cover: mercator stops at ±85.0511° and
     * buildSphereMesh reaches ±89.999°. Past the edge `tiledSampleSphere` clamps to the last row and
     * smears it, silently. `tiledSphereCoverage` is the confidence, so every overlay lets go of the
     * pole at the same latitude instead of three sessions each picking one.
     */
    const { atlas, calls } = atlasWith()
    expect(atlas.glsl).toContain('float tiledSphereCoverage(vec3')
    atlas.bind({ capFade: 'u_tm_capFade' }, 0)
    const [start, end] = calls.uniform4f.u_tm_capFade ?? calls.uniform2f.u_tm_capFade
    expect(end).toBeCloseTo(85.0511287798066, 9)
    expect(start).toBeLessThan(end)
  })

  it('bakes neither clamp into the shader path that both consumers share', () => {
    /**
     * Relief wants max(h, 0); the ocean wants the negative half as depth. `tiledHeight` must hand
     * back the signed number so each takes its own — a clamp here would leave the ocean with a flat
     * sea floor, no error anywhere, and relief still looking perfect.
     */
    const { atlas } = atlasWith()
    const height = atlas.glsl.slice(atlas.glsl.indexOf('float tiledHeight('), atlas.glsl.indexOf('float tiledDepth('))
    expect(height).not.toContain('max(0.0')
    expect(atlas.glsl).toContain('float tiledDepth(vec2 mercatorUV) { return max(0.0, -tiledHeight')
  })

  it('derives the normal from taps that route through the page table, not from an apron', () => {
    // Each tap is a full tiledHeight call, so one that crosses a tile boundary lands in the
    // neighbouring tile rather than clamping — which is what removes the edge seam without
    // fetching neighbours or baking a border.
    const { atlas } = atlasWith()
    const slope = atlas.glsl.slice(atlas.glsl.indexOf('vec2 tm_slope('), atlas.glsl.indexOf('vec3 tiledNormal('))
    expect(slope.match(/tiledHeight\(/g)).toHaveLength(2)
    // Clamped per tap, before differencing: clamping after averaging deletes coastal mountains.
    expect(slope.match(/max\(0\.0, tiledHeight/g)).toHaveLength(2)
  })

  it('refuses to difference across a level boundary, and divides by the span it actually used', () => {
    /**
     * Neighbouring tiles at different levels disagree about the height along their shared edge,
     * because they sampled the same ground at different resolutions. Differencing across that turns
     * a resampling difference into a slope and draws a dark line down every level boundary.
     *
     * The second half matters as much: a one-sided difference divided by the two-sided baseline
     * reads as half the true slope, which is a soft bright band rather than a hard dark line —
     * better hidden and just as wrong.
     */
    const { atlas } = atlasWith()
    const slope = atlas.glsl.slice(atlas.glsl.indexOf('vec2 tm_slope('), atlas.glsl.indexOf('vec3 tiledNormal('))
    expect(slope).toContain('tiledLevel(forward) == level')
    expect(slope).toContain('tiledLevel(back) == level')
    expect(slope).toContain('max(1.0, span)')
    // The normal divides by that span, not by a hardcoded 2.
    const normal = atlas.glsl.slice(atlas.glsl.indexOf('vec3 tiledNormal('))
    expect(normal).toContain('dx.y')
    expect(normal).toContain('dy.y')
    expect(normal).not.toContain('2.0 * ground')
  })

  it('samples the array, not a flat texture', () => {
    const { atlas } = atlasWith()
    expect(atlas.glsl).toContain('sampler2DArray u_tm_tiles')
  })

  it('carries no backtick, which would close the template the GLSL lives in', () => {
    // Caught three times while writing this: a backtick in a GLSL comment ends the template
    // literal, and the module then fails to parse with an error pointing at prose. Cheap to pin.
    expect(atlasWith().atlas.glsl).not.toContain('`')
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
