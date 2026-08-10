import { describe, it, expect, vi } from 'vitest'
import { createQualityController } from '../index.js'
import { DESKTOP_DISCRETE, SCHOOL_CHROMEBOOK, BUDGET_ANDROID } from '../__fixtures__/profiles.js'

const controller = (over = {}) => createQualityController({
  profile: DESKTOP_DISCRETE,
  surface: 'timemap',
  monitor: { windowMs: 1000, recoveryMs: 8000 },
  ...over,
})

describe('the public interface', () => {
  it('knows nothing about lessons, Livewire, scenes or History Portal', async () => {
    // The plugin bar, asserted rather than promised. Read the module text and look for the words.
    const { readFileSync } = await import('node:fs')
    const source = readFileSync('resources/js/timemap/quality/index.js', 'utf8')
    for (const forbidden of ['Livewire', 'wire:', 'lessonId', 'scene_id', 'historyportal']) {
      expect(source, `index.js mentions ${forbidden}`).not.toContain(forbidden)
    }
  })

  it('reaches the video tier through a dynamic import, not a static one', async () => {
    // The fallback is the only DOM-touching part of this system and most devices never reach it.
    // A static re-export would put its element handling, and the unpacking shader behind it, into
    // every host's main chunk.
    const { readFileSync } = await import('node:fs')
    const source = readFileSync('resources/js/timemap/quality/index.js', 'utf8')
    expect(source).not.toMatch(/^import .*from '\.\/video-/m)

    const { loadVideoFallback } = await import('../index.js')
    const module = await loadVideoFallback()
    expect(typeof module.createVideoFallback).toBe('function')
  })

  it('produces a plan from a profile without needing a GL context', () => {
    const q = controller()
    expect(q.plan.tier).toBe('high')
    expect(q.plan.surface).toBe('timemap')
    q.dispose()
  })

  it('exposes the profile it decided from', () => {
    const q = controller({ profile: SCHOOL_CHROMEBOOK })
    expect(q.profile.maxTextureSize).toBe(4096)
    expect(q.plan.tier).toBe('standard')
    q.dispose()
  })
})

describe('runtime step-down', () => {
  it('drops a tier when the device turns out slower than it looked', () => {
    const onPlanChange = vi.fn()
    const q = controller({ onPlanChange })
    for (let i = 0; i < 400; i++) q.frame(45)
    expect(q.plan.tier).toBe('standard')
    expect(onPlanChange).toHaveBeenCalledTimes(1)
    expect(onPlanChange.mock.calls[0][1].direction).toBe('down')
    q.dispose()
  })

  it('holds a step-down until the animation it would pop through has finished', () => {
    let animating = true
    const onPlanChange = vi.fn()
    const q = controller({ onPlanChange, isAnimating: () => animating })

    for (let i = 0; i < 400; i++) q.frame(45)
    expect(onPlanChange).not.toHaveBeenCalled()
    expect(q.pendingChange).toBeTruthy()

    animating = false
    q.frame(45)
    expect(onPlanChange).toHaveBeenCalledTimes(1)
    expect(q.plan.tier).toBe('standard')
    q.dispose()
  })

  it('applies the newest decision, not a stale one, when several queue up while animating', () => {
    let animating = true
    const q = controller({ isAnimating: () => animating })
    for (let i = 0; i < 400; i++) q.frame(45)
    for (let i = 0; i < 400; i++) q.frame(120)
    animating = false
    q.frame(120)
    // One application, and it is the one the last evidence justified.
    expect(q.plan.tier).toBe('standard')
    q.dispose()
  })

  it('never steps below minimal on its own — video is a decision, not a slide', () => {
    const q = controller({ profile: BUDGET_ANDROID })
    expect(q.plan.tier).toBe('minimal')
    for (let i = 0; i < 2000; i++) q.frame(300)
    expect(q.plan.tier).toBe('minimal')
    q.dispose()
  })

  it('stops watching once disposed', () => {
    const onPlanChange = vi.fn()
    const q = controller({ onPlanChange })
    q.dispose()
    for (let i = 0; i < 400; i++) q.frame(45)
    expect(onPlanChange).not.toHaveBeenCalled()
  })
})

describe('overrides', () => {
  it('lets a teacher on a good machine ask for it quiet, and stays there', () => {
    const q = controller()
    q.setPreference('minimal')
    expect(q.plan.tier).toBe('minimal')
    for (let i = 0; i < 2000; i++) q.frame(4)
    expect(q.plan.tier).toBe('minimal')
    q.dispose()
  })

  it('returns to automatic when the preference is cleared', () => {
    const q = controller()
    q.setPreference('minimal')
    q.setPreference('auto')
    expect(q.plan.tier).toBe('high')
    q.dispose()
  })

  it('lets the host pin a tier that runtime measurement cannot then move', () => {
    const q = controller({ override: 'high' })
    for (let i = 0; i < 2000; i++) q.frame(120)
    expect(q.plan.tier).toBe('high')
    q.dispose()
  })
})
