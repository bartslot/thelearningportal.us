import { describe, it, expect } from 'vitest'
import {
  decodeTerrarium,
  equirectHeightGrid,
  normalsFromHeights,
  encodeNormals,
  equirectMetresPerPixel,
  reliefDetailFor,
  RELIEF_GLSL,
  clampTerrainHeight,
  heightsAboveSeaLevel,
  MIN_ELEVATION_M,
  MAX_ELEVATION_M,
} from '../terrain-normals.js'

/**
 * The bake and the shader have to agree about three conventions, and every disagreement between
 * them is silent — the globe still renders, the mountains simply lean the wrong way:
 *
 *  - WHICH WAY IS UP in the terrarium encoding (an off-by-32768 turns Everest into a trench).
 *  - WHICH WAY THE GREEN CHANNEL POINTS. The classic normal-map bug. Here +G is SOUTH, matching
 *    the direction equirectangular v increases, so the shader's tangent basis needs no flip.
 *  - THAT A DEGREE OF LONGITUDE IS NOT A DEGREE. The same height step across one pixel is a much
 *    steeper slope at 70°N than at the equator, because the pixel is narrower there. Skip the
 *    cos(lat) and the poles come out suspiciously gentle.
 */

const gridOf = (width, height, fn) => {
  const out = new Float32Array(width * height)
  for (let j = 0; j < height; j++) for (let i = 0; i < width; i++) out[j * width + i] = fn(i, j)
  return out
}

describe('terrarium decoding', () => {
  it('reads height as (R*256 + G + B/256) - 32768 metres', () => {
    expect(decodeTerrarium(128, 0, 0)).toBe(0)
    expect(decodeTerrarium(128, 100, 0)).toBe(100)
    expect(decodeTerrarium(127, 156, 0)).toBe(-100)
  })

  it('resolves the fractional byte, so gentle ground is not a staircase', () => {
    // Without B the smallest step is a whole metre, and a floodplain becomes visible terracing.
    expect(decodeTerrarium(128, 0, 128)).toBeCloseTo(0.5, 6)
  })

  it('puts the deepest ocean and the highest peak inside the encodable range', () => {
    expect(decodeTerrarium(0, 0, 0)).toBe(-32768)
    expect(decodeTerrarium(255, 255, 255)).toBeGreaterThan(8848)
  })
})

describe('the range a decoded height is allowed to keep', () => {
  /**
   * The relief bake throws the sea floor away, and is right to — you cannot see it through three
   * kilometres of water. But the OCEAN layer is built on exactly that discarded half, so the
   * depths have to survive the shared decode and be dropped by each reader separately.
   *
   * The shared loader used to clamp at -1000 m, which flattens about two thirds of the ocean by
   * area. Nothing noticed, because every reader at the time wanted land.
   */
  it('keeps the deep ocean, which another reader is built on', () => {
    expect(clampTerrainHeight(-3800)).toBe(-3800)     // mid-Atlantic abyssal plain
    expect(clampTerrainHeight(-10935)).toBe(-10935)   // Challenger Deep
  })

  it('keeps the highest ground', () => {
    expect(clampTerrainHeight(8849)).toBe(8849)
  })

  it('rejects values no point on earth can have', () => {
    expect(clampTerrainHeight(-40000)).toBe(MIN_ELEVATION_M)
    expect(clampTerrainHeight(30000)).toBe(MAX_ELEVATION_M)
  })

  it('stays inside a 16-bit grid, which is what stores it', () => {
    expect(MIN_ELEVATION_M).toBeGreaterThan(-32768)
    expect(MAX_ELEVATION_M).toBeLessThan(32767)
  })

  it('flattens water BEFORE the grid is averaged down, or the coast loses its mountains', () => {
    /**
     * The ordering trap. A coastal texel covering half a 2,000 m massif and half a 4,000 m trench
     * averages to -1,000 m while the depths are still signed; clamp that afterwards and it is
     * zero, and a mountain range that runs to the sea has quietly gone flat.
     *
     * This hid for as long as the shared DEM floor was -1,000 m — the error was there, just small
     * enough to pass as coastline softness. Restoring true ocean depth made it four times worse.
     */
    const source = gridOf(2, 2, (i) => (i === 0 ? 2000 : -4000))

    const clampedFirst = equirectHeightGrid(heightsAboveSeaLevel(source), 2, 1, 1)
    expect(clampedFirst[0]).toBeCloseTo(1000, 6)

    const clampedAfter = Math.max(0, equirectHeightGrid(source, 2, 1, 1)[0])
    expect(clampedAfter).toBe(0)     // the massif, gone
  })
})

describe('mercator to equirectangular resampling', () => {
  // A source grid whose value is its own mercator row, so the mapping is readable in the output.
  const source = (size) => gridOf(size, size, (_i, j) => j)

  it('keeps a constant field constant — no drift from the projection change', () => {
    const out = equirectHeightGrid(gridOf(64, 64, () => 42), 64, 8, 4)
    for (const v of out) expect(v).toBeCloseTo(42, 4)
  })

  it('lands the equator on the middle of the mercator square', () => {
    const size = 64
    const out = equirectHeightGrid(source(size), size, 8, 8)
    // Output rows 3 and 4 straddle the equator; the source row there is size/2.
    const above = out[3 * 8]
    const below = out[4 * 8]
    expect(above).toBeLessThan(size / 2)
    expect(below).toBeGreaterThan(size / 2)
    // Straddling rows average back to the middle, give or take the half-texel the row centres sit
    // off the boundary. A projection sign error would put this at a quarter or three quarters.
    expect(Math.abs((above + below) / 2 - size / 2)).toBeLessThan(1.5)
  })

  it('runs monotonically north to south, so nothing is mirrored', () => {
    const out = equirectHeightGrid(source(64), 64, 8, 16)
    for (let j = 1; j < 16; j++) expect(out[j * 8]).toBeGreaterThan(out[(j - 1) * 8])
  })

  it('fills the polar caps mercator never reaches rather than leaving them empty', () => {
    // Mercator stops at ±85.05°. An output row at 89° has no source rows at all; it has to
    // inherit the nearest one, or Antarctica's interior comes back as a 4 km hole.
    const out = equirectHeightGrid(gridOf(64, 64, () => 2500), 64, 8, 180)
    expect(out[0]).toBeCloseTo(2500, 4)                    // 89.5°N
    expect(out[179 * 8]).toBeCloseTo(2500, 4)              // 89.5°S
  })
})

describe('normals from an equirectangular height grid', () => {
  const flat = (w, h) => new Float32Array(w * h)

  it('gives straight up over flat ground', () => {
    const n = normalsFromHeights(flat(16, 8), 16, 8)
    for (let k = 0; k < 16 * 8; k++) {
      expect(n[k * 3 + 0]).toBeCloseTo(0, 6)
      expect(n[k * 3 + 1]).toBeCloseTo(0, 6)
      expect(n[k * 3 + 2]).toBeCloseTo(1, 6)
    }
  })

  it('tilts the normal WEST where the ground rises to the east', () => {
    const w = 64, h = 32
    const heights = gridOf(w, h, (i) => i * 100)
    const n = normalsFromHeights(heights, w, h)
    const at = (i, j) => n.subarray((j * w + i) * 3, (j * w + i) * 3 + 3)
    // Mid-grid, away from the wrap seam and the poles.
    expect(at(32, 16)[0]).toBeLessThan(0)
    expect(at(32, 16)[1]).toBeCloseTo(0, 6)
  })

  it('tilts the normal NORTH where the ground rises to the south (+G is south)', () => {
    const w = 64, h = 32
    const heights = gridOf(w, h, (_i, j) => j * 100)
    const n = normalsFromHeights(heights, w, h)
    const k = (16 * w + 32) * 3
    expect(n[k + 1]).toBeLessThan(0)
    expect(n[k + 0]).toBeCloseTo(0, 6)
  })

  it('makes the same height step a steeper slope at high latitude', () => {
    // One pixel of longitude is 3.4x narrower at 70°N than at the equator, so the same rise over
    // it is a much steeper slope. This is the term that is easy to leave out and impossible to
    // see afterwards.
    const w = 360, h = 180
    const heights = gridOf(w, h, (i) => i * 100)
    const n = normalsFromHeights(heights, w, h)
    const tiltAt = (j) => Math.abs(n[(j * w + 180) * 3])
    const equator = tiltAt(90)   // 0.5°N
    const high = tiltAt(20)      // 70.5°N
    expect(high).toBeGreaterThan(equator * 2)
  })

  it('wraps at the antimeridian instead of flattening a stripe down the Pacific', () => {
    const w = 64, h = 32
    // A field that is genuinely continuous across the seam: one full cosine cycle of longitude.
    const heights = gridOf(w, h, (i) => 1000 * Math.cos((i / w) * 2 * Math.PI))
    const n = normalsFromHeights(heights, w, h)
    const seam = Math.abs(n[(16 * w + 0) * 3])
    const inland = Math.abs(n[(16 * w + 32) * 3])
    // Both sit on the cosine's flat crest/trough, so both should be near zero and equal — a
    // non-wrapping difference would make the seam column read a full cycle of slope.
    expect(seam).toBeCloseTo(inland, 6)
  })

  it('treats the sea floor as flat water, not as terrain', () => {
    /**
     * If you are here because this test failed, it is guarding two separate things and you need
     * both before changing it.
     *
     * MEASURED: terrarium carries bathymetry, and shading it draws mid-ocean ridges onto the
     * surface of the Atlantic — the "bathymetric chart, not water" failure the satellite source
     * already avoids by using the plain shaded-relief variant.
     *
     * INSTRUCTED: Bart ruled on it directly, looking at rendered sea-floor structure — "why is
     * there a normal map for the sea bed. i don't want that. it's not realistic if you look from
     * space to the real earth." A product decision, not an inference.
     *
     * Depth still drives the ocean layer's COLOUR. What is excluded is shading read off the floor.
     */
    const w = 32, h = 16
    const heights = gridOf(w, h, (i) => -1000 * i)
    const n = normalsFromHeights(heights, w, h)
    const k = (8 * w + 16) * 3
    expect(n[k + 0]).toBeCloseTo(0, 6)
    expect(n[k + 1]).toBeCloseTo(0, 6)
    expect(n[k + 2]).toBeCloseTo(1, 6)
  })

  it('does not manufacture cliffs at the poles out of an oversampled grid', () => {
    /**
     * The equirectangular grid crowds its columns together toward the poles: at 89.9°N two
     * neighbouring texels are thirty metres apart on the ground, while the DEM they were resampled
     * from is nineteen kilometres coarse. Measure the slope between them and Greenland's ice edge —
     * a real three-kilometre step — comes out as an 87° wall ringing the Arctic, which is what the
     * first bake of this map actually produced.
     *
     * The slope has to be measured over a fixed distance of GROUND, not over one texel, so the
     * stencil widens as the texels narrow.
     */
    const w = 512, h = 256
    // One continent edge, identical at every latitude: 3 km of land meeting sea down a meridian.
    const heights = gridOf(w, h, (i) => (i < w / 2 ? 3000 : 0))
    const n = normalsFromHeights(heights, w, h)
    const maxTiltInRow = (j) => {
      let worst = 0
      for (let i = 0; i < w; i++) worst = Math.max(worst, Math.abs(n[(j * w + i) * 3]))
      return worst
    }
    const equator = maxTiltInRow(h / 2)
    expect(equator).toBeGreaterThan(0)
    // The same edge, so the same order of slope — not five hundred times it.
    expect(maxTiltInRow(0)).toBeLessThan(equator * 3)
    expect(maxTiltInRow(h - 1)).toBeLessThan(equator * 3)
  })

  it('returns unit normals, so the shader can trust them without renormalising', () => {
    const w = 32, h = 16
    const heights = gridOf(w, h, (i, j) => Math.sin(i) * 800 + Math.cos(j) * 600)
    const n = normalsFromHeights(heights, w, h)
    for (let k = 0; k < w * h; k++) {
      const [x, y, z] = n.subarray(k * 3, k * 3 + 3)
      expect(Math.hypot(x, y, z)).toBeCloseTo(1, 5)
      expect(z).toBeGreaterThan(0)   // no overhangs on a height field
    }
  })
})

describe('encoding to bytes', () => {
  it('round-trips a normal through 8 bits to within half a level', () => {
    const n = new Float32Array([0, 0, 1, 0.6, -0.8, 0, -0.5, 0.5, Math.SQRT1_2])
    const bytes = encodeNormals(n)
    for (let k = 0; k < 3; k++) {
      for (let c = 0; c < 3; c++) {
        const decoded = (bytes[k * 3 + c] / 255) * 2 - 1
        expect(decoded).toBeCloseTo(n[k * 3 + c], 2)
      }
    }
  })

  it('puts flat ground on the byte the shader decodes as exactly zero tilt', () => {
    // The encoder and the shader have to share a centre. 128 under the usual texel*2-1 decodes to
    // 0.0039, and that residue is applied to every flat texel on earth — a constant tilt over
    // every ocean, which is a constant brightness offset. Measured, it moved open water by a whole
    // 8-bit level. So the shader subtracts THIS number, and this test pins the two together.
    const bytes = encodeNormals(new Float32Array([0, 0, 1]))
    expect(bytes[0]).toBe(128)
    expect(bytes[1]).toBe(128)
    expect(bytes[2]).toBe(255)
    expect(RELIEF_GLSL).toContain((128 / 255).toFixed(8))
  })
})

describe('how much ground a texel covers', () => {
  it('measures the equator texel from the earth\'s circumference', () => {
    // Mean radius, matching EARTH_RADIUS_M — the same number clouds.js measures its own field with.
    expect(Math.round(equirectMetresPerPixel(2048, 0))).toBe(19546)
    expect(Math.round(equirectMetresPerPixel(8192, 0))).toBe(4887)
  })

  it('narrows toward the poles with the meridians', () => {
    expect(equirectMetresPerPixel(8192, 60)).toBeCloseTo(equirectMetresPerPixel(8192, 0) / 2, 0)
  })
})

describe('when the relief map runs out of pixels', () => {
  /**
   * Retuned against measurement rather than intuition. The first curve used the MERCATOR scale to
   * decide how magnified the map was, and MapLibre's globe draws at exactly half that — so it was
   * wrong by a whole LOD level, and it retired an effect that still had full amplitude and a fifth
   * of its detail left.
   */
  const WIDTH = 8192

  it('reports magnification in the tiling system\'s own metric', () => {
    // 8192 wide is 4,887 m per texel at the equator, and the globe at z4 is 4,892 m per device
    // pixel — so z4 is where one texel covers one pixel, and each zoom doubles it from there.
    expect(reliefDetailFor(4, 0, WIDTH).pixelsPerTexel).toBeCloseTo(1, 1)
    expect(reliefDetailFor(6, 0, WIDTH).pixelsPerTexel).toBeCloseTo(4, 1)
    expect(reliefDetailFor(8, 0, WIDTH).pixelsPerTexel).toBeCloseTo(16, 1)
  })

  it('runs at full strength while the globe is the picture', () => {
    expect(reliefDetailFor(0, 0, WIDTH).strength).toBeCloseTo(1, 5)
    expect(reliefDetailFor(4, 0, WIDTH).strength).toBeCloseTo(1, 5)
  })

  it('keeps going well past one texel per pixel, because amplitude does not decay', () => {
    // Measured: amplitude is 11.5 of 255 at one pixel per texel and still 11.5 at eight. Only the
    // crispness trades. Retiring here would throw away a working effect.
    expect(reliefDetailFor(6, 0, WIDTH).strength).toBeCloseTo(1, 5)
    expect(reliefDetailFor(7, 0, WIDTH).strength).toBeCloseTo(1, 5)
  })

  it('has let go once a texel covers thirty-two pixels', () => {
    // z9 lands a hair inside the taper's end rather than exactly on it — the texel is 4,886.5 m,
    // not a round 4,892 — so this is "gone" rather than identically zero. Past that it is exact.
    expect(reliefDetailFor(9, 0, WIDTH).strength).toBeLessThan(0.01)
    expect(reliefDetailFor(11, 0, WIDTH).strength).toBe(0)
  })

  it('fades rather than switching off, so the terminator never pops', () => {
    const mid = reliefDetailFor(8, 0, WIDTH).strength
    expect(mid).toBeGreaterThan(0)
    expect(mid).toBeLessThan(1)
  })

  it('holds on longer for a wider map, because a wider map has the pixels', () => {
    expect(reliefDetailFor(9, 0, 16384).strength).toBeGreaterThan(reliefDetailFor(9, 0, 4096).strength)
  })

  it('fades at the same zoom at every latitude — screen and texel shrink together', () => {
    // Both the screen scale and the equirectangular texel carry the same cos(lat), so the ratio
    // that drives the fade does not. A fade that moved with latitude would mean one of the two
    // had lost its cosine.
    expect(reliefDetailFor(8, 60, WIDTH).strength).toBeCloseTo(reliefDetailFor(8, 0, WIDTH).strength, 6)
  })
})
