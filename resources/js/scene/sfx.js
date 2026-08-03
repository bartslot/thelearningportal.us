/**
 * Sfx — the interface's short one-shots (a right answer, and whatever joins it later).
 *
 * One module for the whole app so there is one place that knows whether sound is allowed. The
 * player wires its mute button and volume slider into here, which is what makes "mute the lesson"
 * mean the lesson: a teacher who pressed M in a quiet classroom does not then get a chime when a
 * child answers correctly.
 *
 * Everything is best effort. A missing file, a browser that hasn't been given a gesture yet, a
 * play() that overlaps itself — none of it is worth an error in front of a class, so a sound that
 * cannot play simply doesn't.
 */

const cache = new Map()

let muted = false
let master = 1

/** URL for a named effect, from the manifest the page ships. */
function urlFor (name) {
  return window.LP_AUDIO?.sfx?.[name] || null
}

function element (name) {
  if (cache.has(name)) return cache.get(name)
  const url = urlFor(name)
  if (!url) return null
  const el = new Audio(url)
  el.preload = 'auto'
  cache.set(name, el)
  return el
}

export const Sfx = {
  /** Follows the player's mute button. */
  setMuted (value) {
    muted = !!value
  },

  /** Follows the player's volume slider (0..1). */
  setVolume (level) {
    master = Math.min(1, Math.max(0, Number(level) || 0))
  },

  get isMuted () {
    return muted
  },

  /**
   * Fire an effect. Restarts it if it is already playing, so two quick answers both sound
   * rather than the second being swallowed.
   */
  play (name) {
    if (muted || master <= 0) return
    const el = element(name)
    if (!el) return
    try {
      el.volume = master
      el.currentTime = 0
      el.play().catch(() => { /* no gesture yet, or the file is missing */ })
    } catch (_) { /* element in a bad state — never throw at the student */ }
  },

  /** Warm the cache so the first correct answer isn't the download. */
  preload (...names) {
    names.forEach(name => element(name)?.load())
  },
}
