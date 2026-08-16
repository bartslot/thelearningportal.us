import { describe, it, expect } from 'vitest'
import { register, set, values } from '../tuner.js'

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
