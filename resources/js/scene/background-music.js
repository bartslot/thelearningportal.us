/**
 * BackgroundMusic — the music bed under a lesson.
 *
 * The teacher turns it on per lesson; it is off unless they ask for it. From there it looks after
 * itself: the tracks are shuffled so no two classes get the same opening, each one dissolves into
 * the next rather than stopping dead, and the set loops for as long as the lesson runs.
 *
 * It is scenery, never the thing being listened to. Everything it plays sits at `base` volume
 * (a low fraction, set in config/audio.php) multiplied by the student's own volume, so the deck's
 * slider and the mute button move the music along with the narration — one set of controls for
 * all sound, as a student would expect.
 *
 * Shared by the student player and the wizard preview, so a teacher hears exactly what the class
 * will hear.
 *
 * Fades run off wall-clock time rather than a tick count, because a browser throttles timers in a
 * background tab: a fade that lost half its ticks still lands on the right volume.
 */

const TICK_MS = 50

/** Fisher-Yates. A fresh order per lesson. */
function shuffled (n) {
  const arr = Array.from({ length: n }, (_, i) => i)
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[arr[i], arr[j]] = [arr[j], arr[i]]
  }
  return arr
}

/** Cut an element loose for good. Audio objects live outside the DOM; nothing else frees them. */
function release (el) {
  try {
    el.pause()
    el.removeAttribute('src')
    el.load()
  } catch (_) { /* already gone */ }
}

export class BackgroundMusic {
  /**
   * @param {string[]} tracks  URLs, in catalogue order — the shuffle happens here.
   * @param {{volume?: number, fadeMs?: number}} opts
   */
  constructor (tracks, { volume = 0.14, fadeMs = 4000 } = {}) {
    this.tracks = (tracks || []).filter(Boolean)
    this.base = volume
    this.fadeMs = fadeMs

    this.muted = false
    this.master = 1        // the deck's volume slider (0..1)
    this.paused = false
    this.started = false

    this._order = []
    this._pos = 0
    this._current = null   // the track playing now (or fading in)
    this._retiring = []    // tracks fading out on their way to being released
    this._ticker = null
  }

  get hasTracks () {
    return this.tracks.length > 0
  }

  /**
   * Begin playing. Call from (or after) a user gesture — a browser refuses to start audio
   * otherwise, and a blocked start is not worth an error at the student.
   *
   * Safe to call again after stop(): the previous bed is already on its way out, and this begins
   * a fresh shuffle rather than colliding with it.
   */
  start () {
    if (this.started || !this.hasTracks) return
    this.started = true
    this.paused = false
    this._order = shuffled(this.tracks.length)
    this._pos = 0
    this._current = this._open(this._order[0])
  }

  /** The student paused the lesson: the bed holds where it is and picks up from the same bar. */
  pause () {
    this.paused = true
    this._live().forEach(s => s.el.pause())
  }

  resume () {
    this.paused = false
    this._live().forEach(s => s.el.play().catch(() => {}))
  }

  setMuted (muted) {
    this.muted = !!muted
    this._applyVolumes()
  }

  /** The deck's volume slider (0..1). The bed stays proportionally under the narration. */
  setVolume (level) {
    this.master = Math.min(1, Math.max(0, Number(level) || 0))
    this._applyVolumes()
  }

  /** Fade out and stop — the end of a lesson, not an interruption. */
  stop () {
    this.started = false
    this._retire(this._current)
    this._current = null
  }

  /** Cut everything immediately and release the elements (page teardown). */
  destroy () {
    this._stopTicker()
    this._live().forEach(s => release(s.el))
    this._current = null
    this._retiring = []
    this.started = false
  }

  // ── Internals ───────────────────────────────────────────────────────────

  _live () {
    return this._current ? [this._current, ...this._retiring] : [...this._retiring]
  }

  /**
   * Put a track on a fresh element and fade it up. `gain` is the track's own 0..1 envelope; what
   * reaches the speaker is that times the base level times the student's volume.
   */
  _open (trackIndex) {
    const el = new Audio(this.tracks[trackIndex])
    el.preload = 'auto'
    el.loop = false                       // the crossfade moves us on; looping one track would trap us

    // Silence it BEFORE it can make a sound: an Audio element defaults to full volume, and the
    // fade's first tick is a frame away — long enough for the bed to blare over the narration.
    const slot = { el, gain: 0, fade: null }
    el.volume = 0

    // A track that ends without the crossfade having caught it (a stall, a seek, a file shorter
    // than the fade) must still hand over — silence under a lesson reads as a bug.
    el.addEventListener('ended', () => { if (slot === this._current) this._advance() }, { once: true })
    el.addEventListener('timeupdate', () => this._maybeCrossfade(slot))

    if (!this.paused) el.play().catch(() => { /* autoplay blocked — the lesson still plays */ })
    this._fade(slot, 1, this.fadeMs)
    return slot
  }

  /** Near the end of the current track, bring the next one up underneath it. */
  _maybeCrossfade (slot) {
    if (slot !== this._current) return
    const { duration, currentTime } = slot.el
    if (!Number.isFinite(duration) || duration <= 0) return
    if ((duration - currentTime) * 1000 > this.fadeMs) return
    this._advance()
  }

  /**
   * Hand over to the next track: the outgoing one fades away while the incoming one fades up.
   * When the shuffled set runs out it is reshuffled, never repeating the track that just played —
   * a lesson longer than the soundtrack should not sound like a loop.
   */
  _advance () {
    if (!this.started) return

    const justPlayed = this._order[this._pos]
    this._retire(this._current)

    this._pos++
    if (this._pos >= this._order.length) {
      this._order = shuffled(this.tracks.length)
      if (this._order.length > 1 && this._order[0] === justPlayed) {
        [this._order[0], this._order[1]] = [this._order[1], this._order[0]]
      }
      this._pos = 0
    }

    this._current = this._open(this._order[this._pos])
  }

  /** Fade a slot away and let go of it. Retired slots never come back. */
  _retire (slot) {
    if (!slot) return
    this._retiring.push(slot)
    this._fade(slot, 0, this.fadeMs, () => {
      release(slot.el)
      this._retiring = this._retiring.filter(s => s !== slot)
    })
  }

  _fade (slot, to, ms, done) {
    slot.fade = { from: slot.gain, to, start: Date.now(), ms: Math.max(1, ms), done }
    this._startTicker()
  }

  _startTicker () {
    if (this._ticker) return
    this._ticker = setInterval(() => this._tick(), TICK_MS)
  }

  _stopTicker () {
    if (!this._ticker) return
    clearInterval(this._ticker)
    this._ticker = null
  }

  _tick () {
    const now = Date.now()
    let active = false

    // Snapshot first: a fade's `done` callback drops its slot from _retiring.
    this._live().forEach(slot => {
      const f = slot.fade
      if (!f) return
      const t = Math.min(1, (now - f.start) / f.ms)
      slot.gain = f.from + (f.to - f.from) * t
      if (t < 1) { active = true; return }
      slot.fade = null
      f.done?.()
    })

    this._applyVolumes()
    if (!active) this._stopTicker()
  }

  _applyVolumes () {
    const level = this.muted ? 0 : this.base * this.master
    this._live().forEach(s => {
      s.el.volume = Math.min(1, Math.max(0, s.gain * level))
    })
  }
}

/**
 * Build the bed from the manifest the page ships (see components/audio-manifest.blade.php).
 * Returns null when the lesson has no music, so callers can treat "the teacher said no" and
 * "there is nothing to play" the same way.
 */
export function createBackgroundMusic (enabled) {
  if (!enabled) return null
  const cfg = window.LP_AUDIO?.music
  if (!cfg?.tracks?.length) return null
  return new BackgroundMusic(cfg.tracks, { volume: cfg.volume, fadeMs: cfg.fade_ms })
}
