/**
 * monitor.js — watch frame time and decide, once, that the tier was too optimistic.
 *
 * Detection at startup is a guess about the next ten minutes. A device can begin fine and then
 * thrash: another tab, a thermal limit, a lesson that opened three maps. This watches what actually
 * happens.
 *
 * The design is dominated by one rule: **a tier that oscillates is worse than one that is simply
 * too low.** Everything here is asymmetric in service of that.
 *
 * - Stepping down needs a sustained window of evidence; stepping back up needs several times as
 *   much, and by default the monitor only ever steps down *once*. Going further is a decision for a
 *   fresh session with fresh evidence, not something to slide into while a class is watching.
 * - Frames above `stallMs` are dropped rather than counted. A backgrounded tab produces a single
 *   multi-second frame, and reading that as slow rendering would step every device down the moment
 *   someone switched tabs.
 * - The first frames are ignored entirely: they contain shader compilation and first upload, which
 *   are slow on every device including the fast ones.
 *
 * The monitor emits an *intent* and never applies anything. Whether it is safe to change quality
 * right now — mid-flight, mid-tour, mid-transition — is the host's question, and the controller
 * holds the decision until the answer is yes.
 */

const DEFAULTS = {
  targetFps: 60,
  windowMs: 2000,          // evidence needed to consider stepping down
  recoveryMs: 10000,       // evidence needed to consider stepping back up
  stallMs: 250,            // above this it is not a slow frame, it is not a frame
  downRatio: 1.35,         // median frame this far over budget is genuinely too slow
  upRatio: 0.65,           // median frame this far under budget has room to spare
  warmupFrames: 30,
  maxStepDowns: 1,
  latchAfterStepDowns: 2,  // after this many, stop trying to climb back at all
}

const percentile = (sorted, fraction) => sorted[Math.max(0, Math.ceil(sorted.length * fraction) - 1)]

/**
 * Median, p95 and what was thrown away, from a list of frame durations.
 *
 * The median rather than the mean: one 900 ms stall should not describe a device that is otherwise
 * holding 60.
 */
export const summarizeFrames = (durations, { stallMs = DEFAULTS.stallMs } = {}) => {
  const kept = durations.filter((ms) => Number.isFinite(ms) && ms >= 0 && ms <= stallMs)
  const dropped = durations.length - kept.length
  if (kept.length === 0) return { medianMs: null, p95Ms: null, frames: 0, dropped }

  const sorted = [...kept].sort((a, b) => a - b)
  const middle = sorted.length / 2
  const medianMs = sorted.length % 2
    ? sorted[Math.floor(middle)]
    : (sorted[middle - 1] + sorted[middle]) / 2

  return { medianMs, p95Ms: percentile(sorted, 0.95), frames: kept.length, dropped }
}

/** A bucket that fills with frame time and empties when it is full. */
const createWindow = (limitMs) => ({ limitMs, elapsedMs: 0, samples: [] })

const push = (win, ms) => {
  win.samples.push(ms)
  win.elapsedMs += ms
}

const drain = (win) => {
  win.samples = []
  win.elapsedMs = 0
}

/**
 * @param {object} [opts]  see DEFAULTS; `targetFps` should be the tier's own target, so a 30 fps
 *                         tier is not judged against 60
 * @returns {{ sample: (ms:number) => object|null, stats: () => object, reset: () => void }}
 */
export const createFrameMonitor = (opts = {}) => {
  const config = { ...DEFAULTS, ...opts }
  const budgetMs = 1000 / config.targetFps
  const tooSlowMs = budgetMs * config.downRatio
  const comfortableMs = budgetMs * config.upRatio

  const down = createWindow(config.windowMs)
  const up = createWindow(config.windowMs)

  let warmed = 0
  let stepDownsUsed = 0
  let netSteps = 0
  let comfortMs = 0

  const exhausted = () => stepDownsUsed >= config.maxStepDowns
  const latched = () => stepDownsUsed >= config.latchAfterStepDowns

  /**
   * Feed one frame. Returns a decision the host may act on, or null.
   * @param {number} ms  wall time of the frame just drawn
   */
  const sample = (ms) => {
    if (!Number.isFinite(ms) || ms < 0) return null
    if (warmed < config.warmupFrames) { warmed++; return null }
    if (ms > config.stallMs) return null

    if (!exhausted()) {
      push(down, ms)
      if (down.elapsedMs >= down.limitMs) {
        const summary = summarizeFrames(down.samples, config)
        drain(down)
        if (summary.medianMs != null && summary.medianMs > tooSlowMs) {
          stepDownsUsed++
          netSteps++
          comfortMs = 0
          drain(up)
          if (exhausted()) drain(down)
          return { direction: 'down', ...summary, budgetMs, reason: 'sustained frame time over budget' }
        }
      }
    }

    push(up, ms)
    if (up.elapsedMs < up.limitMs) return null

    const summary = summarizeFrames(up.samples, config)
    drain(up)
    comfortMs = summary.medianMs != null && summary.medianMs < comfortableMs ? comfortMs + config.windowMs : 0

    if (comfortMs < config.recoveryMs || netSteps <= 0 || latched()) return null

    comfortMs = 0
    netSteps--
    return { direction: 'up', ...summary, budgetMs, reason: 'sustained headroom' }
  }

  return {
    sample,
    reset () {
      warmed = 0
      comfortMs = 0
      drain(down)
      drain(up)
    },
    stats: () => ({
      counted: down.samples.length,
      warmed,
      stepDownsUsed,
      netSteps,
      comfortMs,
      latched: latched(),
      exhausted: exhausted(),
      budgetMs,
      tooSlowMs,
      comfortableMs,
    }),
  }
}
