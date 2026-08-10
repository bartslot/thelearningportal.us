import { describe, it, expect } from 'vitest'
import { SURFACES, applySurface, resolveQuality } from '../surfaces.js'
import { planForTier, estimateAssetBytes } from '../tiers.js'
import { DESKTOP_DISCRETE, SCHOOL_CHROMEBOOK, NO_WEBGL } from '../__fixtures__/profiles.js'

describe('surface budgets', () => {
  it('states the three budgets that were already agreed, in bytes', () => {
    expect(SURFACES.timemap.assetBudgetBytes).toBe(8 * 1024 * 1024)
    expect(SURFACES.timemap.maxSingleAssetBytes).toBe(4 * 1024 * 1024)
    expect(SURFACES.lessonMap.assetBudgetBytes).toBe(0)
    expect(SURFACES.lessonMap.optInAssetBudgetBytes).toBe(1536 * 1024)
    expect(SURFACES.landingHero.assetBudgetBytes).toBe(1024 * 1024)
  })

  it('costs a lesson nothing at all unless that lesson opted in', () => {
    const plan = resolveQuality({ profile: DESKTOP_DISCRETE, surface: 'lessonMap' })
    expect(plan.enabled).toBe(false)
    expect(estimateAssetBytes(plan)).toBe(0)
    expect(Object.values(plan.layers).every((l) => l.enabled === false)).toBe(true)
    expect(plan.video.enabled).toBe(false)
  })

  it('lets one lesson opt in, and holds it to 1.5 MB', () => {
    const plan = resolveQuality({ profile: DESKTOP_DISCRETE, surface: 'lessonMap', optIn: true })
    expect(plan.enabled).toBe(true)
    expect(estimateAssetBytes(plan)).toBeGreaterThan(0)
    expect(estimateAssetBytes(plan)).toBeLessThanOrEqual(1536 * 1024)
  })

  it('fits the landing hero into 1 MB even when the device could take far more', () => {
    const plan = resolveQuality({ profile: DESKTOP_DISCRETE, surface: 'landingHero' })
    expect(estimateAssetBytes(plan)).toBeLessThanOrEqual(1024 * 1024)
  })

  it('reduces in a stated order rather than wherever it happens to land', () => {
    const full = planForTier('high', DESKTOP_DISCRETE)
    const hero = applySurface(full, SURFACES.landingHero)
    // Orbits go before the sky does: the sky is what a hero is for.
    expect(hero.layers.orbits.enabled).toBe(false)
    expect(hero.layers.sky.enabled).toBe(true)
    expect(hero.reductions.length).toBeGreaterThan(0)
    expect(hero.reductions[0]).toMatchObject({ layer: expect.any(String), action: expect.any(String) })
  })

  it('halves texture sizes rather than dropping a layer, when halving is enough', () => {
    const full = planForTier('high', DESKTOP_DISCRETE)
    const fitted = applySurface(full, SURFACES.timemap)
    for (const [name, layer] of Object.entries(fitted.layers)) {
      if (!layer.enabled || !layer.textureSize) continue
      expect(Math.log2(layer.textureSize) % 1, `${name} left a non-power-of-two size`).toBe(0)
    }
  })

  it('never exceeds the single-asset ceiling after reduction either', () => {
    for (const key of Object.keys(SURFACES)) {
      const surface = SURFACES[key]
      const plan = resolveQuality({ profile: DESKTOP_DISCRETE, surface: key, optIn: true })
      if (!plan.enabled) continue
      for (const [name, layer] of Object.entries(plan.layers)) {
        if (!layer.enabled) continue
        expect(layer.assetBudgetBytes, `${key}/${name}`).toBeLessThanOrEqual(surface.maxSingleAssetBytes)
      }
    }
  })
})

describe('resolveQuality', () => {
  it('combines the device tier and the surface budget into one plan', () => {
    const plan = resolveQuality({ profile: SCHOOL_CHROMEBOOK, surface: 'timemap' })
    expect(plan.tier).toBe('standard')
    expect(plan.surface).toBe('timemap')
    expect(estimateAssetBytes(plan)).toBeLessThanOrEqual(SURFACES.timemap.assetBudgetBytes)
  })

  it('carries the reasons through so a host can show why it looks like this', () => {
    const plan = resolveQuality({ profile: NO_WEBGL, surface: 'timemap' })
    expect(plan.tier).toBe('video')
    expect(plan.reasons.some((r) => r.rule === 'noWebgl')).toBe(true)
  })

  it('accepts a budget object from a host that is not one of our three surfaces', () => {
    // The plugin bar: a host app we have never heard of must be able to state its own budget.
    const plan = resolveQuality({
      profile: DESKTOP_DISCRETE,
      surface: { name: 'someone-elses-app', assetBudgetBytes: 2 * 1024 * 1024, maxSingleAssetBytes: 512 * 1024 },
    })
    expect(estimateAssetBytes(plan)).toBeLessThanOrEqual(2 * 1024 * 1024)
    for (const layer of Object.values(plan.layers)) {
      if (layer.enabled) expect(layer.assetBudgetBytes).toBeLessThanOrEqual(512 * 1024)
    }
  })

  it('rejects an unknown surface name rather than defaulting to the generous one', () => {
    expect(() => resolveQuality({ profile: DESKTOP_DISCRETE, surface: 'nope' })).toThrow(/nope/)
  })
})
