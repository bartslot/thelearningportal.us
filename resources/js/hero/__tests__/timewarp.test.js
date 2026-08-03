import { describe, test, expect, beforeEach, afterEach, vi } from 'vitest'
import { createTimewarp } from '../timewarp.js'

/**
 * The hero warp launches every card on its own flight. These lock down the two things that make
 * that read correctly — cards arriving one at a time rather than as a set, and each flight
 * running from the centre out past the lens — plus the frame-clock hazards that broke it before:
 *
 *  - a huge delta (backgrounded tab) must not teleport the field
 *  - a NEGATIVE delta must not run flights backwards. That one shipped: the clamp guarded only
 *    the ceiling, so one backwards frame emptied the canvas permanently.
 *
 * jsdom has no 2D context, so the canvas is stubbed. This is about arithmetic, not pixels.
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

function makeWarp () {
  const canvas = document.createElement('canvas')
  canvas.getBoundingClientRect = () => ({ width: 1200, height: 800, left: 0, top: 0 })
  document.body.append(canvas)

  // A 4x6 atlas of 160px cells, the shape tools/build-timewarp-atlas.py produces.
  const warp = createTimewarp(canvas, { naturalWidth: 640, naturalHeight: 960 })

  return { warp, ctx: canvas.getContext('2d') }
}

const run = (warp, seconds) => {
  for (let t = 0; t < seconds; t += 1 / 60) warp.advance(1 / 60)
}

describe('hero timewarp', () => {
  test('the sky starts empty and cards launch one at a time', () => {
    const { warp } = makeWarp()
    const inFlight = () => warp.particles.filter((particle) => particle.active).length

    expect(warp).not.toBeNull()
    expect(inFlight()).toBe(0)

    warp.advance(1 / 60)
    run(warp, 2)
    const early = inFlight()
    run(warp, 4)
    const later = inFlight()

    // A trickle, not a set: a handful in the air early, growing steadily.
    expect(early).toBeGreaterThan(0)
    expect(early).toBeLessThanOrEqual(4)
    expect(later).toBeGreaterThan(early)
  })

  test('no two cards share a moment of growth', () => {
    const { warp } = makeWarp()
    warp.advance(1 / 60)
    run(warp, 6)

    const progresses = warp.particles
      .filter((particle) => particle.active)
      .map((particle) => particle.progress)

    expect(progresses.length).toBeGreaterThan(2)
    // Every flight is at its own point — the lockstep swell was the whole complaint.
    expect(new Set(progresses.map((p) => p.toFixed(3))).size).toBe(progresses.length)
  })

  test('a flight runs from the vanishing point outward and then retires', () => {
    const { warp } = makeWarp()
    warp.state.speed = 1.5
    warp.advance(1 / 60)
    // Long enough for the first launch: the rhythm opens with a deliberate pause.
    run(warp, 2)

    const card = warp.particles.find((particle) => particle.active)
    expect(card).toBeDefined()

    // Born inside the centre dot: even the widest exit vector projects to under 5% of the short
    // edge at Z_FAR, so every flight starts in the middle and opens out from there.
    expect(card.exitRatio * (1600 / 18000)).toBeLessThan(0.05)

    const startedAt = card.z
    run(warp, 1)
    expect(card.z).toBeLessThan(startedAt) // coming at you

    run(warp, 30)
    expect(warp.particles.filter((particle) => particle.progress >= 1).length).toBeGreaterThan(0)
  })

  test('a frame that steps backwards leaves the field where it was', () => {
    const { warp } = makeWarp()
    warp.state.speed = 2.6

    warp.advance(1 / 60)
    run(warp, 2)
    const before = warp.particles.map((particle) => particle.progress)

    warp.advance(-6)

    expect(warp.particles.map((particle) => particle.progress)).toEqual(before)
  })

  test('a huge frame gap cannot fling a flight past its own end', () => {
    const { warp } = makeWarp()
    warp.state.speed = 2.6

    warp.advance(1 / 60)
    run(warp, 2)
    warp.advance(30)

    warp.particles.forEach((particle) => {
      expect(particle.z).toBeGreaterThan(0)
      expect(particle.z).toBeLessThanOrEqual(18000)
    })
  })

  test('receding clears the sky by fading, not by blinking cards out', () => {
    const { warp } = makeWarp()
    warp.advance(1 / 60)
    run(warp, 5)

    expect(warp.particles.filter((particle) => particle.fade > 0.01).length).toBeGreaterThan(0)

    warp.state.density = 0
    warp.recede()
    warp.advance(1 / 60)

    // Still on screen the instant after: it is a fade, not a cut.
    expect(warp.particles.filter((particle) => particle.fade > 0.01).length).toBeGreaterThan(0)

    run(warp, 1.2)
    expect(warp.particles.filter((particle) => particle.fade > 0.01).length).toBe(0)
  })
})
