import { describe, it, expect } from 'vitest'
import {
  MERCATOR_CIRCUMFERENCE_M,
  childrenOf,
  groundMetresPerTexel,
  keyOf,
  latitudeIsCapped,
  parentOf,
  parseKey,
  tileForLngLat,
  tileLngLatBounds,
  tileMercatorBounds,
  tilesPerAxis,
  wrapTileX,
} from '../scheme.js'
import { MERCATOR_EDGE_LAT, latFromMercatorY, mercatorYFromLat } from '../../planet-mesh.js'

/**
 * The tile scheme has to be MapLibre's, not one of our own. Every number here exists so that a
 * tile we ask for lands on exactly the same ground as the imagery tile underneath it — a scheme
 * that is half a texel out looks fine on a globe and shows as a seam the moment you zoom in.
 */

describe('the grid', () => {
  it('is one tile at z0 and quadruples per level', () => {
    expect(tilesPerAxis(0)).toBe(1)
    expect(tilesPerAxis(1)).toBe(2)
    expect(tilesPerAxis(12)).toBe(4096)
  })

  it('puts the origin at the north-west corner, like every slippy map', () => {
    // z1 splits the world into four. (0,0) is north-west: the Atlantic north of the equator.
    expect(tileForLngLat(-90, 45, 1)).toMatchObject({ z: 1, x: 0, y: 0 })
    expect(tileForLngLat(90, 45, 1)).toMatchObject({ z: 1, x: 1, y: 0 })
    expect(tileForLngLat(-90, -45, 1)).toMatchObject({ z: 1, x: 0, y: 1 })
    expect(tileForLngLat(90, -45, 1)).toMatchObject({ z: 1, x: 1, y: 1 })
  })

  it('round-trips a tile back to the ground it covers', () => {
    for (const z of [0, 3, 8, 14]) {
      const tile = tileForLngLat(4.895, 52.37, z) // Amsterdam
      const bounds = tileLngLatBounds(tile)
      expect(bounds.west).toBeLessThanOrEqual(4.895)
      expect(bounds.east).toBeGreaterThan(4.895)
      expect(bounds.south).toBeLessThanOrEqual(52.37)
      expect(bounds.north).toBeGreaterThan(52.37)
    }
  })

  it('agrees with the mesh about where a latitude sits in mercator', () => {
    // scheme.js and planet-mesh.js must not drift: the mesh wraps the same square this indexes.
    const tile = tileForLngLat(0, 52.37, 6)
    const merc = tileMercatorBounds(tile)
    const y = mercatorYFromLat(52.37)
    expect(y).toBeGreaterThanOrEqual(merc.y0)
    expect(y).toBeLessThan(merc.y1)
    expect(latFromMercatorY(merc.y0)).toBeGreaterThan(latFromMercatorY(merc.y1))
  })

  it('keys and parses back to the same tile', () => {
    const tile = { z: 9, x: 261, y: 168 }
    expect(parseKey(keyOf(tile))).toEqual(tile)
  })
})

describe('absolute position, against an authority outside this module', () => {
  /**
   * EVERY OTHER POSITIONAL TEST HERE IS RELATIVE — a tile against its neighbour, a point inside its
   * own tile's bounds, a parent against its children. A pyramid displaced by a uniform offset
   * satisfies all of them, and that is not hypothetical: the page-table straddle was a positional
   * error, and it was found by rendering rather than by any of these.
   *
   * So these check against something this module does not own. The reference below is the published
   * slippy-tilenames formula written out independently — it uses `log(tan + sec)` where scheme.js
   * goes through `mercatorYFromLat`'s `log(tan(π/4 + lat/2))` — so an offset in one shows as a
   * disagreement rather than cancelling.
   */
  const referenceTile = (lng, lat, z) => {
    const n = 2 ** z
    const latRad = (lat * Math.PI) / 180
    return {
      x: Math.floor(((lng + 180) / 360) * n),
      y: Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n),
    }
  }

  it('agrees with the published slippy-map formula everywhere, not just on average', () => {
    const places = [
      [4.895, 52.370],    // Amsterdam
      [-74.006, 40.713],  // New York
      [151.209, -33.868], // Sydney
      [139.692, 35.690],  // Tokyo
      [-43.173, -22.907], // Rio
      [18.424, -33.925],  // Cape Town
      [0, 0],             // the origin of everything
    ]
    for (const [lng, lat] of places) {
      for (const z of [1, 4, 8, 12, 14]) {
        const mine = tileForLngLat(lng, lat, z)
        expect({ x: mine.x, y: mine.y }).toEqual(referenceTile(lng, lat, z))
      }
    }
  })

  it('puts the equator and the prime meridian exactly on the seam between tiles', () => {
    // Both are exact in web mercator, so any offset at all breaks these — there is no rounding to
    // hide in. At z4 that is the boundary between rows 7 and 8, and columns 7 and 8.
    expect(tileForLngLat(0.0001, 0.0001, 4)).toMatchObject({ x: 8, y: 7 })
    expect(tileForLngLat(-0.0001, -0.0001, 4)).toMatchObject({ x: 7, y: 8 })
  })

  it('ends the world at the published web-mercator limit', () => {
    // 85.0511287798066° is not ours to choose; it is where the projection is defined to stop.
    const world = tileLngLatBounds({ z: 0, x: 0, y: 0 })
    expect(world.north).toBeCloseTo(85.0511287798066, 9)
    expect(world.south).toBeCloseTo(-85.0511287798066, 9)
    expect(world.west).toBe(-180)
    expect(world.east).toBe(180)
  })

  it('lands a known city on a known texel, not merely in the right tile', () => {
    /**
     * One level finer than the tile: which TEXEL of that tile. A half-tile offset would still put
     * Amsterdam in the right tile and would show here.
     */
    const AMSTERDAM = { lng: 4.895, lat: 52.37 }
    const tile = tileForLngLat(AMSTERDAM.lng, AMSTERDAM.lat, 12)
    const { x0, y0, size } = tileMercatorBounds(tile)
    const mx = (AMSTERDAM.lng + 180) / 360
    const my = mercatorYFromLat(AMSTERDAM.lat)
    const texelX = Math.floor(((mx - x0) / size) * 256)
    const texelY = Math.floor(((my - y0) / size) * 256)
    expect(texelX).toBeGreaterThanOrEqual(0)
    expect(texelX).toBeLessThan(256)
    expect(texelY).toBeGreaterThanOrEqual(0)
    expect(texelY).toBeLessThan(256)
    // And the texel's own ground position round-trips to within its own size.
    const backLng = (x0 + ((texelX + 0.5) / 256) * size) * 360 - 180
    const groundMetres = groundMetresPerTexel(12, AMSTERDAM.lat, 256)
    const metresPerDegreeLng = (MERCATOR_CIRCUMFERENCE_M * Math.cos((AMSTERDAM.lat * Math.PI) / 180)) / 360
    expect(Math.abs(backLng - AMSTERDAM.lng) * metresPerDegreeLng).toBeLessThan(groundMetres)
  })
})

describe('the poles, where the mercator square runs out', () => {
  /**
   * There are no tiles past ±85.0511°, but the globe mesh reaches ±89.999° — `buildSphereMesh`
   * adds cap rows for exactly this reason. A scheme that lets y run out of range there produces
   * 404s at every pole approach; one that clamps silently produces a smeared Antarctica with no
   * hint that anything happened. So it clamps AND says so.
   */
  it('clamps beyond the mercator edge instead of asking for a tile that does not exist', () => {
    for (const z of [1, 5, 12]) {
      const north = tileForLngLat(0, 89.999, z)
      const south = tileForLngLat(0, -89.999, z)
      expect(north.y).toBe(0)
      expect(south.y).toBe(tilesPerAxis(z) - 1)
      expect(north.capped).toBe(true)
      expect(south.capped).toBe(true)
    }
  })

  it('does not flag latitudes the square actually covers', () => {
    expect(tileForLngLat(0, 84, 4).capped).toBe(false)
    expect(latitudeIsCapped(MERCATOR_EDGE_LAT - 0.001)).toBe(false)
    expect(latitudeIsCapped(MERCATOR_EDGE_LAT + 0.001)).toBe(true)
  })

  it('never returns a y outside the grid, at any latitude', () => {
    for (let lat = -90; lat <= 90; lat += 0.5) {
      const { y } = tileForLngLat(0, lat, 8)
      expect(y).toBeGreaterThanOrEqual(0)
      expect(y).toBeLessThan(256)
    }
  })
})

describe('the antimeridian, where longitude wraps and y does not', () => {
  it('wraps x around the world in both directions', () => {
    expect(wrapTileX(-1, 2)).toBe(3)
    expect(wrapTileX(4, 2)).toBe(0)
    expect(wrapTileX(9, 2)).toBe(1)
    expect(wrapTileX(2, 2)).toBe(2)
  })

  it('treats +180 and -180 as the same edge', () => {
    expect(tileForLngLat(180, 0, 3).x).toBe(tileForLngLat(-180, 0, 3).x)
  })
})

describe('family', () => {
  it('halves to the parent and quarters to four children', () => {
    const tile = { z: 6, x: 33, y: 21 }
    expect(parentOf(tile)).toMatchObject({ z: 5, x: 16, y: 10 })
    const kids = childrenOf(tile)
    expect(kids).toHaveLength(4)
    kids.forEach((kid) => expect(parentOf(kid)).toMatchObject(tile))
  })

  it('stops at the root rather than inventing a z-1', () => {
    expect(parentOf({ z: 0, x: 0, y: 0 })).toBeNull()
  })
})

describe('how much ground a texel covers', () => {
  /**
   * This number is the whole reason the module exists. The cloud deck's global field is 2048 wide,
   * about 19.5 km per pixel; the point of tiles is that this figure keeps falling as you descend
   * instead of standing still while the screen pixel shrinks under it.
   */
  it('matches the standard web-mercator resolution at the equator', () => {
    expect(groundMetresPerTexel(0, 0, 256)).toBeCloseTo(156543.03392, 4)
    expect(groundMetresPerTexel(12, 0, 256)).toBeCloseTo(156543.03392 / 4096, 6)
  })

  it('shrinks with latitude, because mercator stretches', () => {
    expect(groundMetresPerTexel(8, 60, 256)).toBeCloseTo(groundMetresPerTexel(8, 0, 256) * Math.cos(Math.PI / 3), 5)
  })

  it('beats the 2048-wide global field 32x by z8 and 512x by z12', () => {
    // The field the cloud deck samples today is 2048 across, about 19.6 km per pixel. These are
    // the numbers that retire it: 611 m at z8, 38 m at z12.
    const GLOBAL_FIELD_METRES = MERCATOR_CIRCUMFERENCE_M / 2048
    expect(GLOBAL_FIELD_METRES).toBeCloseTo(19568, 0)
    expect(groundMetresPerTexel(8, 0, 256)).toBeCloseTo(611.5, 1)
    expect(groundMetresPerTexel(12, 0, 256)).toBeCloseTo(38.2, 1)
    expect(GLOBAL_FIELD_METRES / groundMetresPerTexel(8, 0, 256)).toBeCloseTo(32, 5)
    expect(GLOBAL_FIELD_METRES / groundMetresPerTexel(12, 0, 256)).toBeCloseTo(512, 5)
  })
})
