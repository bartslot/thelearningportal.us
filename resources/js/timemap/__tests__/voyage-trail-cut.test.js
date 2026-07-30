import { describe, it, expect } from 'vitest'
import { arcTable, cutAtPoint, smooth } from '../voyages.js'

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

describe('arcTable', () => {
  it('measures cumulative length, correcting longitude for latitude', () => {
    const equator = arcTable([[0, 0], [1, 0]])
    const far = arcTable([[0, 60], [1, 60]])
    // One degree of longitude at 60° covers half the ground it does at the equator.
    expect(far.total).toBeCloseTo(equator.total * 0.5, 3)
  })

  it('is empty for a line that has no length', () => {
    expect(arcTable([[5, 5]]).total).toBe(0)
  })
})
