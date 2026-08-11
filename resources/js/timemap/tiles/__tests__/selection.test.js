import { describe, it, expect } from 'vitest'
import { magnificationOf, pixelsPerTexel, selectTiles } from '../selection.js'
import { keyOf, tilesPerAxis } from '../scheme.js'

/**
 * Selection is the part that actually fixes the problem. A global texture has one level and gets
 * magnified; a pyramid picks the level where a texel is about the size of a screen pixel, and the
 * measure of "about" is the screen-space error.
 *
 * These tests pin the behaviour a naive zoom→level table would get wrong: the limb of the globe,
 * the antimeridian, the poles, and running out of source levels.
 */

const camera = (over = {}) => ({
  center: { lng: 0, lat: 0 },
  zoom: 2,
  canvasWidth: 1200,
  canvasHeight: 800,
  projection: 'globe',
  ...over,
})

const DEM = { minzoom: 0, maxzoom: 12, tileSize: 256 }

describe('the error metric', () => {
  /**
   * The anchor. Flat, overhead, no projection tricks: MapLibre's zoom is defined on 512-texel
   * tiles, so a 256-texel source needs exactly one level MORE than the zoom to put one texel on
   * one pixel. Every clever thing below has to keep agreeing with this.
   */
  it('puts one texel on one pixel at zoom+1 for a 256-texel source', () => {
    expect(pixelsPerTexel({ z: 3, x: 4, y: 4 }, camera({ zoom: 2, projection: 'mercator' }), DEM)).toBeCloseTo(1, 6)
    expect(pixelsPerTexel({ z: 9, x: 256, y: 256 }, camera({ zoom: 8, projection: 'mercator' }), DEM)).toBeCloseTo(1, 6)
  })

  it('halves per level, so a coarse tile is the blurry one', () => {
    const view = camera({ zoom: 8, projection: 'mercator' })
    const fine = pixelsPerTexel({ z: 9, x: 256, y: 256 }, view, DEM)
    const coarse = pixelsPerTexel({ z: 8, x: 128, y: 128 }, view, DEM)
    expect(coarse).toBeCloseTo(fine * 2, 6)
  })

  it('needs one level less from a 512-texel source, for the same picture', () => {
    const view = camera({ zoom: 6, projection: 'mercator' })
    const from256 = pixelsPerTexel({ z: 7, x: 64, y: 64 }, view, { ...DEM, tileSize: 256 })
    const from512 = pixelsPerTexel({ z: 6, x: 32, y: 32 }, view, { ...DEM, tileSize: 512 })
    expect(from512).toBeCloseTo(from256, 6)
  })

  it('foreshortens toward the limb of the globe, where a naive zoom table over-refines', () => {
    /**
     * The whole reason this is a metric and not a lookup. A tile near the edge of the visible
     * hemisphere is seen almost edge-on and lands on a fraction of the pixels the same tile would
     * get at the centre — refining it to the same level is detail nobody can see, paid for in
     * bandwidth and atlas slots.
     */
    const view = camera({ zoom: 3, center: { lng: 0, lat: 0 } })
    const level = 4
    const span = tilesPerAxis(level)
    const middle = { z: level, x: span / 2, y: span / 2 }
    const nearLimb = { z: level, x: Math.round(span * 0.32), y: span / 2 }
    expect(pixelsPerTexel(nearLimb, view, DEM)).toBeLessThan(pixelsPerTexel(middle, view, DEM))
  })
})

describe('a source that is not tiled at all', () => {
  /**
   * The cloud deck's field is one 2048-wide equirectangular texture. It is not in the pyramid and
   * never will be — real cloud fraction is dated weather data, and 2026's weather over a 1642 map
   * is the anachronism this project deleted four drawn atlases to avoid.
   *
   * But the deck still needs to know how magnified that texture is, so it can hand the work over to
   * procedural noise. Describing it as a one-level source gets that number out of the same
   * arithmetic, honestly, instead of the hardcoded `FIELD_METRES_PER_PIXEL` it guesses with today.
   *
   * `selectTiles` fetches nothing and touches no GL, so this costs nothing and streams nothing.
   */
  const CLOUD_FIELD = { minzoom: 0, maxzoom: 0, tileSize: 2048 }

  it('reports how magnified a single global texture is, without streaming anything', () => {
    const close = magnificationOf(camera({ zoom: 11 }), CLOUD_FIELD)
    const far = magnificationOf(camera({ zoom: 2 }), CLOUD_FIELD)
    expect(close).toBeGreaterThan(far)
    expect(far).toBeLessThan(1.5)      // at globe zoom a 2048 field is about right
    expect(close).toBeGreaterThan(10)  // by z11 it is a smooth wash
  })

  it('is a supported descriptor, not something that happens to work', () => {
    const report = selectTiles(camera({ zoom: 9 }), CLOUD_FIELD, { maxTiles: 64 })
    expect(report.tiles).toHaveLength(1)
    expect(report.maxLevel).toBe(0)
    // Starved from the first frame, because there is no finer level to ask for — and the AMOUNT is
    // what a deck needs, since it wants to fade rather than switch.
    expect(report.starved).toBe(true)
    expect(report.worstPixelsPerTexel).toBeGreaterThan(1)
  })

  it('agrees with the selection it would have made', () => {
    const view = camera({ zoom: 7 })
    const report = selectTiles(view, CLOUD_FIELD, { maxTiles: 64 })
    expect(magnificationOf(view, CLOUD_FIELD)).toBeCloseTo(report.worstPixelsPerTexel, 6)
  })
})

describe('choosing the set', () => {
  it('refines until a texel is about a pixel, not further', () => {
    const view = camera({ zoom: 8, center: { lng: 4.9, lat: 52.4 }, projection: 'mercator' })
    const { tiles } = selectTiles(view, DEM, { targetPixelsPerTexel: 1, maxTiles: 128 })
    expect(tiles.length).toBeGreaterThan(0)
    tiles.forEach((tile) => {
      // Either it is sharp enough, or it has run out of source levels.
      expect(pixelsPerTexel(tile, view, DEM) <= 1.0001 || tile.z === DEM.maxzoom).toBe(true)
    })
  })

  it('picks a finer level as the camera descends — the thing a single global texture cannot do', () => {
    const levelAt = (zoom) => {
      const view = camera({ zoom, center: { lng: 4.9, lat: 52.4 } })
      const { tiles } = selectTiles(view, DEM, { maxTiles: 96 })
      return Math.max(...tiles.map((t) => t.z))
    }
    const levels = [2, 5, 8, 11].map(levelAt)
    expect(levels[0]).toBeLessThan(levels[1])
    expect(levels[1]).toBeLessThan(levels[2])
    expect(levels[2]).toBeLessThan(levels[3])
  })

  it('stays inside its tile budget', () => {
    const view = camera({ zoom: 14, center: { lng: 4.9, lat: 52.4 } })
    for (const maxTiles of [8, 32, 100]) {
      const { tiles } = selectTiles(view, DEM, { maxTiles })
      expect(tiles.length).toBeLessThanOrEqual(maxTiles)
    }
  })

  it('stays inside its budget even when the source\'s coarsest level already overflows it', () => {
    // A single-level source hands back its whole visible grid as the starting set, and the
    // refinement loop never gets to run — so it only ever checked the budget on the way down.
    // Measured at 77 tiles against a budget of 64 before this.
    const single = { minzoom: 10, maxzoom: 10, tileSize: 256 }
    const view = camera({ zoom: 9, center: { lng: 7.9, lat: 46.0 } })
    const { tiles } = selectTiles(view, single, { maxTiles: 16 })
    expect(tiles.length).toBeLessThanOrEqual(16)
    expect(tiles.length).toBeGreaterThan(0)
  })

  it('never asks for a level the source does not publish', () => {
    const view = camera({ zoom: 16, center: { lng: 4.9, lat: 52.4 } })
    const { tiles, starved } = selectTiles(view, DEM, { maxTiles: 64 })
    tiles.forEach((tile) => {
      expect(tile.z).toBeLessThanOrEqual(DEM.maxzoom)
      expect(tile.z).toBeGreaterThanOrEqual(DEM.minzoom)
    })
    // Past the source's last level the pyramid is out of answers, and it says so rather than
    // pretending. This is the signal clouds.js should hand its procedural noise, instead of
    // guessing at the field's resolution.
    expect(starved).toBe(true)
  })

  it('is not starved while the source still has levels in hand', () => {
    const view = camera({ zoom: 3, center: { lng: 4.9, lat: 52.4 } })
    expect(selectTiles(view, DEM, { maxTiles: 64 }).starved).toBe(false)
  })

  it('returns each tile once', () => {
    const view = camera({ zoom: 9, center: { lng: 4.9, lat: 52.4 } })
    const { tiles } = selectTiles(view, DEM, { maxTiles: 128 })
    expect(new Set(tiles.map(keyOf)).size).toBe(tiles.length)
  })

  it('drops the half of the globe facing away from the camera', () => {
    const view = camera({ zoom: 1, center: { lng: 0, lat: 0 } })
    const { tiles } = selectTiles(view, DEM, { maxTiles: 256 })
    // Nothing near the antipode of the camera centre — that ground is behind the planet.
    const antipodal = tiles.filter((t) => {
      const span = tilesPerAxis(t.z)
      const lng = ((t.x + 0.5) / span) * 360 - 180
      return Math.abs(Math.abs(lng) - 180) < 20
    })
    expect(antipodal).toHaveLength(0)
  })

  it('crosses the antimeridian without asking for a tile off the edge of the grid', () => {
    const view = camera({ zoom: 6, center: { lng: 179.7, lat: 0 } })
    const { tiles } = selectTiles(view, DEM, { maxTiles: 128 })
    tiles.forEach(({ z, x, y }) => {
      expect(x).toBeGreaterThanOrEqual(0)
      expect(x).toBeLessThan(tilesPerAxis(z))
      expect(y).toBeGreaterThanOrEqual(0)
      expect(y).toBeLessThan(tilesPerAxis(z))
    })
    // Ground on both sides of the seam is in view, so both edges of the grid must appear.
    const columns = new Set(tiles.map((t) => `${t.z}:${t.x}`))
    expect(columns.size).toBeGreaterThan(1)
  })

  it('survives the pole, where there are no tiles to select', () => {
    const view = camera({ zoom: 7, center: { lng: 0, lat: 89.9 } })
    const { tiles } = selectTiles(view, DEM, { maxTiles: 64 })
    tiles.forEach(({ z, y }) => {
      expect(y).toBeGreaterThanOrEqual(0)
      expect(y).toBeLessThan(tilesPerAxis(z))
    })
  })

  it('handles a source whose coarsest level is deep, without enumerating that level', () => {
    /**
     * Plenty of WMTS layers publish from z8 upward. Listing every tile at the source's minzoom is
     * the obvious way to start and it is 65,536 iterations per frame at z8, sixteen million at z12.
     * Walking down from z0 and culling costs only what is visible, so this finishes at all.
     */
    const view = camera({ zoom: 10, center: { lng: 4.9, lat: 52.4 } })
    const deep = { minzoom: 8, maxzoom: 12, tileSize: 256 }
    const started = Date.now()
    const { tiles } = selectTiles(view, deep, { maxTiles: 32 })
    expect(Date.now() - started).toBeLessThan(500)
    expect(tiles.length).toBeGreaterThan(0)
    tiles.forEach((tile) => expect(tile.z).toBeGreaterThanOrEqual(8))
  })

  it('reports what it chose, so the numbers can be measured rather than eyeballed', () => {
    const view = camera({ zoom: 8, center: { lng: 4.9, lat: 52.4 } })
    const report = selectTiles(view, DEM, { maxTiles: 64 })
    expect(report.maxLevel).toBe(Math.max(...report.tiles.map((t) => t.z)))
    expect(report.worstPixelsPerTexel).toBeGreaterThan(0)
    expect(report.tiles.length).toBe(report.tileCount)
  })
})
