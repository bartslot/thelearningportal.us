import { describe, test, expect, beforeEach, afterEach, vi } from 'vitest'
import { createTimewarp } from '../timewarp.js'
import { VARIANTS } from '../lesson-demo.js'

/**
 * Scores the four warp variants on the thing that actually decides this: can a teacher SEE the
 * pictures? A warp nobody can read is a screensaver.
 *
 * Runs each variant's dial curve through the real engine at 60fps with a stubbed canvas and
 * measures what reached the screen. Not a pass/fail suite — the assertions only guard the floor.
 * The numbers it prints are the point; see the summary in the session notes.
 */

const CONTEXT_METHODS = [
  'save', 'restore', 'translate', 'rotate', 'scale', 'setTransform',
  'clearRect', 'fillRect', 'drawImage', 'beginPath', 'closePath', 'clip',
  'rect', 'roundRect', 'createRadialGradient',
]

const VIEWPORT = { width: 1440, height: 900 }
// A card is "readable" once it covers this share of the short edge — below it, it is texture.
const READABLE_RATIO = 0.09

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
  canvas.getBoundingClientRect = () => ({ ...VIEWPORT, left: 0, top: 0 })
  document.body.append(canvas)

  const warp = createTimewarp(canvas, { naturalWidth: 640, naturalHeight: 960 })

  return { warp, ctx: canvas.getContext('2d') }
}

/** Linear ramp with the same shape as the storyboard's power-in easing. */
const easeIn = (t) => t * t * t * t

/**
 * Run one variant's warp and report what a viewer got.
 *
 * `readableSeconds` is the headline: total card-seconds spent big enough to make out, which is
 * what "long enough for teachers to see what's going on" means in a number.
 */
function measure (variant) {
  const { warp, ctx } = makeWarp()
  const step = 1 / 60
  const readable = warp.particles.map(() => 0)
  let framesWithReadable = 0
  let peak = 0
  let frames = 0

  // Fill the tunnel first, the way the idle trickle does before the warp starts.
  Object.assign(warp.state, { density: 0.22, speed: 0.16, gap: 2.2, cadence: 0.94 })
  for (let t = 0; t < 9; t += step) warp.advance(step)

  // The warp restarts the rhythm from this variant's opening pause.
  warp.state.gap = variant.warpFirstGap ?? 1.4
  warp.state.cadence = variant.warpCadence ?? 0.9

  const total = variant.windUp + variant.brake

  for (let t = 0; t < total; t += step) {
    const p = Math.min(1, t / variant.windUp)
    const braking = t > variant.windUp

    warp.state.speed = braking ? 0.08 : 0.16 + (variant.warpRate - 0.16) * easeIn(p)
    warp.state.spin = variant.warpSpin * p
    warp.state.density = braking ? 0.16 : 0.22 + (variant.warpDensity - 0.22) * Math.min(1, t / 1.6)
    warp.state.scale = braking ? 1 : 1 + ((variant.warpScale ?? 1) - 1) * Math.min(1, t / (variant.windUp * 0.8))
    warp.state.spread = braking ? 1 : 1 + ((variant.warpSpread ?? 1) - 1) * Math.min(1, t / (variant.windUp * 0.8))
    warp.state.alpha = braking ? 0.12 : 1

    ctx.drawImage.mockClear()
    warp.advance(step)
    frames += 1

    const sizes = ctx.drawImage.mock.calls.map(([, , , w]) => w)
    const readableNow = sizes.filter((w) => w >= VIEWPORT.height * READABLE_RATIO).length

    peak = Math.max(peak, sizes.length)
    if (readableNow > 0) framesWithReadable += 1

    warp.particles.forEach((particle, index) => {
      const size = particle.sizeRatio * warp.state.scale * VIEWPORT.height * (1600 / particle.z)
      if (particle.fade > 0.35 && size >= VIEWPORT.height * READABLE_RATIO) readable[index] += step
    })
  }

  const seen = readable.filter((seconds) => seconds > 0)

  return {
    seconds: +total.toFixed(2),
    readableCards: seen.length,
    readableSeconds: +seen.reduce((a, b) => a + b, 0).toFixed(1),
    perCard: +(seen.length ? seen.reduce((a, b) => a + b, 0) / seen.length : 0).toFixed(2),
    legibleShare: +(framesWithReadable / frames).toFixed(2),
    // NB: draw calls, not cards — motion blur draws each card several times.
    peakDraws: peak,
  }
}

describe('warp variants', () => {
  test('scores every variant on how long its pictures stay readable', () => {
    // Tunnel variants only. The cardwheel (hero/cardwheel.js) is a different field: its cards sit
    // still on a ring at readable size for the entire wait, so it would score top on this metric
    // by construction and tell you nothing. Judge that one by eye.
    const rows = Object.entries(VARIANTS)
      .filter(([, variant]) => (variant.field ?? 'tunnel') === 'tunnel')
      .map(([name, variant]) => ({ variant: name, ...measure(variant) }))

    // eslint-disable-next-line no-console
    console.log(rows.map((r) => JSON.stringify(r)).join('\n'))

    rows.forEach((row) => {
      // A warp where nothing is ever readable is a screensaver, whatever it looks like.
      expect(row.readableCards).toBeGreaterThan(0)
    })
  })
})
