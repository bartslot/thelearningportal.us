import { describe, it, expect } from 'vitest'
import { chooseSkyTier, readDeviceFacts, SKY_TIERS, SKY_PLACEHOLDER } from '../sky-tiers.js'

/**
 * Which panorama a device is given.
 *
 * This is the only decision in the layer whose wrong answer is invisible to the person making it.
 * Everything else fails on the reviewer's screen; this fails on a Chromebook in a classroom, as a
 * black sky with no error, while looking perfect on the machine it was chosen on. So it is tested
 * against devices rather than against itself.
 */

const HIGH = SKY_TIERS[0]
const STANDARD = SKY_TIERS[SKY_TIERS.length - 1]

const DEVICES = {
  'a teacher\'s laptop': { maxTextureSize: 16384, deviceMemory: 16, hardwareConcurrency: 12 },
  'a mid-range Windows machine': { maxTextureSize: 8192, deviceMemory: 8, hardwareConcurrency: 8 },
  'a school Chromebook': { maxTextureSize: 8192, deviceMemory: 2, hardwareConcurrency: 2 },
  'an old tablet': { maxTextureSize: 4096, deviceMemory: 2, hardwareConcurrency: 4 },
  'something ancient': { maxTextureSize: 2048, deviceMemory: 1, hardwareConcurrency: 2 },
  // Safari and Firefox report neither memory nor, in Safari's case, anything useful about cores.
  'a Mac in Safari, which admits to nothing': { maxTextureSize: 16384 },
}

describe('nothing is ever handed a texture it cannot bind', () => {
  /**
   * The whole reason this file exists. Past MAX_TEXTURE_SIZE a texture does not soften — it does
   * not bind, the sampler returns black, and no error is raised anywhere.
   */
  it.each(Object.entries(DEVICES))('%s gets something within its limit', (_name, facts) => {
    const { tier } = chooseSkyTier(facts)
    expect(tier.width).toBeLessThanOrEqual(facts.maxTextureSize)
  })

  it('falls back to the placeholder rather than nothing at all', () => {
    // A device that cannot manage even the smallest tier still gets a sky. A smaller sky, but the
    // alternative is black space, which reads as broken rather than as modest.
    const { tier, reason } = chooseSkyTier({ maxTextureSize: 512 })
    expect(tier).toBe(SKY_PLACEHOLDER)
    expect(reason).toContain('512')
  })

  it('treats a driver that says nothing as the weakest possible', () => {
    // getParameter returning undefined must not read as "no limit".
    expect(chooseSkyTier({}).tier).toBe(SKY_PLACEHOLDER)
    expect(chooseSkyTier({ maxTextureSize: 0 }).tier).toBe(SKY_PLACEHOLDER)
  })
})

describe('who gets the big one', () => {
  it('gives it to a machine with room for it', () => {
    expect(chooseSkyTier(DEVICES['a teacher\'s laptop']).tier).toBe(HIGH)
    expect(chooseSkyTier(DEVICES['a mid-range Windows machine']).tier).toBe(HIGH)
  })

  it('does not give it to a Chromebook that could technically bind it', () => {
    // This is the case MAX_TEXTURE_SIZE alone gets wrong: 8192 is plenty of texture limit, and the
    // machine would still spend a second and a half decoding a file it did not need.
    const { tier, reason } = chooseSkyTier(DEVICES['a school Chromebook'])
    expect(tier).toBe(STANDARD)
    expect(reason).toMatch(/memory|cores/)
  })

  it('gives it to a Mac that will not say how much memory it has', () => {
    // Safari and Firefox report no deviceMemory at all. Reading absence as constraint would send
    // every Mac in the building the small one, quietly, forever.
    expect(chooseSkyTier(DEVICES['a Mac in Safari, which admits to nothing']).tier).toBe(HIGH)
  })
})

describe('the signals that are instructions rather than hints', () => {
  const capable = DEVICES['a teacher\'s laptop']

  it('obeys data-saver even on a fast machine', () => {
    const { tier, reason } = chooseSkyTier({ ...capable, saveData: true })
    expect(tier).toBe(STANDARD)
    expect(reason).toContain('data-saver')
  })

  it.each(['slow-2g', '2g', '3g'])('steps down on a %s connection', (effectiveType) => {
    expect(chooseSkyTier({ ...capable, effectiveType }).tier).toBe(STANDARD)
  })

  it('does not step down on 4g', () => {
    expect(chooseSkyTier({ ...capable, effectiveType: '4g' }).tier).toBe(HIGH)
  })
})

describe('always says why', () => {
  it.each(Object.entries(DEVICES))('%s is given a reason worth reading', (_name, facts) => {
    const { reason } = chooseSkyTier(facts)
    expect(typeof reason).toBe('string')
    expect(reason.length).toBeGreaterThan(10)
  })
})

describe('reading the device', () => {
  it('asks the driver for its limit and the browser for the rest', () => {
    const gl = { MAX_TEXTURE_SIZE: 0x0d33, getParameter: (name) => (name === 0x0d33 ? 8192 : null) }
    const facts = readDeviceFacts(gl, {
      deviceMemory: 8, hardwareConcurrency: 8, connection: { saveData: false, effectiveType: '4g' },
    })
    expect(facts).toEqual({
      maxTextureSize: 8192, deviceMemory: 8, hardwareConcurrency: 8, saveData: false, effectiveType: '4g',
    })
  })

  it('survives a browser with no connection API and a context with no getParameter', () => {
    // Firefox has no navigator.connection at all, and a lost context has no getParameter. Neither
    // may throw here: an exception in this path takes the whole layer down at onAdd.
    expect(() => readDeviceFacts({}, {})).not.toThrow()
    expect(readDeviceFacts({}, {}).maxTextureSize).toBe(0)
    expect(readDeviceFacts(null, null).saveData).toBe(false)
  })
})
