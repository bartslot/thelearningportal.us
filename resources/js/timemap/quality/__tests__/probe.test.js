import { describe, it, expect } from 'vitest'
import { runRenderProbe, estimateFrameMs } from '../probe.js'
import { selectTier } from '../tiers.js'
import { createFakeGl } from '../__fixtures__/fake-gl.js'
import { makeProfile, DESKTOP_DISCRETE } from '../__fixtures__/profiles.js'

const run = (gl, opts = {}) => runRenderProbe({ gl, now: gl.now, ...opts })

describe('runRenderProbe', () => {
  it('measures the frames it drew and says how many pixels each covered', () => {
    const gl = createFakeGl({ msPerDraw: 2 })
    const result = run(gl, { frames: 10, size: 128, budgetMs: 10000 })
    expect(result.probeMs).toBe(2)
    expect(result.frames).toBe(10)
    expect(result.pixels).toBe(128 * 128)
    expect(result.aborted).toBe(false)
  })

  it('warms up before it starts counting, because the first frames are shader compilation', () => {
    const gl = createFakeGl({ msPerDraw: 1 })
    run(gl, { frames: 10, warmupFrames: 3, budgetMs: 10000 })
    expect(gl.drawArrays.mock.calls.length).toBe(13)
  })

  it('gives up at the wall-clock ceiling rather than making a slow device slower', () => {
    // The device where this measurement matters most is the one where it would take longest.
    const gl = createFakeGl({ msPerDraw: 40 })
    const result = run(gl, { frames: 50, budgetMs: 60 })
    expect(result.aborted).toBe(true)
    expect(result.frames).toBeLessThan(50)
  })

  it('drains the pipeline after each draw, or it is timing the queue and not the GPU', () => {
    const gl = createFakeGl({ msPerDraw: 1 })
    run(gl, { frames: 4, warmupFrames: 0, budgetMs: 10000 })
    expect(gl.readPixels.mock.calls.length).toBe(4)
  })

  it('frees every GL object it made, on the way out', () => {
    // This runs on every page load. A leaked framebuffer here is a slow fault in the one part of
    // the system whose whole job is not to cost anything.
    const gl = createFakeGl({ msPerDraw: 1 })
    run(gl, { frames: 4, budgetMs: 10000 })
    expect(gl.leaked()).toEqual([])
  })

  it('frees them on the failure paths too', () => {
    for (const failure of [{ compiles: false }, { links: false }, { framebufferComplete: false }]) {
      const gl = createFakeGl(failure)
      expect(run(gl, { frames: 4 })).toBe(null)
      expect(gl.leaked(), JSON.stringify(failure)).toEqual([])
    }
  })

  it('returns null instead of throwing when the context is lost mid-probe', () => {
    const gl = createFakeGl({ throwsOn: 'drawArrays' })
    expect(run(gl, { frames: 4 })).toBe(null)
    expect(gl.leaked()).toEqual([])
  })

  it('returns null when there is no context to probe', () => {
    expect(runRenderProbe({ gl: null })).toBe(null)
    expect(runRenderProbe()).toBe(null)
  })
})

describe('estimateFrameMs', () => {
  it('scales the probe by how many more pixels a real frame covers', () => {
    const probe = { probeMs: 1, pixels: 100, frames: 10, aborted: false }
    const profile = makeProfile({ viewport: { width: 100, height: 100 }, dpr: 1 })
    // 10,000 frame pixels over 100 probe pixels is 100×, times the workload constant.
    expect(estimateFrameMs(probe, profile).medianMs).toBeGreaterThan(0)
    expect(estimateFrameMs(probe, profile).medianMs).toBeLessThan(1 * 100)
  })

  it('counts device pixels, not CSS pixels', () => {
    const probe = { probeMs: 1, pixels: 100, frames: 10, aborted: false }
    const atOne = estimateFrameMs(probe, makeProfile({ viewport: { width: 100, height: 100 }, dpr: 1 }))
    const atTwo = estimateFrameMs(probe, makeProfile({ viewport: { width: 100, height: 100 }, dpr: 2 }))
    expect(atTwo.medianMs).toBeCloseTo(atOne.medianMs * 4, 6)
  })

  it('admits it is not calibrated', () => {
    const probe = { probeMs: 1, pixels: 100, frames: 10, aborted: false }
    expect(estimateFrameMs(probe, DESKTOP_DISCRETE).calibrated).toBe(false)
  })

  it('returns null rather than a number when it has nothing to scale by', () => {
    expect(estimateFrameMs(null, DESKTOP_DISCRETE)).toBe(null)
    expect(estimateFrameMs({ probeMs: 1, pixels: 100 }, makeProfile({ viewport: { width: 0, height: 0 } }))).toBe(null)
  })
})

describe('a plan says whether anything was actually measured', () => {
  it('reports measured: false when no probe backed the decision', () => {
    // A tier chosen from static signals alone is a reasonable guess. Reporting it identically to
    // one backed by a measurement is how a profile read somewhere meaningless — a hidden pane, a
    // zero-area viewport — gets quoted later as though a device had been tested.
    expect(selectTier(makeProfile({ probe: null })).measured).toBe(false)
    expect(selectTier(DESKTOP_DISCRETE).measured).toBe(true)
  })
})

describe('an uncalibrated probe is not allowed to quietly downgrade a device', () => {
  it('ignores a modest uncalibrated reading entirely', () => {
    // The constant that turns probe milliseconds into frame milliseconds has never been measured on
    // real hardware. Acting on a fine-grained reading from it would move every device a tier and
    // look like a deliberate decision.
    const uncalibrated = makeProfile({ probe: { medianMs: 30, calibrated: false } })
    const calibrated = makeProfile({ probe: { medianMs: 30, calibrated: true } })
    expect(selectTier(uncalibrated).tier).toBe('high')
    expect(selectTier(calibrated).tier).toBe('minimal')
  })

  it('still acts on a reading no calibration error could explain', () => {
    const hopeless = makeProfile({ probe: { medianMs: 4000, calibrated: false } })
    expect(selectTier(hopeless).tier).toBe('video')
  })
})
