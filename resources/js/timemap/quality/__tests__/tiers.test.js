import { describe, it, expect } from 'vitest'
import {
  TIER_ORDER,
  TIERS,
  selectTier,
  planForTier,
  textureBytes,
  estimateVramBytes,
  estimateAssetBytes,
} from '../tiers.js'
import { makeProfile, ALL_PROFILES, DESKTOP_DISCRETE, MACBOOK_INTEGRATED, IPAD, SCHOOL_CHROMEBOOK, OLD_CHROMEBOOK_WEBGL1, BUDGET_ANDROID, DATA_SAVER_PHONE, SOFTWARE_RENDERER, NO_WEBGL } from '../__fixtures__/profiles.js'

describe('textureBytes', () => {
  it('is the plain arithmetic, checked against a value computed by hand', () => {
    // 8192 * 8192 * 4 bytes = 268,435,456. Written out because this is the number that decides an
    // 8192 RGBA8 texture can never fit any tier budget, and a formula that is subtly wrong here
    // would let one through.
    expect(textureBytes(8192, { channels: 4 })).toBe(268435456)
    expect(textureBytes(4096, { channels: 4 })).toBe(67108864)
    expect(textureBytes(2048, { channels: 3 })).toBe(12582912)
  })

  it('adds a third for a full mip chain', () => {
    // The mip pyramid of a square texture converges on 4/3 of the base level.
    expect(textureBytes(1024, { channels: 4, mipmaps: true })).toBe(Math.round(1024 * 1024 * 4 * 4 / 3))
  })
})

describe('tier selection', () => {
  it('gives a discrete GPU the top tier', () => {
    expect(selectTier(DESKTOP_DISCRETE).tier).toBe('high')
  })

  it('does not punish Safari for not implementing deviceMemory', () => {
    // Both report `undefined`, which must read as "unknown", never as "small".
    expect(selectTier(MACBOOK_INTEGRATED).tier).toBe('high')
    expect(selectTier(IPAD).tier).not.toBe('minimal')
  })

  it('puts a current school Chromebook on standard, not high and not minimal', () => {
    expect(selectTier(SCHOOL_CHROMEBOOK).tier).toBe('standard')
  })

  it('drops a WebGL1 device below the tiers that need texture arrays', () => {
    const decision = selectTier(OLD_CHROMEBOOK_WEBGL1)
    expect(TIER_ORDER.indexOf(decision.tier)).toBeGreaterThanOrEqual(TIER_ORDER.indexOf('standard'))
    expect(planForTier(decision.tier, OLD_CHROMEBOOK_WEBGL1).pyramid.enabled).toBe(false)
  })

  it('sends a weak phone to minimal', () => {
    expect(selectTier(BUDGET_ANDROID).tier).toBe('minimal')
  })

  it('sends a device with no WebGL straight to video', () => {
    expect(selectTier(NO_WEBGL).tier).toBe('video')
  })

  it('catches a software rasteriser that every static signal calls capable', () => {
    // 8192 textures, WebGL2, texture arrays, 8 GB. Only the measured frame time gives it away.
    const withoutProbe = selectTier(makeProfile({ ...SOFTWARE_RENDERER, probe: null }))
    expect(withoutProbe.tier).toBe('high')
    expect(selectTier(SOFTWARE_RENDERER).tier).toBe('video')
  })

  it('honours a data-saver request without calling the device slow', () => {
    const decision = selectTier(DATA_SAVER_PHONE)
    expect(decision.tier).toBe('minimal')
    expect(decision.reasons.map((r) => r.rule)).toContain('saveData')
  })

  it('explains itself: every downgrade names the rule that caused it', () => {
    const decision = selectTier(BUDGET_ANDROID)
    expect(decision.reasons.length).toBeGreaterThan(0)
    for (const reason of decision.reasons) {
      expect(reason).toMatchObject({ rule: expect.any(String), from: expect.any(String), to: expect.any(String) })
      expect(TIER_ORDER).toContain(reason.to)
    }
  })

  it('lets the host app pin a tier, and records that it was pinned', () => {
    const decision = selectTier(BUDGET_ANDROID, { override: 'high' })
    expect(decision.tier).toBe('high')
    expect(decision.overridden).toBe(true)
  })

  it('lets a user ask for less than the device could manage, but never silently for more', () => {
    const quieter = selectTier(DESKTOP_DISCRETE, { preference: 'minimal' })
    expect(quieter.tier).toBe('minimal')

    // A preference is a request, not an override: asking a 2 GB phone for `high` must not grant it.
    const greedy = selectTier(BUDGET_ANDROID, { preference: 'high' })
    expect(greedy.tier).toBe('minimal')
  })

  it('rejects a tier name it does not know rather than guessing', () => {
    expect(() => selectTier(DESKTOP_DISCRETE, { override: 'ultra' })).toThrow(/ultra/)
  })
})

describe('the texture ceiling, which is the failure this system exists to prevent', () => {
  it('never asks any device for a texture larger than it can bind', () => {
    // A texture above MAX_TEXTURE_SIZE does not degrade — it fails to bind, draws nothing, and
    // raises no error. Checked across the whole matrix rather than for one lucky pairing.
    for (const [name, profile] of Object.entries(ALL_PROFILES)) {
      if (profile.webglVersion === 0) continue
      for (const tier of TIER_ORDER) {
        if (tier === 'video') continue
        const plan = planForTier(tier, profile)
        for (const [layerName, layer] of Object.entries(plan.layers)) {
          if (!layer.enabled || !layer.textureSize) continue
          expect(
            layer.textureSize,
            `${name} / ${tier} / ${layerName} asks for ${layer.textureSize} against a ceiling of ${profile.maxTextureSize}`,
          ).toBeLessThanOrEqual(profile.maxTextureSize)
        }
      }
    }
  })

  it('records that it clamped, so a layer can tell reduced from intended', () => {
    const plan = planForTier('high', SCHOOL_CHROMEBOOK)
    const clamped = Object.values(plan.layers).filter((l) => l.clamped)
    expect(clamped.length).toBeGreaterThan(0)
  })
})

describe('budgets', () => {
  it('keeps every tier inside its own VRAM budget', () => {
    for (const tier of TIER_ORDER) {
      if (tier === 'video') continue
      const plan = planForTier(tier, DESKTOP_DISCRETE)
      expect(estimateVramBytes(plan), `${tier}`).toBeLessThanOrEqual(plan.vramBudgetBytes)
    }
  })

  it('gives each tier its own memory budget instead of one flat number', () => {
    const budgets = TIER_ORDER.filter((t) => t !== 'video').map((t) => TIERS[t].pyramid.budgetBytes)
    expect(new Set(budgets).size).toBe(budgets.length)
    // The top tier keeps the pyramid's existing default, so nothing regresses for the session that
    // measured against it.
    expect(TIERS.high.pyramid.budgetBytes).toBe(96 << 20)
  })

  it('never lets a single asset ceiling exceed 4 MB', () => {
    for (const tier of TIER_ORDER) {
      if (tier === 'video') continue
      const plan = planForTier(tier, DESKTOP_DISCRETE)
      for (const [name, layer] of Object.entries(plan.layers)) {
        expect(layer.assetBudgetBytes, `${tier}/${name}`).toBeLessThanOrEqual(4 * 1024 * 1024)
      }
    }
  })

  it('keeps the top tier inside the 8 MB Time-Map asset budget', () => {
    expect(estimateAssetBytes(planForTier('high', DESKTOP_DISCRETE))).toBeLessThanOrEqual(8 * 1024 * 1024)
  })

  it('spends less the further down the tiers you go', () => {
    const vram = ['high', 'standard', 'minimal'].map((t) => estimateVramBytes(planForTier(t, DESKTOP_DISCRETE)))
    expect(vram[0]).toBeGreaterThan(vram[1])
    expect(vram[1]).toBeGreaterThan(vram[2])
  })
})

describe('the plan is data a layer can act on', () => {
  it('turns the pyramid off below standard and hands over its budget when it is on', () => {
    expect(planForTier('high', DESKTOP_DISCRETE).pyramid).toMatchObject({ enabled: true, budgetBytes: 96 << 20 })
    expect(planForTier('minimal', DESKTOP_DISCRETE).pyramid.enabled).toBe(false)
  })

  it('moves relief and ocean onto a global field when the pyramid is off', () => {
    const minimal = planForTier('minimal', DESKTOP_DISCRETE)
    expect(minimal.layers.relief.source).toBe('global')
    expect(minimal.layers.ocean.source).toBe('global')
    expect(planForTier('high', DESKTOP_DISCRETE).layers.relief.source).toBe('pyramid')
  })

  it('falls back off the pyramid on a device with no texture arrays even at a tier that wants it', () => {
    const plan = planForTier('standard', OLD_CHROMEBOOK_WEBGL1)
    expect(plan.pyramid.enabled).toBe(false)
    expect(plan.layers.relief.source).toBe('global')
  })

  it('caps device pixel ratio, which is the cheapest lever there is', () => {
    expect(planForTier('high', DESKTOP_DISCRETE).maxDpr).toBeGreaterThan(planForTier('minimal', DESKTOP_DISCRETE).maxDpr)
    expect(planForTier('minimal', BUDGET_ANDROID).renderDpr).toBeLessThanOrEqual(1)
  })

  it('describes the video tier as a video rather than as a set of empty layers', () => {
    const plan = planForTier('video', NO_WEBGL)
    expect(plan.video.enabled).toBe(true)
    expect(Object.values(plan.layers).every((l) => l.enabled === false)).toBe(true)
    expect(plan.pyramid.enabled).toBe(false)
  })
})
