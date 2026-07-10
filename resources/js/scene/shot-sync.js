// Storyboard shot ↔ narration synchronisation (pure logic, unit-tested).
//
// A scene renders as a sequence of "shots" (sliced cells of one storyboard grid). Each
// shot carries an `anchor_sentence` — a verbatim substring of the scene script naming the
// exact moment it depicts. The player switches to a shot when the narration reaches that
// sentence, using the TTS character alignment as the clock. Extracted from lesson-player.js
// so the sync promise is covered by tests rather than only eyeballed.

/**
 * Resolve the narration timestamp (seconds) where `anchor` begins, from the ElevenLabs
 * per-character alignment. Returns null when it can't be resolved (caller falls back to an
 * even split). Matching happens against the alignment text itself, so it is immune to any
 * whitespace differences between the stored script and the spoken text.
 *
 * @param {Array<{character?: string, start_time?: number}>} alignment
 * @param {string} anchor
 * @returns {number|null}
 */
export function resolveAnchorTime (alignment, anchor) {
  if (!alignment?.length || !anchor) return null
  const normAnchor = anchor.toLowerCase().replace(/\s+/g, ' ').trim()
  if (normAnchor.length < 10) return null

  // Build the normalised transcript with a map back to alignment indices.
  let norm = ''
  const map = []
  let lastWasSpace = true
  for (let i = 0; i < alignment.length; i++) {
    const ch = (alignment[i].character || '')
    if (/\s/.test(ch)) {
      if (!lastWasSpace) { norm += ' '; map.push(i); lastWasSpace = true }
    } else {
      norm += ch.toLowerCase(); map.push(i); lastWasSpace = false
    }
  }

  const pos = norm.indexOf(normAnchor)
  if (pos === -1) return null
  return alignment[map[pos]]?.start_time ?? null
}

/**
 * Given the shot plan (each `{ time: number|null }`, ordered) and the current playback
 * position, return the index of the shot that should be showing. Shots with an unresolved
 * anchor (`time == null`) fall back to an even split across the scene audio.
 *
 * @param {Array<{time: number|null}>} shotPlan
 * @param {number} currentTime
 * @param {number} duration
 * @returns {number}
 */
export function pickShotIndex (shotPlan, currentTime, duration) {
  let target = 0
  for (let i = 1; i < shotPlan.length; i++) {
    const at = shotPlan[i].time ?? (duration > 0 ? (i / shotPlan.length) * duration : Infinity)
    if (currentTime >= at) target = i
  }
  return target
}
