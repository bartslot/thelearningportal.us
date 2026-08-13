import { describe, it, expect, beforeEach, vi } from 'vitest'
import { CARD_CONTROLS, readLengthPx, registerCardControls } from '../information-card.js'

/** A tuner that records what was registered, the way the real panel would consume it. */
const fakeTuner = () => {
  const registered = []
  const off = vi.fn()
  return {
    registered,
    off,
    __tune: { register: (name, controls, opts) => { registered.push({ name, controls, opts }); return off } },
  }
}

beforeEach(() => {
  document.documentElement.style.cssText = ''
})

describe('readLengthPx', () => {
  it('reads a pixel length', () => {
    document.documentElement.style.setProperty('--probe', '310px')
    expect(readLengthPx('--probe')).toBe(310)
  })

  /**
   * The bug this exists for: brand-kit.css writes --card-width as `19.375rem`, and parseFloat on
   * that string returns 19.375 — a perfectly finite number that is silently 16x too small. A card
   * 19px wide has no error state; it just looks broken.
   */
  it('resolves a rem length against the root font size rather than reading it as pixels', () => {
    document.documentElement.style.fontSize = '16px'
    document.documentElement.style.setProperty('--probe', '19.375rem')
    expect(readLengthPx('--probe')).toBe(310)
  })

  it('returns null for a property that is not set, rather than NaN', () => {
    expect(readLengthPx('--not-set-anywhere')).toBeNull()
  })
})

describe('registerCardControls', () => {
  it('registers every card control on the Map tab', () => {
    const tuner = fakeTuner()
    registerCardControls(tuner)

    expect(tuner.registered).toHaveLength(1)
    const [group] = tuner.registered
    expect(group.name).toBe('Information card')
    expect(group.opts).toEqual({ tab: 'Map' })
    expect(group.controls.map((c) => c.key)).toEqual(CARD_CONTROLS.map((c) => c.key))
  })

  it('writes the tuned value onto the root as pixels', () => {
    const tuner = fakeTuner()
    registerCardControls(tuner)

    tuner.registered[0].controls[0].apply(288)
    expect(document.documentElement.style.getPropertyValue('--card-width')).toBe('288px')
  })

  /**
   * A tuned value left behind would be indistinguishable from a committed default to whoever opened
   * the map next — the exact situation the tuner exists to get out of.
   */
  it('hands every property back to the stylesheet on teardown', () => {
    const tuner = fakeTuner()
    const teardown = registerCardControls(tuner)

    for (const control of CARD_CONTROLS) {
      tuner.registered[0].controls.find((c) => c.key === control.key).apply(99)
    }
    teardown()

    expect(tuner.off).toHaveBeenCalled()
    for (const control of CARD_CONTROLS) {
      expect(document.documentElement.style.getPropertyValue(control.property)).toBe('')
    }
  })

  it('no-ops without a tuner, because the panel is local-only', () => {
    expect(() => registerCardControls({})()).not.toThrow()
  })
})
