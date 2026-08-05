import { describe, it, expect } from 'vitest'
import { buffer as turfBuffer, difference as turfDifference, featureCollection, polygon as turfPolygon, booleanPointInPolygon, point } from '@turf/turf'
import { unwrapTo } from '../voyage-fog.js'

/**
 * Fog of war across the antimeridian.
 *
 * Reported as "something is wrong at Tonga, it's completely black — does the tile load?". The tiles
 * loaded fine. Tasman's last leg sails past 180°, and the route is held in an unwrapped frame
 * (Tonga ≈ 184.8°E) — but turfBuffer projects through a wrapped [-180,180] one, so the sailed
 * corridor came back at -175.2° and the fog's difference cut its hole half a globe away. The ship
 * ended up parked under intact fog, which on the Satellite style is solid black.
 *
 * Legs west of the seam were never affected, which is why it survived every earlier voyage.
 */

const boxRing = (b) => [[b[0], b[1]], [b[2], b[1]], [b[2], b[3]], [b[0], b[3]], [b[0], b[1]]]
const lineOf = (coords) => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } })
const meanLng = (c) => c.reduce((s, p) => s + p[0], 0) / c.length
const lngsOf = (f) => f.geometry.coordinates.flat(2).filter((_, i) => i % 2 === 0)

// Tasman: Mauritius → Tasmania → New Zealand → Tonga, the last hop crossing 180°.
const TASMAN = [[57.5, -20.3], [110, -35], [147, -43], [170, -42], [174, -38], [178, -34], [182, -28], [184.8, -21.2]]
const TONGA = point([184.8, -21.2])
// The auto mask's world box: the route's bbox, in the same unwrapped frame.
const WORLD = turfPolygon([boxRing([10, -55, 200, 20])])

const corridorFor = (coords) => {
  const line = lineOf(coords)
  return unwrapTo(turfBuffer(line, 450, { units: 'kilometers' }), meanLng(line.geometry.coordinates))
}

describe('unwrapTo', () => {
  it('pulls a seam-crossing corridor back into the route frame', () => {
    const raw = turfBuffer(lineOf(TASMAN), 450, { units: 'kilometers' })
    // turf hands back a ring that wraps mid-way: it walks east to ~178 and reappears at ~-179.
    expect(Math.min(...lngsOf(raw))).toBeLessThan(-170)

    const fixed = unwrapTo(raw, meanLng(TASMAN))
    // Back in one piece around the route, with nothing left on the far side of the world.
    expect(Math.min(...lngsOf(fixed))).toBeGreaterThan(0)
    expect(Math.max(...lngsOf(fixed))).toBeGreaterThan(180)
  })

  it('leaves geometry that is already in frame untouched — safe over authored rings', () => {
    const poly = turfPolygon([boxRing([100, -30, 140, -10])])
    expect(unwrapTo(poly, 120)).toEqual(poly)
  })

  it('does not mutate the feature it is handed', () => {
    const poly = turfPolygon([boxRing([-179, -30, -175, -10])])
    const before = JSON.stringify(poly)
    unwrapTo(poly, 184)
    expect(JSON.stringify(poly)).toBe(before)
  })

  it('keeps a ring contiguous instead of folding it in half at the seam', () => {
    // A ring straddling 180°, written wrapped: +179 … -179 is 2° of longitude, not 358°.
    const straddling = turfPolygon([[[179, -20], [-179, -20], [-179, -22], [179, -22], [179, -20]]])
    const lngs = lngsOf(unwrapTo(straddling, 180))
    expect(Math.max(...lngs) - Math.min(...lngs)).toBeCloseTo(2, 6)
  })

  it('passes through geometry it has no business touching', () => {
    const pt = point([184.8, -21.2])
    expect(unwrapTo(pt, 150)).toBe(pt)
    expect(unwrapTo(null, 150)).toBe(null)
  })
})

describe('the fog cut at Tonga', () => {
  it('reveals the ship\'s own position once the leg is sailed', () => {
    const cut = turfDifference(featureCollection([WORLD, corridorFor(TASMAN)]))
    expect(booleanPointInPolygon(TONGA, cut)).toBe(false)
  })

  it('still hides everything the expedition has not reached', () => {
    const cut = turfDifference(featureCollection([WORLD, corridorFor(TASMAN)]))
    // Well north of the track, and the empty Pacific south of it: both still unknown world.
    expect(booleanPointInPolygon(point([150, 10]), cut)).toBe(true)
    expect(booleanPointInPolygon(point([184.8, -45]), cut)).toBe(true)
  })

  it('regresses nothing west of the seam: an early leg reveals only its own corridor', () => {
    const early = corridorFor(TASMAN.slice(0, 3))   // Mauritius → Australia, nowhere near 180°
    const cut = turfDifference(featureCollection([WORLD, early]))
    expect(booleanPointInPolygon(point([110, -35]), cut)).toBe(false)  // sailed
    expect(booleanPointInPolygon(TONGA, cut)).toBe(true)               // not yet
  })
})
