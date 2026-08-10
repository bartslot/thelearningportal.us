/**
 * tiers.js — the policy. What each class of device gets, stated as data.
 *
 * Four tiers, best first: `high`, `standard`, `minimal`, `video`. A tier is a plain record, so the
 * decision is inspectable, diffable and testable against synthetic profiles rather than against
 * whatever machine happens to be running the suite.
 *
 * Two budgets, and they are not the same thing:
 *
 * - **VRAM** — what is resident on the GPU. Per tier, replacing the pyramid's flat 96 MB.
 * - **Assets** — what crosses the network. Per *surface* (`surfaces.js`), because the Time-Map, a
 *   lesson map and a landing hero have completely different ceilings for identical hardware.
 *
 * Every texture dimension here is a *request*. `planForTier` puts each one through `fitTexture`
 * against the device's real `MAX_TEXTURE_SIZE` before it reaches a layer, because a texture over
 * that limit does not degrade — it fails to bind and draws nothing, silently.
 */

import { fitTexture } from './capabilities.js'

/** Best to worst. Index order is the comparison order; nothing else should encode it. */
export const TIER_ORDER = ['high', 'standard', 'minimal', 'video']

const MB = 1024 * 1024

/**
 * Compressed bytes a layer is allowed per texture pixel.
 *
 * These are ceilings handed to layer owners, not measurements of files that exist. A layer that
 * cannot hit its number says so and gets a different one; a layer that quietly ships double is the
 * failure this table exists to make visible.
 */
const ASSET_BYTES_PER_PIXEL = {
  sky: 0.117,     // starfield panorama: mostly black, compresses hard
  sun: 0.5,       // small LUT and flare sprite, little to compress
  relief: 0.4,    // packed normal + shoreline, only used when the pyramid is off
  ocean: 0.4,
  clouds: 0.25,   // a coverage field, effectively single-channel
  orbits: 0,      // geometry, generated at runtime
}

/** Aspect of each layer's texture, as height ÷ width. Global fields are equirectangular. */
const ASPECT = { sky: 0.5, sun: 1, relief: 0.5, ocean: 0.5, clouds: 0.5, orbits: 1 }

/** Bytes per texel once resident. The sky carries no alpha and does not need to pay for one. */
const CHANNELS = { sky: 3, sun: 4, relief: 4, ocean: 4, clouds: 4, orbits: 4 }

/**
 * The tier table.
 *
 * `source` says where a layer's detail comes from: `pyramid` streams tiles at real LOD, `global` is
 * one equirectangular field, `procedural` is generated in the shader, `none` is off. A layer on the
 * pyramid costs no global texture at all, which is most of why the top tier fits its budget.
 */
export const TIERS = {
  high: {
    id: 'high',
    targetFps: 60,
    maxDpr: 2,
    vramBudgetBytes: 256 * MB,
    pyramid: { enabled: true, budgetBytes: 96 * MB, maxTiles: 64, targetPixelsPerTexel: 1 },
    layers: {
      sky: { enabled: true, source: 'global', textureSize: 8192, effects: { parallax: true, twinkle: true } },
      sun: { enabled: true, source: 'global', textureSize: 512, effects: { bloom: true, framebufferBloom: true, godRays: true } },
      relief: { enabled: true, source: 'pyramid', textureSize: 0, globalTextureSize: 2048, effects: { selfShadow: true, cloudShadow: true } },
      ocean: { enabled: true, source: 'pyramid', textureSize: 0, globalTextureSize: 2048, effects: { depthTint: true, specular: true, waves: true } },
      clouds: { enabled: true, source: 'global', textureSize: 2048, effects: { volumetric: true, shadows: true, drift: true } },
      orbits: { enabled: true, source: 'procedural', textureSize: 0, effects: { kepler: true, trails: true } },
    },
  },

  standard: {
    id: 'standard',
    targetFps: 60,
    maxDpr: 1.5,
    vramBudgetBytes: 96 * MB,
    pyramid: { enabled: true, budgetBytes: 40 * MB, maxTiles: 32, targetPixelsPerTexel: 1.5 },
    layers: {
      sky: { enabled: true, source: 'global', textureSize: 4096, effects: { parallax: true, twinkle: false } },
      sun: { enabled: true, source: 'global', textureSize: 256, effects: { bloom: true, framebufferBloom: false, godRays: false } },
      relief: { enabled: true, source: 'pyramid', textureSize: 0, globalTextureSize: 1024, effects: { selfShadow: true, cloudShadow: false } },
      ocean: { enabled: true, source: 'pyramid', textureSize: 0, globalTextureSize: 1024, effects: { depthTint: true, specular: true, waves: false } },
      clouds: { enabled: true, source: 'global', textureSize: 1024, effects: { volumetric: false, shadows: false, drift: true } },
      orbits: { enabled: true, source: 'procedural', textureSize: 0, effects: { kepler: true, trails: false } },
    },
  },

  minimal: {
    id: 'minimal',
    targetFps: 30,
    maxDpr: 1,
    vramBudgetBytes: 32 * MB,
    pyramid: { enabled: false, budgetBytes: 0, maxTiles: 0, targetPixelsPerTexel: 3 },
    layers: {
      sky: { enabled: true, source: 'global', textureSize: 2048, effects: { parallax: false, twinkle: false } },
      sun: { enabled: true, source: 'global', textureSize: 128, effects: { bloom: false, framebufferBloom: false, godRays: false } },
      relief: { enabled: true, source: 'global', textureSize: 1024, globalTextureSize: 1024, effects: { selfShadow: false, cloudShadow: false } },
      ocean: { enabled: true, source: 'global', textureSize: 1024, globalTextureSize: 1024, effects: { depthTint: true, specular: false, waves: false } },
      clouds: { enabled: false, source: 'none', textureSize: 0, effects: {} },
      orbits: { enabled: true, source: 'procedural', textureSize: 0, effects: { kepler: true, trails: false } },
    },
  },

  video: {
    id: 'video',
    targetFps: 30,
    maxDpr: 1,
    vramBudgetBytes: 0,
    pyramid: { enabled: false, budgetBytes: 0, maxTiles: 0, targetPixelsPerTexel: 0 },
    layers: {
      sky: { enabled: false, source: 'none', textureSize: 0, effects: {} },
      sun: { enabled: false, source: 'none', textureSize: 0, effects: {} },
      relief: { enabled: false, source: 'none', textureSize: 0, effects: {} },
      ocean: { enabled: false, source: 'none', textureSize: 0, effects: {} },
      clouds: { enabled: false, source: 'none', textureSize: 0, effects: {} },
      orbits: { enabled: false, source: 'none', textureSize: 0, effects: {} },
    },
  },
}

/** Effects that are motion for motion's sake, switched off when the OS asks for less of it. */
const MOTION_EFFECTS = ['twinkle', 'drift', 'waves', 'trails', 'godRays']

const assertTier = (name, label) => {
  if (!TIER_ORDER.includes(name)) {
    throw new Error(`quality: unknown ${label} "${name}" — expected one of ${TIER_ORDER.join(', ')}`)
  }
  return name
}

/** The worse of two tiers. `worse('high', 'minimal') === 'minimal'`. */
const worse = (a, b) => (TIER_ORDER.indexOf(b) > TIER_ORDER.indexOf(a) ? b : a)

/**
 * Every rule that can lower the ceiling, in one list so the whole policy reads at once.
 *
 * A rule fires on numbers or on a measured frame time. Nothing here looks at a GPU string or a user
 * agent: those are wrong often enough that a device would be misjudged in exactly the direction
 * that is hardest to notice.
 */
/**
 * A probe reading whose calibration constant has not been verified may only trigger the extreme
 * rule, and only at a threshold that survives being wrong by an order of magnitude. Fine-grained
 * judgement from an unverified number would downgrade every device silently and look deliberate.
 */
const trusted = (probe) => probe && probe.calibrated !== false
const probeCeiling = (probe) => (probe.calibrated === false ? 1000 : 100)

const RULES = [
  { rule: 'noWebgl', to: 'video', when: (p) => p.webglVersion === 0 },
  { rule: 'probeUnusable', to: 'video', when: (p) => p.probe && p.probe.medianMs > probeCeiling(p.probe) },
  // No texture arrays means no tile pyramid, so the top tier's whole detail story is unavailable.
  { rule: 'noTextureArrays', to: 'standard', when: (p) => !p.textureArrays },
  { rule: 'tinyTextures', to: 'minimal', when: (p) => p.maxTextureSize > 0 && p.maxTextureSize < 4096 },
  // `deviceMemory` is undefined on Safari. Absent must read as unknown, never as small.
  { rule: 'smallMemory', to: 'minimal', when: (p) => p.deviceMemoryGb != null && p.deviceMemoryGb <= 2 },
  { rule: 'modestMemory', to: 'standard', when: (p) => p.deviceMemoryGb != null && p.deviceMemoryGb <= 4 },
  { rule: 'fewThreads', to: 'minimal', when: (p) => p.cpuThreads != null && p.cpuThreads <= 2 },
  { rule: 'saveData', to: 'minimal', when: (p) => p.saveData === true },
  { rule: 'probeSlow', to: 'minimal', when: (p) => trusted(p.probe) && p.probe.medianMs > 25 },
  { rule: 'probeModest', to: 'standard', when: (p) => trusted(p.probe) && p.probe.medianMs > 8 },
]

/**
 * Choose a tier for a device.
 *
 * @param {object}  profile             from `readCapabilities`
 * @param {object} [opts]
 * @param {string} [opts.override]      host app pins a tier outright
 * @param {string} [opts.preference]    user asks for a tier; may lower, never raises
 * @returns {{ tier: string, reasons: Array, overridden: boolean, preference: string|null }}
 */
export const selectTier = (profile, { override = null, preference = null } = {}) => {
  if (override) {
    return { tier: assertTier(override, 'tier override'), reasons: [], overridden: true, preference: null }
  }

  let tier = 'high'
  const reasons = []
  for (const { rule, to, when } of RULES) {
    if (!when(profile)) continue
    const next = worse(tier, to)
    if (next === tier) continue
    reasons.push({ rule, from: tier, to: next })
    tier = next
  }

  // A preference is a request for *less*. Asking a 2 GB phone for `high` does not make it one.
  if (preference && preference !== 'auto') {
    assertTier(preference, 'tier preference')
    const next = worse(tier, preference)
    if (next !== tier) reasons.push({ rule: 'userPreference', from: tier, to: next })
    tier = next
  }

  return { tier, reasons, overridden: false, preference: preference && preference !== 'auto' ? preference : null }
}

/**
 * Bytes a texture occupies once resident.
 *
 * @param {number} size                 width in texels
 * @param {object} [opts]
 * @param {number} [opts.channels=4]    bytes per texel
 * @param {number} [opts.aspect=1]      height ÷ width
 * @param {boolean} [opts.mipmaps]      add the mip chain, which converges on a further third
 */
export const textureBytes = (size, { channels = 4, aspect = 1, mipmaps = false } = {}) => {
  const base = size * Math.round(size * aspect) * channels
  return mipmaps ? Math.round((base * 4) / 3) : base
}

/** The compressed-byte ceiling handed to a layer for a texture of this size. */
const assetBytesFor = (layerName, size) => {
  const perPixel = ASSET_BYTES_PER_PIXEL[layerName] ?? 0.25
  return Math.round(size * Math.round(size * (ASPECT[layerName] ?? 1)) * perPixel)
}

/**
 * Turn a tier into a plan for one specific device.
 *
 * This is where the texture ceiling is enforced and where the pyramid falls back to a global field
 * on hardware that cannot run it.
 */
export const planForTier = (tierName, profile) => {
  const tier = TIERS[assertTier(tierName, 'tier')]
  const pyramidUsable = tier.pyramid.enabled && profile.textureArrays === true
  const layers = {}

  for (const [name, spec] of Object.entries(tier.layers)) {
    // A layer routed to the pyramid needs a global field instead when the pyramid cannot run.
    const source = spec.source === 'pyramid' && !pyramidUsable ? 'global' : spec.source
    const wanted = source === 'global' && spec.source === 'pyramid' ? spec.globalTextureSize : spec.textureSize
    const { size, clamped, requested } = spec.enabled && wanted
      ? fitTexture(wanted, profile)
      : { size: 0, clamped: false, requested: wanted || 0 }

    const effects = { ...spec.effects }
    if (profile.reducedMotion) for (const key of MOTION_EFFECTS) if (key in effects) effects[key] = false

    layers[name] = {
      enabled: spec.enabled && (source !== 'global' || size > 0),
      source,
      textureSize: size,
      textureHeight: Math.round(size * (ASPECT[name] ?? 1)),
      channels: CHANNELS[name] ?? 4,
      requestedTextureSize: requested,
      clamped,
      assetBudgetBytes: source === 'global' && size > 0 ? assetBytesFor(name, size) : 0,
      effects,
    }
  }

  return {
    tier: tier.id,
    targetFps: tier.targetFps,
    maxDpr: tier.maxDpr,
    renderDpr: Math.min(profile.dpr || 1, tier.maxDpr),
    vramBudgetBytes: tier.vramBudgetBytes,
    reducedMotion: !!profile.reducedMotion,
    pyramid: { ...tier.pyramid, enabled: pyramidUsable },
    layers,
    video: { enabled: tier.id === 'video' },
    enabled: true,
    reductions: [],
  }
}

/** Resident GPU bytes this plan implies: its global fields plus whatever the pyramid may hold. */
export const estimateVramBytes = (plan) => {
  const textures = Object.entries(plan.layers).reduce((total, [name, layer]) => (
    layer.enabled && layer.source === 'global' && layer.textureSize
      ? total + textureBytes(layer.textureSize, { channels: layer.channels, aspect: ASPECT[name] ?? 1 })
      : total
  ), 0)
  return textures + (plan.pyramid.enabled ? plan.pyramid.budgetBytes : 0)
}

/** Compressed bytes this plan is allowed to pull over the network before it is drawing. */
export const estimateAssetBytes = (plan) => Object.values(plan.layers)
  .reduce((total, layer) => (layer.enabled ? total + layer.assetBudgetBytes : total), 0)

/** Exported for `surfaces.js`, which halves sizes and needs the same arithmetic. */
export const layerAssetBytes = assetBytesFor
export const LAYER_ASPECT = ASPECT
