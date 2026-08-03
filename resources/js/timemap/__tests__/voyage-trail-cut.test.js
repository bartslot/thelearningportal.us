import { describe, it, expect } from 'vitest'
import { arcTable, cutAtPoint, trailCutAt, smooth } from '../voyages.js'

/**
 * Where the route trail stops.
 *
 * Reported as "the line is really trailing behind the horse and camel". The reveal was cut at the
 * traveller's progress along its own track, which is a different ruler from the drawn line's
 * length — so the line's end drifted away from the traveller mid-leg. It stayed unnoticed on the
 * ship, which is big enough to hide the gap; a camel is not.
 */

const at = (lng, lat) => ({ lng, lat })

describe('cutAtPoint', () => {
  // A plain due-east line at the equator: 0..4 degrees, so fractions are easy to reason about.
  const line = [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]]
  const table = arcTable(line)

  it('cuts exactly where the traveller stands, mid-segment included', () => {
    expect(cutAtPoint(line, table, at(2, 0), 0)).toBeCloseTo(0.5, 6)
    expect(cutAtPoint(line, table, at(2.5, 0), 0)).toBeCloseTo(0.625, 6)
  })

  it('ignores the estimate when the position is unambiguous — that is the whole point', () => {
    // A wildly wrong estimate (what the old track fraction amounted to) must not move the cut.
    expect(cutAtPoint(line, table, at(1, 0), 0.9)).toBeCloseTo(0.25, 6)
  })

  it('projects a traveller sitting slightly off the line back onto it', () => {
    expect(cutAtPoint(line, table, at(3, 0.02), 0)).toBeCloseTo(0.75, 4)
  })

  it('ends at 0 and 1 at the two ends', () => {
    expect(cutAtPoint(line, table, at(0, 0), 0)).toBeCloseTo(0, 6)
    expect(cutAtPoint(line, table, at(4, 0), 1)).toBeCloseTo(1, 6)
  })

  it('picks the outbound pass over the return one when a route doubles back', () => {
    // Out along the equator and back a hair north — Marco Polo returning down his own corridor.
    const there = [[0, 0], [1, 0], [2, 0], [3, 0]]
    const back = [[3, 0.01], [2, 0.01], [1, 0.01], [0, 0.01]]
    const doubled = [...there, ...back]
    const t = arcTable(doubled)
    // Standing at lng 1 on the way out (estimate early) vs on the way home (estimate late).
    expect(cutAtPoint(doubled, t, at(1, 0.005), 0.15)).toBeLessThan(0.5)
    expect(cutAtPoint(doubled, t, at(1, 0.005), 0.85)).toBeGreaterThan(0.5)
  })

  it('falls back to the estimate when there is no line to measure against', () => {
    expect(cutAtPoint([], arcTable([]), at(1, 1), 0.4)).toBe(0.4)
    expect(cutAtPoint(line, table, null, 0.4)).toBe(0.4)
  })

  it('holds up on the smoothed spline the trail is actually drawn from', () => {
    const spline = smooth([[0, 0], [10, 5], [20, 0], [30, 5]])
    const t = arcTable(spline)
    const mid = spline[Math.floor(spline.length / 2)]
    const cut = cutAtPoint(spline, t, at(mid[0], mid[1]), 0.5)
    expect(cut).toBeGreaterThan(0.4)
    expect(cut).toBeLessThan(0.6)
  })
})

/**
 * How far short of the traveller the line stops.
 *
 * Reported as "the line is already further than our ship". Cutting at the traveller's coordinate
 * is cutting at its CENTRE, so half the marker's worth of line carries on past the bow — and the
 * bigger the marker, the more of it shows.
 */
describe('trailCutAt', () => {
  // Due east along the equator, 0..4° — 4 × 111.32 km of line, so a marker's share is easy to read.
  const line = [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]]
  const table = arcTable(line)
  const at = (lng, lat) => ({ lng, lat })
  const DEGREE_M = 111_320

  it('stops half a marker short of where the traveller stands', () => {
    // A 2°-long ship (222.64 km): half of it is 1°, a quarter of this 4° line. The traveller
    // stands halfway, so the line ends at 0.25 and its last quarter-line runs under the hull.
    const cut = trailCutAt(line, table, at(2, 0), 0, 2 * DEGREE_M)

    expect(cut).toBeCloseTo(0.25, 6)
  })

  it('sets back further for a bigger marker, and not at all for none', () => {
    const small = trailCutAt(line, table, at(2, 0), 0, 0.2 * DEGREE_M)
    const large = trailCutAt(line, table, at(2, 0), 0, 2 * DEGREE_M)

    expect(large).toBeLessThan(small)
    expect(trailCutAt(line, table, at(2, 0), 0, 0)).toBeCloseTo(cutAtPoint(line, table, at(2, 0), 0), 6)
  })

  it('never runs past the traveller, whatever the marker', () => {
    for (const metres of [0, 1e3, 1e5, 1e7]) {
      expect(trailCutAt(line, table, at(2, 0), 0.9, metres)).toBeLessThanOrEqual(0.5 + 1e-9)
    }
  })

  it('never goes negative — a marker longer than the sailed line just hides all of it', () => {
    expect(trailCutAt(line, table, at(0.1, 0), 0, 50 * DEGREE_M)).toBe(0)
  })

  it('leaves the fallback alone when there is no line to measure against', () => {
    expect(trailCutAt([], arcTable([]), at(1, 1), 0.4, 5000)).toBe(0.4)
  })
})

/**
 * arcTable measures in WEB MERCATOR, not in ground distance.
 *
 * The number it produces is handed to MapLibre as `line-progress`, which is the fraction of the
 * line's length in Mercator tile coordinates. Measuring ground distance instead (longitudes scaled
 * by cos φ) is a different ruler, and on a route that runs from the tropics into the Roaring
 * Forties the two disagree by thousands of kilometres: the reveal stopped an ocean short of the
 * ship on every leg of Tasman's voyage, while every number we computed agreed with itself.
 */
describe('arcTable', () => {
  it('gives a degree of longitude the same length at every latitude', () => {
    const equator = arcTable([[0, 0], [1, 0]])
    const far = arcTable([[0, 60], [1, 60]])

    expect(far.total).toBeCloseTo(equator.total, 6)
  })

  it('stretches latitude with distance from the equator, as Mercator does', () => {
    const tropic = arcTable([[0, 0], [0, 1]])
    const forties = arcTable([[0, 45], [0, 46]])

    expect(forties.total).toBeGreaterThan(tropic.total * 1.3)
  })

  it('places a far-southern landfall where MapLibre would, not where ground distance would', () => {
    // Batavia → the Roaring Forties → back north, roughly Tasman's shape.
    const route = [[106, -6], [130, -45], [172, -45], [172, -6]]
    const table = arcTable(route)
    const cut = cutAtPoint(route, table, { lng: 172, lat: -45 }, 0.5)

    // In Mercator degrees the corners sit at y ≈ -6.03 and -50.36, so the legs measure
    // hypot(24, 44.33) = 50.42, then 42, then 44.33 — and the turn north falls at
    // (50.42 + 42) / 136.75 ≈ 0.676 of the line.
    expect(cut).toBeCloseTo(0.676, 2)
    // Ground distance puts the same corner at ~0.656. Two points of the line here; on the real
    // route, which runs far deeper into the south, it was the difference between the trail ending
    // under the ship and ending an ocean behind it.
    expect(cut).toBeGreaterThan(0.66)
  })

  it('is empty for a line that has no length', () => {
    expect(arcTable([[5, 5]]).total).toBe(0)
  })
})
