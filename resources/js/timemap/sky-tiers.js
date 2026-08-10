/**
 * sky-tiers.js — which panorama this device gets.
 *
 * The sky is a plugin asset now: the same layer has to look its best on a teacher's laptop and
 * still work on whatever the school bought in 2019. One fixed resolution cannot do both, and the
 * failure is not symmetric. Too small on a good machine is a slightly soft sky that nobody
 * complains about. Too large on a weak one is not a soft sky — it is NO sky.
 *
 * ── THE CHECK THAT CARRIES THE WEIGHT ─────────────────────────────────────────────────────────
 *
 * Past the driver's `MAX_TEXTURE_SIZE`, a texture does not degrade. It does not bind at all. The
 * layer draws, the shader runs, the sampler returns black, and there is no error anywhere — a black
 * sky on exactly the hardware nobody reviewing this owns. That is why the limit is read at runtime
 * and a tier is chosen from it, rather than a size being picked once by whoever had the fastest
 * machine on the day.
 *
 * ── WHY THE TOP TIER IS 4096 AND NOT 8192 ─────────────────────────────────────────────────────
 *
 * Measured, and it is the unusual case where more is worse. Rendering the same frame through each
 * panorama and differencing the sky pixels, the steps do not converge — 1k→2k is 0.97 levels out of
 * 255, 2k→4k is 0.90, and 4k→8k is 1.34. Detail that were really there would converge; this is each
 * resolution carrying a different realisation of the source's shot noise. Side by side at the
 * galactic bulge the 8192 is visibly GRAINIER than the 2048, with the same dust lanes in the same
 * places, and it costs 20 MB and 1.2 seconds of blocked main thread on an M3 Pro.
 *
 * So the top tier is the largest size that still shows convergence, and it is also the largest
 * texture every WebGL device is required to support. Above it there is nothing to buy.
 * `docs/sky-resolution.md` has the full workings.
 *
 * ── WHY MAX_TEXTURE_SIZE IS NOT THE WHOLE ANSWER ──────────────────────────────────────────────
 *
 * Both real tiers fit inside 4096, which every device supports, so the texture limit alone would
 * hand the big file to a Chromebook that can bind it and then spend a second and a half decoding
 * it. It rules tiers OUT; it cannot rule the top tier IN. So the choice also reads the cheap,
 * boring signals a browser will actually tell you — memory, cores, and whether the user has asked
 * for less data — and treats a missing signal as "not obviously constrained" rather than guessing.
 */

/**
 * Resident cost of one of these panoramas, in bytes.
 *
 * TRANSFER BYTES ARE THE MISLEADING NUMBER. A texture uploaded from an `<img>` is RGBA8 whatever
 * the file said, so the 746 KB standard tier is 8 MiB on the card — a factor of eleven — and the
 * 3.6 MB high tier is 32 MiB. On the machines the low tier exists for, video memory is the binding
 * limit and bandwidth is not, so this is the number a tier should be sized against.
 *
 * NO MIPMAP OVERHEAD, and that is not an oversight. A mipmapped texture costs a third again, and
 * the fleet's budget checker assumes it — reasonably, since every other field on this map is
 * wrapped on the ground and wants them. A sky must not have them: the camera is inside it, so the
 * UV derivative per pixel is enormous and the sampler would pick a 2x2 level over most of the
 * frame. `starfield.js` acquires with `mipmap: false`, so these are flat and this arithmetic is
 * the whole story.
 */
const residentBytes = (width, height) => width * height * 4

/**
 * The ladder, largest first. Every entry is the same image; only the sampling grid changes.
 *
 * `bytes` is what it weighs on the wire, `resident` what it costs on the card, and `decodeMs` what
 * it cost on an M3 Pro — several times that on the hardware the low tier exists for. They are here
 * so the next person tuning this has the numbers in front of them rather than in a commit message.
 */
export const SKY_TIERS = [
  {
    name: 'high', width: 4096, height: 2048, url: '/img/map/sky/milkyway-4k.webp',
    bytes: 3_680_220, resident: residentBytes(4096, 2048), decodeMs: 235,
  },
  {
    name: 'standard', width: 2048, height: 1024, url: '/img/map/sky/milkyway-2k.webp',
    bytes: 764_012, resident: residentBytes(2048, 1024), decodeMs: 51,
  },
]

/**
 * The one shown immediately, while the chosen tier is still coming down the wire. It is also the
 * floor: a device that cannot manage 2048 gets this and still has a sky.
 *
 * It is released the moment the chosen tier lands, so the two are resident together only for the
 * span of one decode — the layer's steady-state cost is one panorama, never two.
 */
export const SKY_PLACEHOLDER = {
  name: 'placeholder', width: 1024, height: 512, url: '/img/map/sky/milkyway-1k.webp',
  bytes: 45_160, resident: residentBytes(1024, 512), decodeMs: 5,
}

/** Below this, a machine is told it is not getting the big one. Gigabytes, as the browser reports. */
const MEMORY_FOR_HIGH_GB = 4
/** Cores are a rough proxy for everything else. Two-core machines are Chromebooks and old tablets. */
const CORES_FOR_HIGH = 4

/**
 * Pick a tier for this device.
 *
 * Written as a pure function of the things it reads, so the decision can be tested without a GPU —
 * which matters, because this is the code that decides whether a whole class sees a sky.
 *
 * @param {object}  facts
 * @param {number}  facts.maxTextureSize   gl.getParameter(gl.MAX_TEXTURE_SIZE)
 * @param {number} [facts.deviceMemory]    navigator.deviceMemory, in GB. Absent on Safari and Firefox.
 * @param {number} [facts.hardwareConcurrency]
 * @param {boolean}[facts.saveData]        navigator.connection.saveData
 * @param {string} [facts.effectiveType]   navigator.connection.effectiveType, e.g. '3g'
 * @returns {{tier: object, reason: string}}
 */
export const chooseSkyTier = ({
  maxTextureSize = 0,
  deviceMemory = null,
  hardwareConcurrency = null,
  saveData = false,
  effectiveType = null,
} = {}) => {
  // Nothing that will not bind. This is the check that turns a black sky into a soft one.
  const affordable = SKY_TIERS.filter((tier) => tier.width <= maxTextureSize)
  if (affordable.length === 0) {
    return {
      tier: SKY_PLACEHOLDER,
      reason: `MAX_TEXTURE_SIZE is ${maxTextureSize}, below every tier — falling back to the placeholder`,
    }
  }

  const smallest = affordable[affordable.length - 1]
  const largest = affordable[0]
  if (largest === smallest) return { tier: largest, reason: `only ${largest.name} fits MAX_TEXTURE_SIZE ${maxTextureSize}` }

  // The user asking for less data is an instruction, not a hint.
  if (saveData) return { tier: smallest, reason: 'the browser is in data-saver mode' }
  if (effectiveType && /^(slow-2g|2g|3g)$/.test(effectiveType)) {
    return { tier: smallest, reason: `the connection reports ${effectiveType}` }
  }
  // A missing signal is not a weak machine — Safari and Firefox do not report memory at all, and
  // reading absence as constraint would send every Mac the small one.
  if (Number.isFinite(deviceMemory) && deviceMemory < MEMORY_FOR_HIGH_GB) {
    return { tier: smallest, reason: `the device reports ${deviceMemory} GB of memory` }
  }
  if (Number.isFinite(hardwareConcurrency) && hardwareConcurrency < CORES_FOR_HIGH) {
    return { tier: smallest, reason: `the device reports ${hardwareConcurrency} cores` }
  }
  return { tier: largest, reason: `nothing says otherwise, and MAX_TEXTURE_SIZE is ${maxTextureSize}` }
}

/** What this browser will admit to, in the shape `chooseSkyTier` wants. */
export const readDeviceFacts = (gl, navigatorRef = typeof navigator === 'undefined' ? null : navigator) => {
  const connection = navigatorRef?.connection ?? null
  return {
    maxTextureSize: gl?.getParameter?.(gl.MAX_TEXTURE_SIZE) ?? 0,
    deviceMemory: navigatorRef?.deviceMemory ?? null,
    hardwareConcurrency: navigatorRef?.hardwareConcurrency ?? null,
    saveData: connection?.saveData ?? false,
    effectiveType: connection?.effectiveType ?? null,
  }
}
