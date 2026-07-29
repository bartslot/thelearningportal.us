import { describe, it, expect } from 'vitest'
import { smooth, SAMPLES_PER_SEGMENT } from '../voyages.js'

/**
 * Route geometry.
 *
 * Two reported problems, one shared consequence: "can we make the line corners super smooth?" and
 * "the dragging area is not on the line". A drag handle sits on a raw waypoint, so the drawn line
 * must pass exactly through every waypoint or the handle floats off it.
 */

const route = [
  [-6.9, 37.2], [-16, 28], [-30, 25.5], [-45, 24], [-60, 23.8],
]
// A deliberately sharp turn, the shape that used to kink: a route rounding a headland.
const headland = [[30, 32], [35, 33], [35.5, 36.5], [33, 40]]

const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1])

describe('smooth', () => {
  it('passes exactly through every waypoint, so drag handles land on the line', () => {
    const line = smooth(route)
    route.forEach((wp, i) => {
      const sample = line[i * SAMPLES_PER_SEGMENT]
      expect(dist(sample, wp)).toBeLessThan(1e-9)
    })
  })

  it('emits SAMPLES_PER_SEGMENT points per segment', () => {
    const line = smooth(route)
    expect(line.length).toBe((route.length - 1) * SAMPLES_PER_SEGMENT + 1)
  })

  it('never overshoots a sharp corner', () => {
    // Centripetal parameterisation is chosen precisely so a tight turn cannot cusp or loop past
    // the corner. Every sample must stay within the waypoints' bounding box, generously padded.
    const line = smooth(headland)
    const xs = headland.map((p) => p[0])
    const ys = headland.map((p) => p[1])
    const padX = (Math.max(...xs) - Math.min(...xs)) * 0.25
    const padY = (Math.max(...ys) - Math.min(...ys)) * 0.25
    for (const [x, y] of line) {
      expect(x).toBeGreaterThanOrEqual(Math.min(...xs) - padX)
      expect(x).toBeLessThanOrEqual(Math.max(...xs) + padX)
      expect(y).toBeGreaterThanOrEqual(Math.min(...ys) - padY)
      expect(y).toBeLessThanOrEqual(Math.max(...ys) + padY)
    }
  })

  it('turns corners gradually rather than in one hard step', () => {
    // Largest heading change between consecutive samples: a polyline would turn the whole corner
    // at once, a smooth curve spreads it out.
    const line = smooth(headland)
    let worst = 0
    for (let i = 1; i < line.length - 1; i++) {
      const a = Math.atan2(line[i][1] - line[i - 1][1], line[i][0] - line[i - 1][0])
      const b = Math.atan2(line[i + 1][1] - line[i][1], line[i + 1][0] - line[i][0])
      let d = Math.abs(b - a)
      if (d > Math.PI) d = 2 * Math.PI - d
      worst = Math.max(worst, d)
    }
    expect(worst).toBeLessThan(Math.PI / 6)   // no single step turns more than 30 degrees
  })

  it('leaves a two-point route alone', () => {
    const straight = [[0, 0], [10, 10]]
    expect(smooth(straight)).toEqual(straight)
  })

  it('survives duplicate waypoints without dividing by zero', () => {
    const dupes = [[5, 5], [5, 5], [10, 10], [10, 10]]
    for (const [x, y] of smooth(dupes)) {
      expect(Number.isFinite(x)).toBe(true)
      expect(Number.isFinite(y)).toBe(true)
    }
  })
})

describe('wobble envelope', () => {
  // Mirrors the shaping in voyage-tour.js trailFeature().
  const wobbleOffset = (i, seg = SAMPLES_PER_SEGMENT) => Math.sin(((i % seg) / seg) * Math.PI)

  it('is zero at every waypoint, so the drawn line still touches the handles', () => {
    for (let wp = 0; wp < 6; wp++) {
      expect(Math.abs(wobbleOffset(wp * SAMPLES_PER_SEGMENT))).toBeLessThan(1e-12)
    }
  })

  it('still bows between waypoints, keeping the hand-drawn look', () => {
    expect(wobbleOffset(Math.floor(SAMPLES_PER_SEGMENT / 2))).toBeGreaterThan(0.9)
  })
})
