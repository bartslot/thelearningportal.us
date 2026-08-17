import { describe, it, expect } from 'vitest'
import { register, set, values, __setPresetsForTest, applyActivePreset } from '../tuner.js'

/**
 * WHY A PRESET WRITE HAS TO MERGE.
 *
 * `values()` can only report groups that are registered at the moment it is called, and most of
 * this panel's groups are not permanent — the ocean, clouds, sky, glare and colour grade are
 * registered by the globe layers, which exist only while the Earth style is mounted. Saving used to
 * write values() straight into tuning.json, so saving from any other style DELETED every one of
 * those settings. Nothing reported it: the panel had faithfully stored everything it could see.
 *
 * These pin the precondition rather than the fix, because the precondition is the surprising part.
 */
describe('what values() can and cannot see', () => {
  it('reports a group once it is registered', () => {
    register('Ocean·t', [{ key: 'depth', value: 0.4, apply: () => {} }])
    expect(values({ meta: false })['Ocean·t']).toEqual({ depth: 0.4 })
  })

  it('reports what was SET, not the declared default', () => {
    register('Grade·t', [{ key: 'saturation', value: 1, apply: () => {} }])
    expect(set('Grade·t', 'saturation', 0)).toBe(true)
    expect(values({ meta: false })['Grade·t']).toEqual({ saturation: 0 })
  })

  it('STOPS reporting a group the moment it unregisters — the whole reason a write must merge', () => {
    const off = register('Clouds·t', [{ key: 'cover', value: 0.5, apply: () => {} }])
    expect(values({ meta: false })['Clouds·t']).toEqual({ cover: 0.5 })
    off()
    // A preset written from values() alone at THIS instant carries no cloud settings at all, so
    // storing it verbatim would erase them. Merging over what is already saved is what keeps them.
    expect(values({ meta: false })['Clouds·t']).toBeUndefined()
  })

  it('re-registering a group resets it to the declared defaults', () => {
    register('Glare·t', [{ key: 'strength', value: 0.45, apply: () => {} }])
    set('Glare·t', 'strength', 2)
    expect(values({ meta: false })['Glare·t']).toEqual({ strength: 2 })
    register('Glare·t', [{ key: 'strength', value: 0.45, apply: () => {} }])
    expect(values({ meta: false })['Glare·t']).toEqual({ strength: 0.45 })
  })

  it('set() returns false for a control that does not exist, rather than doing nothing quietly', () => {
    expect(set('Glare·t', 'nope', 1)).toBe(false)
    expect(set('NoSuchGroup·t', 'strength', 1)).toBe(false)
  })
})

/**
 * SWITCHING PRESETS MUST NOT LEAK VALUES BETWEEN THEM.
 *
 * Reported from the map: a "WW2" preset stores saturation 0 for a black-and-white look, and every
 * preset saved before the colour grade existed has no saturation of its own. Switching away from
 * WW2 left the map black and white, because an absent key was skipped rather than reset.
 */
describe('switching between presets', () => {
  const load = (presets, active) => {
    __setPresetsForTest({ active, presets })
  }

  it('a key the target preset does not have goes back to its DEFAULT, not the previous value', () => {
    const PRESETS = { WW2: { 'Grade·s': { saturation: 0 } }, Plain: {} }

    // Register ONCE, then switch. Re-registering would rebuild the controls at their defaults and
    // the leak could not reproduce — the bug lives in the switch, not in the registration.
    load(PRESETS, 'WW2')
    register('Grade·s', [{ key: 'saturation', value: 1, apply: () => {} }])
    expect(values({ meta: false })['Grade·s']).toEqual({ saturation: 0 })

    load(PRESETS, 'Plain')
    applyActivePreset()
    expect(values({ meta: false })['Grade·s'], 'WW2 saturation leaked into a preset that never had one')
      .toEqual({ saturation: 1 })
  })

  it('a key the target preset DOES have still wins', () => {
    load({ A: { 'Grade·s2': { saturation: 0.2 } } }, 'A')
    register('Grade·s2', [{ key: 'saturation', value: 1, apply: () => {} }])
    expect(values({ meta: false })['Grade·s2']).toEqual({ saturation: 0.2 })
  })
})
