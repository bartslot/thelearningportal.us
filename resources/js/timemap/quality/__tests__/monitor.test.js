import { describe, it, expect } from 'vitest'
import { createFrameMonitor, summarizeFrames } from '../monitor.js'

/** Feed a monitor `count` frames of `ms` each, collecting whatever it decides. */
const feed = (monitor, ms, count) => {
  const decisions = []
  for (let i = 0; i < count; i++) {
    const d = monitor.sample(ms)
    if (d) decisions.push(d)
  }
  return decisions
}

describe('summarizeFrames', () => {
  it('reports the median, not the mean, so one stall does not define the device', () => {
    expect(summarizeFrames([16, 16, 16, 16, 900]).medianMs).toBe(16)
  })

  it('drops samples above the stall threshold entirely', () => {
    // A backgrounded tab produces a single multi-second frame. Counting it as slow rendering would
    // step every device down the moment someone switches tabs.
    const summary = summarizeFrames([16, 16, 4000, 16, 16], { stallMs: 250 })
    expect(summary.dropped).toBe(1)
    expect(summary.medianMs).toBe(16)
  })

  it('reports a p95 above the median on a jittery sequence', () => {
    const summary = summarizeFrames([10, 10, 10, 10, 10, 10, 10, 10, 10, 40])
    expect(summary.p95Ms).toBeGreaterThan(summary.medianMs)
  })

  it('says nothing at all when it has nothing to say', () => {
    expect(summarizeFrames([]).medianMs).toBe(null)
  })
})

describe('step-down', () => {
  it('does not react to a brief hitch', () => {
    const monitor = createFrameMonitor({ targetFps: 60 })
    feed(monitor, 16, 200)
    const decisions = feed(monitor, 60, 5)
    expect(decisions).toEqual([])
  })

  it('steps down once when slowness is sustained', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 2000 })
    const decisions = feed(monitor, 45, 400)
    expect(decisions.filter((d) => d.direction === 'down').length).toBe(1)
    expect(decisions[0].medianMs).toBeCloseTo(45, 0)
  })

  it('waits out the whole window before deciding, not just a few frames', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 2000 })
    // 2000 ms at 45 ms a frame is 44 frames. Well short of that must produce nothing.
    expect(feed(monitor, 45, 20)).toEqual([])
  })

  it('measures against the tier target, so a 30 fps tier is not judged at 60', () => {
    const at60 = createFrameMonitor({ targetFps: 60, windowMs: 2000 })
    const at30 = createFrameMonitor({ targetFps: 30, windowMs: 2000 })
    expect(feed(at60, 28, 400).some((d) => d.direction === 'down')).toBe(true)
    expect(feed(at30, 28, 400).some((d) => d.direction === 'down')).toBe(false)
  })
})

describe('hysteresis, which is the whole point', () => {
  it('does not oscillate when the device sits exactly on the boundary', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000, recoveryMs: 8000 })
    const decisions = []
    // Alternating comfortable and slow stretches, twenty times over.
    for (let round = 0; round < 20; round++) {
      decisions.push(...feed(monitor, 40, 60))
      decisions.push(...feed(monitor, 8, 60))
    }
    // A tier that flickers is worse than one that is simply too low.
    expect(decisions.length).toBeLessThanOrEqual(1)
  })

  it('needs far longer to step up than to step down', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000, recoveryMs: 8000 })
    feed(monitor, 45, 300)                                   // earn a step down
    expect(feed(monitor, 6, 100).length).toBe(0)             // ~600 ms of comfort: not enough
    const up = feed(monitor, 6, 1400)                        // ~8.4 s of comfort
    expect(up.filter((d) => d.direction === 'up').length).toBe(1)
  })

  it('stops trying after it has stepped down twice', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000, recoveryMs: 2000, maxStepDowns: 2 })
    feed(monitor, 45, 300)
    feed(monitor, 45, 300)
    const later = feed(monitor, 4, 4000)
    expect(later.filter((d) => d.direction === 'up')).toEqual([])
    expect(monitor.stats().latched).toBe(true)
  })

  it('will not step below the floor it was given', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000, maxStepDowns: 0 })
    expect(feed(monitor, 200, 500).filter((d) => d.direction === 'down')).toEqual([])
  })
})

describe('what the monitor refuses to count', () => {
  it('ignores the first frames, which include shader compilation', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000, warmupFrames: 30 })
    feed(monitor, 300, 25)
    expect(monitor.stats().counted).toBe(0)
  })

  it('ignores a hidden-tab gap instead of reading it as a slow device', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000 })
    feed(monitor, 8, 200)
    feed(monitor, 30000, 1)
    expect(feed(monitor, 8, 100).filter((d) => d.direction === 'down')).toEqual([])
  })

  it('resets its window after a decision so the next one starts from fresh evidence', () => {
    const monitor = createFrameMonitor({ targetFps: 60, windowMs: 1000 })
    feed(monitor, 45, 300)
    expect(monitor.stats().counted).toBe(0)
  })
})
