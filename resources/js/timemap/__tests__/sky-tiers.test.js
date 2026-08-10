import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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

describe('what these cost on the card', () => {
  /**
   * Video memory is the number that breaks things, and it is the one a file listing cannot show.
   * A texture uploaded from an <img> is RGBA8 whatever the file said, so the 746 KB standard tier
   * is 8 MiB resident — a factor of eleven — and a tier sized against transfer bytes is sized
   * against the wrong quantity by about that much.
   */
  const MiB = 1048576

  /** Pixel dimensions from the file header, so the declared numbers are checked against reality. */
  const dimensionsOf = (buf) => {
    if (buf.toString('ascii', 0, 4) !== 'RIFF' || buf.toString('ascii', 8, 12) !== 'WEBP') {
      throw new Error('not a WebP — the sky ships WebP and this test assumes it')
    }
    const fourcc = buf.toString('ascii', 12, 16)
    if (fourcc === 'VP8X') return { width: 1 + buf.readUIntLE(24, 3), height: 1 + buf.readUIntLE(27, 3) }
    if (fourcc === 'VP8 ') return { width: buf.readUInt16LE(26) & 0x3fff, height: buf.readUInt16LE(28) & 0x3fff }
    const bits = buf.readUInt32LE(21)
    return { width: (bits & 0x3fff) + 1, height: ((bits >> 14) & 0x3fff) + 1 }
  }

  it.each([...SKY_TIERS, SKY_PLACEHOLDER])('$name is really $width wide on disk', (tier) => {
    // A file quietly swapped for a different size would make every resident figure here a lie, and
    // the budget that depends on them a wish.
    const file = readFileSync(resolve(process.cwd(), 'public', tier.url.replace(/^\//, '')))
    expect(dimensionsOf(file)).toEqual({ width: tier.width, height: tier.height })
  })

  it('states resident bytes as RGBA8 with no mipmap overhead', () => {
    // Flat, because the sky is sampled from inside and mipmaps would collapse it. The fleet's
    // budget checker assumes a third again for mips; for these textures that overstates by 33%.
    for (const tier of [...SKY_TIERS, SKY_PLACEHOLDER]) {
      expect(tier.resident).toBe(tier.width * tier.height * 4)
    }
    expect(SKY_TIERS[0].resident).toBe(32 * MiB)
    expect(SKY_TIERS[1].resident).toBe(8 * MiB)
    expect(SKY_PLACEHOLDER.resident).toBe(2 * MiB)
  })

  it('keeps the worst case inside a third of the surface budget', () => {
    // The timemap surface allows 96 MiB resident across every texture on it, shared with the cloud
    // field, the wind field and the moon. The sky's peak is the high tier plus the placeholder it
    // has not let go of yet, and that lasts one decode.
    const peak = SKY_TIERS[0].resident + SKY_PLACEHOLDER.resident
    expect(peak).toBe(34 * MiB)
    expect(peak).toBeLessThan(96 * MiB / 2)
    // Steady state, once the placeholder is released.
    expect(SKY_TIERS[0].resident).toBe(32 * MiB)
  })

  it('costs the machines that need it least the most', () => {
    // Sanity on the direction of the ladder: dropping a tier must actually save something, and
    // save more than it saves on the wire, because that is the point.
    const [high, standard] = SKY_TIERS
    expect(standard.resident * 4).toBe(high.resident)
    expect(high.resident / high.bytes).toBeGreaterThan(8)
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
