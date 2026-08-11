import { describe, it, expect, vi, afterEach } from 'vitest'
import {
  MAX_ELEVATION_M,
  MIN_ELEVATION_M,
  TERRARIUM_TERRAIN,
  decodeHeightTexel,
  decodeTerrainTile,
  decodeTerrarium,
  heightTextureFromHeights,
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

describe('storing a height', () => {
  /**
   * Sixteen bits of signed height, and NO clamp baked in. Relief takes `max(h, 0)` and the ocean
   * takes the negative half; a tile that arrived pre-flattened would leave the ocean with no data
   * and no error, while relief carried on looking perfect.
   */
  it('round-trips a height through two bytes', () => {
    for (const metres of [0, 1, -1, 8848, -10935, 250.5, -3200]) {
      const packed = heightTextureFromHeights(Float32Array.from([metres]), 1)
      expect(decodeHeightTexel(packed[0], packed[1])).toBeCloseTo(metres, 0)
    }
  })

  it('keeps the sea floor, which is the ocean layer\'s only data source', () => {
    const packed = heightTextureFromHeights(Float32Array.from([-4000]), 1)
    expect(decodeHeightTexel(packed[0], packed[1])).toBeLessThan(-3900)
  })

  it('resolves better than a metre, so a depth ramp does not band into contour rings', () => {
    const a = heightTextureFromHeights(Float32Array.from([-100]), 1)
    const b = heightTextureFromHeights(Float32Array.from([-100.5]), 1)
    expect(decodeHeightTexel(a[0], a[1])).not.toBeCloseTo(decodeHeightTexel(b[0], b[1]), 4)
    const step = Math.abs(decodeHeightTexel(a[0], a[1]) - decodeHeightTexel(b[0], b[1]))
    expect(step).toBeLessThan(1)
  })

  it('holds the whole real range without saturating at either end', () => {
    const deep = heightTextureFromHeights(Float32Array.from([MIN_ELEVATION_M]), 1)
    const high = heightTextureFromHeights(Float32Array.from([MAX_ELEVATION_M]), 1)
    expect(decodeHeightTexel(deep[0], deep[1])).toBeCloseTo(MIN_ELEVATION_M, 0)
    expect(decodeHeightTexel(high[0], high[1])).toBeCloseTo(MAX_ELEVATION_M, 0)
    // Challenger Deep and Everest both sit inside it with room to spare.
    expect(MIN_ELEVATION_M).toBeLessThan(-10935)
    expect(MAX_ELEVATION_M).toBeGreaterThan(8848)
  })

  it('writes two bytes per texel, not four', () => {
    // Half the VRAM of an RGBA tile for exactly the same data, which is what decides how much of
    // the world stays resident on a school Chromebook.
    expect(heightTextureFromHeights(new Float32Array(64), 8)).toHaveLength(8 * 8 * 2)
  })

  it('clips nonsense rather than wrapping it into a mountain', () => {
    // Saturates at the ends of the fixed-point range, which is wider than the real one — what
    // matters is that an absurd value cannot wrap round into a plausible mountain.
    const high = heightTextureFromHeights(Float32Array.from([500000]), 1)
    const low = heightTextureFromHeights(Float32Array.from([-500000]), 1)
    expect(decodeHeightTexel(high[0], high[1])).toBeGreaterThanOrEqual(MAX_ELEVATION_M)
    expect(decodeHeightTexel(low[0], low[1])).toBeLessThanOrEqual(MIN_ELEVATION_M)
  })

  it('puts sea level close enough to zero that open water stays flat', () => {
    /**
     * The bias class that bit the baked normal map: flat ground encoded to byte 128 and decoded
     * with `texel*2-1` to 0.0039 rather than 0 — a constant tilt over every flat texel on earth,
     * measured as a whole 8-bit level of shading across open ocean. It survives every relative
     * check, because everything is wrong by the same amount.
     *
     * A height grid cannot have that failure in the same form, since nothing is centred on 128 —
     * but the round-trip still has to land near zero or every ocean texel gets a small slope. It
     * lands within a millimetre, which over 38 m of ground at z12 is a slope of 3e-5 degrees.
     */
    const packed = heightTextureFromHeights(Float32Array.from([0]), 1)
    expect(Math.abs(decodeHeightTexel(packed[0], packed[1]))).toBeLessThan(0.001)
  })

  it('never writes past the end of the texture, whatever it is handed', () => {
    // A size that disagrees with the array length is a caller bug, but it must not corrupt memory
    // beyond the tile — that reads back as NaN and looks like a decode fault.
    const bytes = heightTextureFromHeights(new Float32Array(16), 2)
    expect(bytes).toHaveLength(8)
    bytes.forEach((value) => expect(Number.isFinite(value)).toBe(true))
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

  it('produces two bytes per DEM pixel, at the source tile size', async () => {
    stubBrowserDecode(() => 250)
    const { data, bytes } = await decodeTerrainTile(new ArrayBuffer(8), { z: 6, x: 33, y: 21 })
    expect(lastImageDataSize).toEqual([SIZE, SIZE])
    expect(data).toHaveLength(SIZE * SIZE * 2)
    expect(bytes).toBe(SIZE * SIZE * 2)
  })

  it('carries the height through unchanged, whatever level the tile is at', async () => {
    /**
     * The decode must not depend on the tile's zoom. It used to: normals were baked here and scaled
     * by the tile's ground resolution. Now the tile is raw height and the scaling happens in the
     * shader, which is what lets a single tile serve both relief and the ocean.
     */
    stubBrowserDecode(() => -3200)
    const { data: coarse } = await decodeTerrainTile(new ArrayBuffer(8), { z: 4, x: 8, y: 5 })
    const { data: fine } = await decodeTerrainTile(new ArrayBuffer(8), { z: 11, x: 1050, y: 670 })
    const middle = (bytes) => decodeHeightTexel(bytes[(4 * SIZE + 4) * 2], bytes[(4 * SIZE + 4) * 2 + 1])
    expect(middle(coarse)).toBeCloseTo(-3200, 0)
    expect(middle(fine)).toBeCloseTo(middle(coarse), 6)
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

  it('asks for a two-channel texture, because height needs sixteen bits and no more', () => {
    expect(TERRARIUM_TERRAIN.channels).toBe('rg')
  })

  it('fills a tile into its url template', () => {
    expect(tileUrl(TERRARIUM_TERRAIN, { z: 7, x: 65, y: 42 }))
      .toBe('https://s3.amazonaws.com/elevation-tiles-prod/terrarium/7/65/42.png')
  })
})
