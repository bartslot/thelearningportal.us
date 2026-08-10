import { describe, it, expect, vi, afterEach } from 'vitest'
import {
  SEA_BAND_M,
  TERRARIUM_TERRAIN,
  decodeTerrainTile,
  decodeTerrarium,
  seaLevelRamp,
  terrainTextureFromHeights,
  tileUrl,
} from '../sources.js'

/**
 * The DEM is already in the project — `map-imagery.js` uses these exact tiles for the atlas
 * hillshade. Reusing them is the point: mountain shadow and the ocean coastline both come out of
 * real tiles at real LOD, and neither session has to bake a global asset.
 */

/** Terrarium packs a height into a pixel. This is that packing, used to build test fixtures. */
const encode = (metres) => {
  const raw = Math.round((metres + 32768) * 256)
  return [(raw >> 16) & 255, (raw >> 8) & 255, raw & 255]
}

/** An RGBA image of `size` square where every pixel's height comes from `heightAt(x, y)`. */
const demImage = (size, heightAt) => {
  const pixels = new Uint8ClampedArray(size * size * 4)
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const [r, g, b] = encode(heightAt(x, y))
      const i = (y * size + x) * 4
      pixels[i] = r
      pixels[i + 1] = g
      pixels[i + 2] = b
      pixels[i + 3] = 255
    }
  }
  return pixels
}

describe('decoding terrarium', () => {
  it('reads the published formula, with 32768 as sea level', () => {
    // height = (R*256 + G + B/256) - 32768
    expect(decodeTerrarium(new Uint8ClampedArray([128, 0, 0, 255]), 1)[0]).toBeCloseTo(0, 6)
    expect(decodeTerrarium(new Uint8ClampedArray([128, 1, 0, 255]), 1)[0]).toBeCloseTo(1, 6)
    expect(decodeTerrarium(new Uint8ClampedArray([128, 0, 128, 255]), 1)[0]).toBeCloseTo(0.5, 6)
  })

  it('carries a mountain and a trench without losing them', () => {
    const heights = decodeTerrarium(demImage(2, (x) => (x === 0 ? 8848 : -10935)), 2)
    expect(heights[0]).toBeCloseTo(8848, 2)   // Everest
    expect(heights[1]).toBeCloseTo(-10935, 2) // Challenger Deep
  })

  it('returns one height per pixel', () => {
    expect(decodeTerrarium(demImage(8, () => 0), 8)).toHaveLength(64)
  })
})

describe('sea level', () => {
  it('puts the shoreline exactly in the middle of the ramp', () => {
    expect(seaLevelRamp(0)).toBeCloseTo(0.5, 6)
  })

  it('is linear through the band, so filtering between texels lands where the coast really is', () => {
    /**
     * A hard 0/1 mask is what makes the current baked mask step along every shore: linear filtering
     * across a binary edge puts the coastline at the texel boundary rather than where the land
     * actually stops. A ramp in METRES interpolates to the true crossing.
     */
    const quarter = seaLevelRamp(SEA_BAND_M / 2)
    expect(quarter).toBeCloseTo(0.75, 6)
    expect(seaLevelRamp(-SEA_BAND_M / 2)).toBeCloseTo(0.25, 6)
  })

  it('saturates outside the band rather than running away', () => {
    expect(seaLevelRamp(8848)).toBe(1)
    expect(seaLevelRamp(-10935)).toBe(0)
  })
})

describe('normals from heights', () => {
  const SIZE = 8
  const METRES_PER_TEXEL = 100

  const unpack = (rgba, size, x, y) => {
    const i = (y * size + x) * 4
    return {
      x: (rgba[i] / 255) * 2 - 1,
      y: (rgba[i + 1] / 255) * 2 - 1,
      z: (rgba[i + 2] / 255) * 2 - 1,
      land: rgba[i + 3] / 255,
    }
  }

  it('points straight up over flat ground', () => {
    const flat = terrainTextureFromHeights(decodeTerrarium(demImage(SIZE, () => 500), SIZE), SIZE, METRES_PER_TEXEL)
    const n = unpack(flat, SIZE, 4, 4)
    expect(n.x).toBeCloseTo(0, 2)
    expect(n.y).toBeCloseTo(0, 2)
    expect(n.z).toBeCloseTo(1, 2)
  })

  it('tilts away from a slope that rises to the east', () => {
    // Ground climbing eastward has a normal leaning west, which is negative x.
    const heights = decodeTerrarium(demImage(SIZE, (x) => x * 50), SIZE)
    const n = unpack(terrainTextureFromHeights(heights, SIZE, METRES_PER_TEXEL), SIZE, 4, 4)
    expect(n.x).toBeLessThan(-0.3)
    expect(n.y).toBeCloseTo(0, 2)
  })

  it('tilts the other way for a slope rising to the south', () => {
    const heights = decodeTerrarium(demImage(SIZE, (_x, y) => y * 50), SIZE)
    const n = unpack(terrainTextureFromHeights(heights, SIZE, METRES_PER_TEXEL), SIZE, 4, 4)
    expect(n.y).toBeLessThan(-0.3)
    expect(n.x).toBeCloseTo(0, 2)
  })

  it('leans harder where the ground is steeper', () => {
    const gentle = decodeTerrarium(demImage(SIZE, (x) => x * 5), SIZE)
    const steep = decodeTerrarium(demImage(SIZE, (x) => x * 200), SIZE)
    const a = unpack(terrainTextureFromHeights(gentle, SIZE, METRES_PER_TEXEL), SIZE, 4, 4)
    const b = unpack(terrainTextureFromHeights(steep, SIZE, METRES_PER_TEXEL), SIZE, 4, 4)
    expect(Math.abs(b.x)).toBeGreaterThan(Math.abs(a.x))
  })

  it('reads the same slope as steeper where texels cover less ground', () => {
    /**
     * The same height difference across 38 m of ground (z12) is a far steeper hill than across
     * 611 m (z8). Forgetting to scale by the tile's own resolution makes every level look like a
     * different planet, and it is invisible until two levels are on screen at once.
     */
    const heights = decodeTerrarium(demImage(SIZE, (x) => x * 50), SIZE)
    const coarse = unpack(terrainTextureFromHeights(heights, SIZE, 600), SIZE, 4, 4)
    const fine = unpack(terrainTextureFromHeights(heights, SIZE, 40), SIZE, 4, 4)
    expect(Math.abs(fine.x)).toBeGreaterThan(Math.abs(coarse.x))
  })

  it('carries the coastline in the alpha channel, so one tile serves shadow and ocean alike', () => {
    const heights = decodeTerrarium(demImage(SIZE, (x) => (x < 4 ? -500 : 500)), SIZE)
    const rgba = terrainTextureFromHeights(heights, SIZE, METRES_PER_TEXEL)
    expect(unpack(rgba, SIZE, 1, 4).land).toBeCloseTo(0, 2)
    expect(unpack(rgba, SIZE, 6, 4).land).toBeCloseTo(1, 2)
  })

  it('produces a full RGBA image with nothing out of range or NaN', () => {
    const heights = decodeTerrarium(demImage(SIZE, (x, y) => Math.sin(x) * 900 + y * 30), SIZE)
    const rgba = terrainTextureFromHeights(heights, SIZE, METRES_PER_TEXEL)
    expect(rgba).toHaveLength(SIZE * SIZE * 4)
    rgba.forEach((value) => {
      expect(Number.isFinite(value)).toBe(true)
      expect(value).toBeGreaterThanOrEqual(0)
      expect(value).toBeLessThanOrEqual(255)
    })
  })

  it('clamps at the tile edge rather than reading off the end of the array', () => {
    // Terrarium tiles do not overlap, so an edge texel is missing a neighbour row. Clamping costs
    // a half-texel seam; reading past the end would wrap a normal from the far side of the tile.
    const heights = decodeTerrarium(demImage(SIZE, (x) => x * 50), SIZE)
    const rgba = terrainTextureFromHeights(heights, SIZE, METRES_PER_TEXEL)
    for (const [x, y] of [[0, 0], [SIZE - 1, 0], [0, SIZE - 1], [SIZE - 1, SIZE - 1]]) {
      const n = unpack(rgba, SIZE, x, y)
      expect(Number.isFinite(n.x)).toBe(true)
      expect(n.z).toBeGreaterThan(0)
    }
  })
})

describe('turning fetched PNG bytes into a tile', () => {
  /**
   * The glue between the cache and the atlas. Nothing clever happens here, which is exactly why it
   * is worth a test: if it silently produces the wrong size or forgets the latitude scaling, every
   * tile is subtly wrong and nothing errors.
   */
  const SIZE = TERRARIUM_TERRAIN.tileSize
  let lastImageDataSize = null

  const stubBrowserDecode = (heightAt) => {
    globalThis.createImageBitmap = vi.fn(async () => ({ close: vi.fn() }))
    globalThis.OffscreenCanvas = class {
      constructor(width, height) { this.width = width; this.height = height }
      getContext() {
        return {
          drawImage: () => {},
          getImageData: (_x, _y, width, height) => {
            lastImageDataSize = [width, height]
            return { data: demImage(width, heightAt) }
          },
        }
      }
    }
  }

  afterEach(() => {
    delete globalThis.createImageBitmap
    delete globalThis.OffscreenCanvas
    lastImageDataSize = null
  })

  it('produces one RGBA texel per DEM pixel, at the source tile size', async () => {
    stubBrowserDecode(() => 250)
    const { data, bytes } = await decodeTerrainTile(new ArrayBuffer(8), { z: 6, x: 33, y: 21 })
    expect(lastImageDataSize).toEqual([SIZE, SIZE])
    expect(data).toHaveLength(SIZE * SIZE * 4)
    expect(bytes).toBe(SIZE * SIZE * 4)
  })

  it('scales the normals by the tile\'s own ground resolution, not a fixed one', async () => {
    /**
     * The same DEM read at z4 and at z11 is the same heights across wildly different ground. If the
     * spacing did not come from the tile, one of those two levels would be a different planet — and
     * cross-level blending puts both on screen at once.
     */
    stubBrowserDecode((x) => x * 40)
    const { data: coarse } = await decodeTerrainTile(new ArrayBuffer(8), { z: 4, x: 8, y: 5 })
    const { data: fine } = await decodeTerrainTile(new ArrayBuffer(8), { z: 11, x: 1050, y: 670 })
    const nx = (rgba) => rgba[(4 * SIZE + 4) * 4] / 255
    expect(Math.abs(nx(fine) - 0.5)).toBeGreaterThan(Math.abs(nx(coarse) - 0.5))
  })

  it('releases the decoded bitmap rather than leaking one per tile', async () => {
    stubBrowserDecode(() => 0)
    let bitmap = null
    globalThis.createImageBitmap = vi.fn(async () => { bitmap = { close: vi.fn() }; return bitmap })
    await decodeTerrainTile(new ArrayBuffer(8), { z: 6, x: 33, y: 21 })
    expect(bitmap.close).toHaveBeenCalled()
  })
})

describe('the source descriptor', () => {
  it('is the DEM map-imagery.js already uses, so nothing new is introduced', () => {
    expect(TERRARIUM_TERRAIN.url).toBe('https://s3.amazonaws.com/elevation-tiles-prod/terrarium/{z}/{x}/{y}.png')
    expect(TERRARIUM_TERRAIN.maxzoom).toBe(12)
    expect(TERRARIUM_TERRAIN.tileSize).toBe(256)
  })

  it('fills a tile into its url template', () => {
    expect(tileUrl(TERRARIUM_TERRAIN, { z: 7, x: 65, y: 42 }))
      .toBe('https://s3.amazonaws.com/elevation-tiles-prod/terrarium/7/65/42.png')
  })
})
