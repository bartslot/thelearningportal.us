import { describe, it, expect, vi } from 'vitest'
import {
  coastTerrain,
  flatTerrain,
  oceanTerrain,
  ridgeTerrain,
  syntheticSource,
  syntheticTerrain,
} from '../fixtures.js'
import { createTilePyramid } from '../index.js'
import { memoryStore } from '../cache.js'
import { decodeHeightTexel } from '../sources.js'
import { keyOf, tileForLngLat, tileMercatorBounds } from '../scheme.js'
import { latFromMercatorY } from '../../planet-mesh.js'

/**
 * These exist so a measurement rig can be offline and deterministic, and so it can assert what a
 * pixel SHOULD be rather than that it changed. Both properties are load-bearing, so both are tested.
 */

const readHeight = (data, tileSize, column, row) => {
  const index = (row * tileSize + column) * 2
  return decodeHeightTexel(data[index], data[index + 1])
}

describe('generating a tile without a network', () => {
  const TILE_SIZE = 8

  it('never fetches anything, at any zoom', async () => {
    const fetchSpy = vi.fn()
    const { decode } = syntheticTerrain(flatTerrain(500), { tileSize: TILE_SIZE })
    const source = { ...syntheticSource({ tileSize: TILE_SIZE }), tileSize: TILE_SIZE }
    const pyramid = createTilePyramid({ source, decode, fetch: fetchSpy, store: memoryStore(), layers: 32, maxTiles: 16 })
    // Before onAdd there is no atlas, so update stands down — and must not reach the network on
    // the way to standing down.
    pyramid.update({ center: { lng: 0, lat: 0 }, zoom: 5, canvasWidth: 400, canvasHeight: 300, projection: 'globe' })
    await pyramid.idle()
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('gives the same tile every time, so a rig is reproducible', async () => {
    const { decode } = syntheticTerrain(ridgeTerrain(), { tileSize: TILE_SIZE })
    const tile = { z: 6, x: 33, y: 21 }
    const first = await decode(new ArrayBuffer(0), tile)
    const second = await decode(new ArrayBuffer(0), tile)
    expect(Array.from(first.data)).toEqual(Array.from(second.data))
  })

  it('agrees with itself across a tile boundary, so seams can be measured', async () => {
    /**
     * The height is a function of the ground, not of the tile, so the last column of one tile and
     * the first of its neighbour are half a texel apart and nearly equal. A fixture that generated
     * per tile would manufacture the very seam a seam test is looking for.
     */
    const AMPLITUDE = 1200
    const CYCLES = 4
    const { decode } = syntheticTerrain(ridgeTerrain({ amplitudeM: AMPLITUDE, cycles: CYCLES }), { tileSize: TILE_SIZE })
    const left = await decode(new ArrayBuffer(0), { z: 4, x: 7, y: 8 })
    const right = await decode(new ArrayBuffer(0), { z: 4, x: 8, y: 8 })
    const lastOfLeft = readHeight(left.data, TILE_SIZE, TILE_SIZE - 1, 4)
    const firstOfRight = readHeight(right.data, TILE_SIZE, 0, 4)

    // Those two texel centres are exactly one texel apart across the join, so the step between them
    // must be one texel's worth of the ridge — no more (a discontinuity) and no less (a duplicated
    // edge column). Asserting the exact gradient is a far stronger claim than "nearly equal".
    const texel = 1 / (2 ** 4 * TILE_SIZE)
    const mxAtJoin = 0.5
    const expected = Math.abs(
      AMPLITUDE * Math.sin(2 * Math.PI * CYCLES * (mxAtJoin + texel / 2))
      - AMPLITUDE * Math.sin(2 * Math.PI * CYCLES * (mxAtJoin - texel / 2)),
    )
    // Within the storage's own resolution: two texels each rounded to the nearest 1/3 m can differ
    // by up to 2/3 m from the exact answer, and no assertion here can be tighter than that.
    expect(Math.abs(Math.abs(lastOfLeft - firstOfRight) - expected)).toBeLessThan(2 / 3)
  })

  it('gives the same ground the same height at every level', async () => {
    // A coarse tile and a fine tile covering the same point must agree, or cross-level blending
    // measures the fixture rather than the blend.
    const { decode } = syntheticTerrain(ridgeTerrain({ cycles: 2 }), { tileSize: TILE_SIZE })
    const sample = async (z) => {
      const tile = tileForLngLat(4.895, 52.37, z)
      const { data } = await decode(new ArrayBuffer(0), tile)
      const { x0, y0, size } = tileMercatorBounds(tile)
      const mx = (4.895 + 180) / 360
      const column = Math.min(TILE_SIZE - 1, Math.floor(((mx - x0) / size) * TILE_SIZE))
      return readHeight(data, TILE_SIZE, column, 0)
    }
    // Within one texel's worth of the ridge, which is all a discrete grid can promise.
    expect(Math.abs((await sample(6)) - (await sample(9)))).toBeLessThan(400)
  })
})

describe('surfaces with a known answer', () => {
  it('makes flat ground exactly flat — the strongest assertion a relief rig has', () => {
    /**
     * A relief term that tilts flat ground is the constant-bias failure: a decode centred half a
     * step out, a stencil reading its own texel. Against real terrain it is invisible because
     * everything moves together. Against this it is the whole signal.
     */
    const flat = flatTerrain(500)
    expect(flat(0.1, 0.2)).toBe(500)
    expect(flat.normalAt()).toEqual([0, 0, 1])
  })

  it('gives the ridge a normal in closed form, matching a numerical gradient', () => {
    // If the analytic normal and a finite difference of the same surface disagree, the fixture is
    // lying and every absolute assertion built on it is worthless.
    const ridge = ridgeTerrain({ amplitudeM: 1200, cycles: 64 })
    const my = 0.32
    for (const mx of [0.1, 0.2005, 0.37, 0.55]) {
      const step = 1e-7
      const slopeMercator = (ridge(mx + step) - ridge(mx - step)) / (2 * step)
      const analytic = ridge.normalAt(mx, my)
      // Recover dh/dEast from the analytic normal: n = normalize(-dh/dEast, 0, 1).
      const recovered = -analytic[0] / analytic[2]
      // The latitude has to come from the mercator y the normal was asked about, not from a
      // constant — the whole point of the conversion is that it varies with cos(lat).
      const metresPerUnit = 40075016.685578488 * Math.cos((latFromMercatorY(my) * Math.PI) / 180)
      expect(recovered).toBeCloseTo(slopeMercator / metresPerUnit, 6)
    }
  })

  it('keeps the ridge inside the elevation range at every zoom', async () => {
    // A tilted plane is the obvious analytic surface and runs to hundreds of kilometres of rise
    // across a z0 tile, which the encoder clamps into a staircase. Bounded amplitude does not.
    const { decode } = syntheticTerrain(ridgeTerrain({ amplitudeM: 1200 }), { tileSize: 8 })
    for (const z of [0, 5, 12]) {
      const { data } = await decode(new ArrayBuffer(0), { z, x: 0, y: 0 })
      for (let i = 0; i < 8 * 8; i++) {
        const height = readHeight(data, 8, i % 8, Math.floor(i / 8))
        expect(Math.abs(height)).toBeLessThanOrEqual(1201)
      }
    }
  })

  it('puts a shoreline at an exactly known longitude, for the ocean rig', async () => {
    const coast = coastTerrain({ shoreLng: 0, landM: 800, seaM: -2000 })
    expect(coast.shoreMercatorX).toBeCloseTo(0.5, 12)
    const { decode } = syntheticTerrain(coast, { tileSize: 8 })
    // z1 tile (0,0) is the north-west quarter: entirely west of the meridian, so entirely sea.
    const west = await decode(new ArrayBuffer(0), { z: 1, x: 0, y: 0 })
    const east = await decode(new ArrayBuffer(0), { z: 1, x: 1, y: 0 })
    expect(readHeight(west.data, 8, 4, 4)).toBeCloseTo(-2000, 0)
    expect(readHeight(east.data, 8, 4, 4)).toBeCloseTo(800, 0)
  })

  it('keeps the sea floor signed, so an ocean rig has something to ramp over', async () => {
    const { decode } = syntheticTerrain(oceanTerrain(-3000), { tileSize: 8 })
    const { data } = await decode(new ArrayBuffer(0), { z: 3, x: 4, y: 4 })
    expect(readHeight(data, 8, 2, 2)).toBeCloseTo(-3000, 0)
  })
})

describe('driving a whole pyramid offline', () => {
  it('streams, uploads and reports without a network or a real DEM', async () => {
    const calls = { texSubImage3D: 0 }
    const gl = {
      TEXTURE_2D: 3553, TEXTURE_2D_ARRAY: 35866, RGBA8: 32856, RGBA: 6408, UNSIGNED_BYTE: 5121,
      RG8: 33323, RG: 33319,
      TEXTURE_MIN_FILTER: 10241, TEXTURE_MAG_FILTER: 10240, TEXTURE_WRAP_S: 10242, TEXTURE_WRAP_T: 10243,
      NEAREST: 9728, LINEAR: 9729, CLAMP_TO_EDGE: 33071, MAX_ARRAY_TEXTURE_LAYERS: 35071, TEXTURE0: 33984,
      createTexture: () => ({}), deleteTexture: () => {}, bindTexture: () => {}, activeTexture: () => {},
      texStorage3D: () => {}, texSubImage3D: () => { calls.texSubImage3D++ }, texImage2D: () => {},
      texParameteri: () => {}, pixelStorei: () => {}, getParameter: () => 256,
      uniform1i: () => {}, uniform1f: () => {}, uniform2f: () => {}, uniform4f: () => {},
    }

    const source = syntheticSource({ tileSize: 8 })
    const { fetch, decode } = syntheticTerrain(ridgeTerrain(), { tileSize: 8 })
    const pyramid = createTilePyramid({ source, fetch, decode, store: memoryStore(), layers: 64, maxTiles: 32 })
    pyramid.onAdd(gl)

    const camera = { center: { lng: 7.9, lat: 46 }, zoom: 8, canvasWidth: 800, canvasHeight: 600, projection: 'globe' }
    pyramid.update(camera)
    await pyramid.idle()
    pyramid.update(camera)

    const stats = pyramid.stats()
    expect(stats.tileCount).toBeGreaterThan(0)
    expect(stats.completeness).toBe(1)
    expect(stats.networkFetches).toBeGreaterThan(0)   // the synthetic fetch, not the network
    expect(calls.texSubImage3D).toBeGreaterThan(0)
    // Every selected tile is at a real level of the synthetic source, keyed the ordinary way.
    Object.keys(pyramid.lastLevels()).forEach((level) => {
      expect(Number(level)).toBeGreaterThanOrEqual(0)
      expect(Number(level)).toBeLessThanOrEqual(source.maxzoom)
    })
    expect(keyOf({ z: 3, x: 4, y: 5 })).toBe('3/4/5')
    pyramid.onRemove()
  })
})
