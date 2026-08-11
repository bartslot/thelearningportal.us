/**
 * Adaptive quality for the globe.
 *
 * Highest quality first, then adapt to how it is actually used. A capable machine gets everything;
 * a school Chromebook gets a plan it can hold at 60; a device that cannot run the shaders at all
 * gets a pre-rendered loop. The system decides that itself, from what it measures.
 *
 *     import { createQualityController, readCapabilities } from './quality/index.js'
 *
 *     const quality = createQualityController({
 *       gl,                      // optional: capabilities and probe are read from it
 *       surface: 'timemap',      // or your own { assetBudgetBytes, maxSingleAssetBytes }
 *       onPlanChange (plan, decision) { applyPlan(plan) },
 *       isAnimating: () => tour.isPlaying(),
 *     })
 *
 *     quality.plan            // what every layer should build
 *     quality.frame(deltaMs)  // once per rendered frame
 *     quality.dispose()
 *
 * Nothing in this interface knows what it is drawing for. It takes a GL context or a capability
 * profile, a byte budget, and frame timings; it returns data. That is the whole contract, and it is
 * what lets the same module serve a host that has never heard of this application.
 *
 * ## Where the boundary with the tile pyramid sits
 *
 * The pyramid owns whether it *can* run (`pyramid.supported`, its own `texStorage3D` check) and how
 * it spends what it is given. This module owns whether it *should*, and how much: the plan carries
 * `{ enabled, budgetBytes, maxTiles, targetPixelsPerTexel }` to pass straight to
 * `createTilePyramid`. Where the two disagree about support, the pyramid is right — it is holding
 * the real context — and the plan should be re-resolved with the pyramid off.
 */

import { readCapabilities, fitTexture } from './capabilities.js'
import { runRenderProbe, estimateFrameMs } from './probe.js'
import { TIER_ORDER, TIERS, selectTier, planForTier, textureBytes, estimateVramBytes, estimateAssetBytes } from './tiers.js'
import { SURFACES, applySurface, resolveQuality } from './surfaces.js'
import { createFrameMonitor, summarizeFrames } from './monitor.js'

export {
  readCapabilities,
  fitTexture,
  runRenderProbe,
  estimateFrameMs,
  TIER_ORDER,
  TIERS,
  selectTier,
  planForTier,
  textureBytes,
  estimateVramBytes,
  estimateAssetBytes,
  SURFACES,
  applySurface,
  resolveQuality,
  createFrameMonitor,
  summarizeFrames,
}

/**
 * The video fallback, behind a dynamic import.
 *
 * It is the only part of this system that touches the DOM, and the overwhelming majority of devices
 * never reach the tier that needs it. A static re-export would put its element handling — and,
 * through it, the unpacking shader — into every host's main chunk to serve a case that host will
 * probably never hit.
 *
 *     if (quality.plan.video.enabled) {
 *       const { createVideoFallback } = await loadVideoFallback()
 *       createVideoFallback({ container, sources }).start()
 *     }
 */
export const loadVideoFallback = () => import('./video-fallback.js')

/** The lowest tier runtime measurement is allowed to reach on its own. */
const RUNTIME_FLOOR = 'minimal'

const worse = (a, b) => (TIER_ORDER.indexOf(b) > TIER_ORDER.indexOf(a) ? b : a)

const step = (tier, direction) => {
  const index = TIER_ORDER.indexOf(tier)
  const next = TIER_ORDER[direction === 'down' ? index + 1 : index - 1]
  if (!next) return tier
  // Dropping to the video loop is a decision, not somewhere to slide into while a class is
  // watching. Runtime measurement stops at the floor; choosing video is startup's job.
  if (TIER_ORDER.indexOf(next) > TIER_ORDER.indexOf(RUNTIME_FLOOR)) return tier
  return next
}

/**
 * @param {object}   opts
 * @param {object}  [opts.gl]           live context; capabilities and the probe are read from it
 * @param {object}  [opts.profile]      a capability profile instead of a context (tests, SSR)
 * @param {boolean} [opts.probe=true]   run the render probe when a context is available
 * @param {string|object} opts.surface  a name from SURFACES, or your own budget object
 * @param {boolean} [opts.optIn]        for a surface that costs nothing until asked
 * @param {string}  [opts.override]     pin a tier; runtime measurement will not move it
 * @param {string}  [opts.preference]   user request; may lower the tier, never raises it
 * @param {Function}[opts.onPlanChange] (plan, decision) whenever the plan actually changes
 * @param {Function}[opts.isAnimating]  return true to hold a change back until it can land unseen
 * @param {object}  [opts.monitor]      frame-monitor overrides
 */
export const createQualityController = ({
  gl = null,
  profile: givenProfile = null,
  probe = true,
  surface,
  optIn = false,
  override = null,
  preference = null,
  onPlanChange = null,
  isAnimating = null,
  monitor: monitorOptions = {},
} = {}) => {
  const profile = givenProfile ?? (() => {
    const read = readCapabilities({ gl })
    // `measurable` is false where a timing reading would be produced by an environment that cannot
    // produce one — a hidden page gets no animation frames and has its timers clamped, and a
    // zero-area viewport gives a canvas nothing to draw into. Neither errors, which is exactly why
    // the probe has to be skipped rather than run and quietly believed. The static values are still
    // read and still enforced: a driver's texture limit is a fact regardless of what is on screen.
    if (!probe || !gl || read.measurable === false) return read
    const measured = estimateFrameMs(runRenderProbe({ gl }), read)
    return measured ? { ...read, probe: measured } : read
  })()

  let userPreference = preference
  let runtimeFloor = null
  let pending = null
  let disposed = false

  const build = () => resolveQuality({
    profile,
    surface,
    optIn,
    override,
    preference: [userPreference, runtimeFloor].filter(Boolean).reduce(worse, TIER_ORDER[0]) || null,
  })

  let plan = build()

  // One monitor for the controller's lifetime, deliberately. Rebuilding it on every tier change
  // would hand back a fresh step-down budget each time, which is how a system that was supposed to
  // settle ends up walking itself to the bottom.
  const frames = createFrameMonitor({ targetFps: plan.targetFps, ...monitorOptions })

  const apply = (decision) => {
    const from = plan.tier
    const target = step(runtimeFloor || from, decision.direction)
    if (target === from && runtimeFloor === target) return false

    runtimeFloor = target
    const next = build()
    if (next.tier === from) return false

    plan = next
    onPlanChange?.(plan, decision)
    return true
  }

  return {
    get plan () { return plan },
    get profile () { return profile },
    get pendingChange () { return pending },

    /** Feed one frame. Cheap: an array push and, once a window fills, a sort of a few hundred numbers. */
    frame (deltaMs) {
      if (disposed) return null
      // A pinned tier is a statement, not a starting point. Nothing measured here may move it.
      if (override) return null

      const decision = frames.sample(deltaMs)
      // Newest evidence wins: a decision that waited out a two-minute flight is describing a moment
      // that has passed.
      if (decision) pending = decision
      if (!pending) return null
      if (isAnimating?.()) return null

      const held = pending
      pending = null
      return apply(held) ? held : null
    },

    /** A user asking for less. `'auto'` hands the decision back to the device. */
    setPreference (next) {
      if (disposed) return plan
      userPreference = next && next !== 'auto' ? next : null
      plan = build()
      return plan
    },

    stats: () => ({ tier: plan.tier, surface: plan.surface, runtimeFloor, frames: frames.stats() }),

    dispose () {
      disposed = true
      pending = null
    },
  }
}
