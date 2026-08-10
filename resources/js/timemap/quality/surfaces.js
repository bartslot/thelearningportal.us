/**
 * surfaces.js — the second axis.
 *
 * A tier says what the *device* can take. A surface says what the *place it is embedded in* can
 * afford. Identical hardware gets a different plan on the Time-Map, inside a lesson, and behind a
 * landing hero, and the difference is bytes over the network rather than anything about the GPU.
 *
 * The three budgets here were set before this module existed; they are transcribed, not invented.
 * A host we have never heard of can pass its own budget object instead of naming one of ours —
 * nothing in this file knows what a lesson is.
 */

import { planForTier, selectTier, estimateAssetBytes, layerAssetBytes, LAYER_ASPECT } from './tiers.js'

const KB = 1024
const MB = 1024 * 1024

/** Below this a texture is not worth binding; the layer goes off instead. */
const MIN_TEXTURE_SIZE = 256

/**
 * Which layer gives way first when the budget will not stretch. Least missed at the front.
 *
 * Ordered rather than "shrink whatever is biggest": the biggest asset on a landing hero is the sky,
 * which is the one thing a hero is actually for.
 */
const DROP_ORDER = ['clouds', 'orbits', 'sun', 'ocean', 'relief', 'sky']

export const SURFACES = {
  /** The Time-Map itself. The generous one, and still not unlimited. */
  timemap: {
    name: 'timemap',
    assetBudgetBytes: 8 * MB,
    maxSingleAssetBytes: 4 * MB,
    layers: null, // all of them
  },

  /**
   * A map inside a lesson. Zero by default — a lesson that does not ask for a globe pays nothing,
   * not "a bit", and the module is never imported. One lesson may opt in and is then held to 1.5 MB.
   */
  lessonMap: {
    name: 'lessonMap',
    assetBudgetBytes: 0,
    optInAssetBudgetBytes: 1536 * KB,
    maxSingleAssetBytes: 1 * MB,
    layers: ['sky', 'relief', 'ocean'],
  },

  /** The landing hero. 1 MB, and only after first paint. */
  landingHero: {
    name: 'landingHero',
    assetBudgetBytes: 1 * MB,
    maxSingleAssetBytes: 768 * KB,
    layers: ['sky', 'sun', 'relief', 'ocean', 'clouds'],
  },
}

const resolveSurface = (surface) => {
  if (typeof surface === 'string') {
    const known = SURFACES[surface]
    if (!known) throw new Error(`quality: unknown surface "${surface}" — expected one of ${Object.keys(SURFACES).join(', ')}, or a budget object`)
    return known
  }
  if (surface && typeof surface === 'object') {
    return {
      name: surface.name || 'custom',
      layers: surface.layers ?? null,
      assetBudgetBytes: surface.assetBudgetBytes ?? 8 * MB,
      maxSingleAssetBytes: surface.maxSingleAssetBytes ?? surface.assetBudgetBytes ?? 4 * MB,
    }
  }
  throw new Error('quality: a surface must be a known name or a budget object')
}

/** A copy of a layer at half the texture size, with its byte ceiling recomputed from the new area. */
const halved = (name, layer) => {
  const size = Math.floor(layer.textureSize / 2)
  if (size < MIN_TEXTURE_SIZE) return { ...layer, enabled: false, textureSize: 0, textureHeight: 0, assetBudgetBytes: 0 }
  return {
    ...layer,
    textureSize: size,
    textureHeight: Math.round(size * (LAYER_ASPECT[name] ?? 1)),
    assetBudgetBytes: layerAssetBytes(name, size),
  }
}

const isReducible = (layer) => layer.enabled && layer.source === 'global' && layer.textureSize > 0

/**
 * Fit a tier plan into a surface's byte budget.
 *
 * Three passes, in order: drop what the surface never asked for, shrink anything over the
 * single-asset ceiling, then shrink from the least-missed end until the total fits. Every step is
 * recorded in `reductions`, so a layer owner can tell "you were given less" from "you were given
 * what you asked for".
 */
export const applySurface = (plan, surface) => {
  const budget = resolveSurface(surface)
  const reductions = []
  let layers = { ...plan.layers }

  if (budget.layers) {
    for (const name of Object.keys(layers)) {
      if (budget.layers.includes(name) || !layers[name].enabled) continue
      layers = { ...layers, [name]: { ...layers[name], enabled: false, assetBudgetBytes: 0 } }
      reductions.push({ layer: name, action: 'dropped', why: 'surface did not ask for it' })
    }
  }

  for (const name of Object.keys(layers)) {
    let guard = 16
    while (isReducible(layers[name]) && layers[name].assetBudgetBytes > budget.maxSingleAssetBytes && guard-- > 0) {
      const next = halved(name, layers[name])
      reductions.push({
        layer: name,
        action: next.enabled ? 'halved' : 'dropped',
        why: 'over the single-asset ceiling',
        from: layers[name].textureSize,
        to: next.textureSize,
      })
      layers = { ...layers, [name]: next }
    }
  }

  const total = () => estimateAssetBytes({ ...plan, layers })
  let guard = 64
  while (total() > budget.assetBudgetBytes && guard-- > 0) {
    const name = DROP_ORDER.find((candidate) => isReducible(layers[candidate]))
    if (!name) break
    const next = halved(name, layers[name])
    reductions.push({
      layer: name,
      action: next.enabled ? 'halved' : 'dropped',
      why: 'over the surface budget',
      from: layers[name].textureSize,
      to: next.textureSize,
    })
    layers = { ...layers, [name]: next }
  }

  return {
    ...plan,
    surface: budget.name,
    surfaceBudgetBytes: budget.assetBudgetBytes,
    layers,
    reductions: [...plan.reductions, ...reductions],
  }
}

/** Everything off, for a surface that has not opted in. Shaped like a plan so callers never branch. */
const disabledPlan = (plan, budget, reason) => ({
  ...plan,
  enabled: false,
  surface: budget.name,
  surfaceBudgetBytes: 0,
  pyramid: { ...plan.pyramid, enabled: false },
  layers: Object.fromEntries(Object.entries(plan.layers).map(([name, layer]) => (
    [name, { ...layer, enabled: false, assetBudgetBytes: 0 }]
  ))),
  video: { enabled: false },
  reductions: [{ layer: '*', action: 'dropped', why: reason }],
})

/**
 * The one call a host makes: device capability plus surface budget, in, one plan out.
 *
 * @param {object}  opts
 * @param {object}  opts.profile        from `readCapabilities`
 * @param {string|object} opts.surface  a name from `SURFACES`, or your own budget object
 * @param {boolean} [opts.optIn]        for a surface that is zero-cost until asked
 * @param {string}  [opts.override]     host pins a tier
 * @param {string}  [opts.preference]   user asks for a tier; may lower, never raises
 */
export const resolveQuality = ({ profile, surface, optIn = false, override = null, preference = null }) => {
  const budget = resolveSurface(surface)
  const decision = selectTier(profile, { override, preference })
  const base = planForTier(decision.tier, profile)
  const withReasons = { ...base, reasons: decision.reasons, overridden: decision.overridden, measured: decision.measured }

  const available = optIn && budget.optInAssetBudgetBytes != null
    ? budget.optInAssetBudgetBytes
    : budget.assetBudgetBytes

  if (available <= 0) {
    return disabledPlan(withReasons, budget, optIn ? 'this surface has no budget' : 'this surface is opt-in and was not asked for')
  }

  const fitted = applySurface(withReasons, { ...budget, assetBudgetBytes: available })
  return {
    ...fitted,
    video: {
      ...fitted.video,
      assetBudgetBytes: fitted.video.enabled ? Math.min(budget.maxSingleAssetBytes, available) : 0,
    },
  }
}
