import { describe, test, expect, beforeEach, afterEach, vi } from 'vitest'
import { createCardwheel } from '../cardwheel.js'

/**
 * The cardwheel is the "order, chaos, order" hero: a ring of evenly spaced cards, dragged into
 * the middle one at a time, coming back with the LESSON's pictures in the same seats — as if the
 * wheel had been changed rather than redrawn.
 *
 * These lock the two things that were wrong the first time round: the ring came back showing the
 * same generic cards it left with, and the burst relaunched for as long as it was switched on,
 * so pictures streamed out for ever instead of being thrown once.
 */

const CONTEXT_METHODS = [
  'save', 'restore', 'translate', 'rotate', 'scale', 'setTransform',
  'clearRect', 'fillRect', 'drawImage', 'beginPath', 'closePath', 'clip',
  'rect', 'roundRect', 'createRadialGradient',
]

function makeContext () {
  const ctx = { globalAlpha: 1, globalCompositeOperation: 'source-over', fillStyle: '' }
  CONTEXT_METHODS.forEach((name) => { ctx[name] = vi.fn() })
  ctx.createRadialGradient = () => ({ addColorStop: () => {} })

  return ctx
}

let originalGetContext

beforeEach(() => {
  originalGetContext = HTMLCanvasElement.prototype.getContext
  HTMLCanvasElement.prototype.getContext = function getContext () {
    this.__ctx ??= makeContext()

    return this.__ctx
  }
})

afterEach(() => {
  HTMLCanvasElement.prototype.getContext = originalGetContext
})

function makeWheel () {
  const canvas = document.createElement('canvas')
  canvas.getBoundingClientRect = () => ({ width: 1200, height: 800, left: 0, top: 0 })
  document.body.append(canvas)

  const wheel = createCardwheel(canvas, { naturalWidth: 640, naturalHeight: 960 }, {
    // A 4x4 sheet of the lesson's own footage, the shape lessons:build-warp-atlas produces.
    lessonAtlas: { image: { naturalWidth: 640, naturalHeight: 640 }, cells: 16 },
  })

  return { wheel, ctx: canvas.getContext('2d') }
}

const run = (wheel, seconds) => {
  for (let t = 0; t < seconds; t += 1 / 60) wheel.advance(1 / 60)
}

describe('hero cardwheel', () => {
  test('the ring is evenly spaced with an identical gap between every pair', () => {
    const { wheel } = makeWheel()
    const angles = wheel.particles.map((particle) => particle.homeAngle)
    const gaps = angles.slice(1).map((angle, index) => angle - angles[index])

    expect(wheel.particles.length).toBeGreaterThan(8)
    gaps.forEach((gap) => expect(gap).toBeCloseTo(gaps[0], 6))
  })

  test('the ring is mathematically exact: one radius, one gap, rotation following the circle', () => {
    const { wheel, ctx } = makeWheel()
    const centre = { x: 600, y: 400 } // canvas is 1200x800

    ctx.translate.mockClear()
    ctx.rotate.mockClear()
    wheel.advance(1 / 60)

    const points = ctx.translate.mock.calls.map(([x, y]) => ({ x, y }))
    const rotations = ctx.rotate.mock.calls.map(([angle]) => angle)

    expect(points.length).toBe(wheel.particles.length)

    // Every card sits on one circle.
    const radii = points.map((p) => Math.hypot(p.x - centre.x, p.y - centre.y))
    radii.forEach((r) => expect(r).toBeCloseTo(radii[0], 6))

    // Seats are evenly spaced: sorted angles step by exactly 2π/N, so every gap is identical.
    const step = (Math.PI * 2) / wheel.particles.length
    const angles = points.map((p) => Math.atan2(p.y - centre.y, p.x - centre.x)).sort((a, b) => a - b)
    angles.slice(1).forEach((angle, index) => {
      expect(angle - angles[index]).toBeCloseTo(step, 6)
    })

    // Rotation follows the circle and nothing else — no jitter, which is what made the gaps
    // read as uneven even though the centres were exact.
    const spins = rotations.map((a) => a % (Math.PI * 2)).sort((a, b) => a - b)
    spins.slice(1).forEach((spin, index) => {
      expect(spin - spins[index]).toBeCloseTo(step, 6)
    })
  })

  test('a card comes back holding the lesson picture, in the seat it left', () => {
    const { wheel } = makeWheel()
    const seat = wheel.particles[0]
    const before = seat.cellBefore
    const home = seat.homeAngle

    expect(seat.swapped).toBe(false)
    expect(seat.cellAfter).not.toBe(before)

    // Swallow the ring, then bring it back.
    wheel.state.pull = 1
    run(wheel, 0.5)
    wheel.state.pull = 0
    run(wheel, 0.5)

    expect(seat.swapped).toBe(true)
    // Same seat, new picture: the wheel was changed, not redrawn somewhere else.
    expect(seat.homeAngle).toBe(home)
  })

  test('the burst throws each picture once and then stops', () => {
    const { wheel } = makeWheel()

    expect(wheel.burstCards.length).toBeGreaterThan(0)

    // Switch it on and leave it on, which is exactly the state that used to stream for ever.
    wheel.state.burst = 1
    run(wheel, 12)

    expect(wheel.burstCards.every((card) => card.spent)).toBe(true)
    expect(wheel.burstCards.some((card) => card.active)).toBe(false)

    // Still on, much later: nothing may relaunch.
    run(wheel, 12)
    expect(wheel.burstCards.some((card) => card.active)).toBe(false)
  })

  test('restarting puts the generic wheel back', () => {
    const { wheel } = makeWheel()

    wheel.state.pull = 1
    run(wheel, 0.6)
    expect(wheel.particles[0].swapped).toBe(true)

    wheel.restart()

    expect(wheel.particles[0].swapped).toBe(false)
    expect(wheel.state.pull).toBe(0)
    expect(wheel.state.burst).toBe(0)
    expect(wheel.burstCards.every((card) => !card.spent && !card.active)).toBe(true)
  })
})
