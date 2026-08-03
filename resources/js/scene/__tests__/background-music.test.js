import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { BackgroundMusic, createBackgroundMusic } from '../background-music.js'

/**
 * The music bed under a lesson.
 *
 * Everything here is timing: a track has to hand over to the next one before it runs out, the two
 * have to overlap rather than butt against each other, and the whole thing has to obey a mute
 * button that a teacher may press at any moment. None of that is visible in a screenshot, and the
 * failure mode — a lesson that goes quiet halfway, or music that keeps playing after mute — is
 * exactly the kind a classroom notices and we wouldn't.
 */

const TRACKS = ['/a.mp3', '/b.mp3', '/c.mp3']
const FADE_MS = 1000

class FakeAudio {
  static made = []

  constructor (src) {
    this.url = src            // survives release(), unlike src, so a test can see what played
    this.src = src
    this.volume = 1
    this.paused = true
    this.duration = 60
    this.currentTime = 0
    this.preload = ''
    this.loop = false
    this._listeners = {}
    FakeAudio.made.push(this)
  }

  addEventListener (type, fn) {
    (this._listeners[type] ||= []).push(fn)
  }

  play () { this.paused = false; return Promise.resolve() }
  pause () { this.paused = true }
  removeAttribute () { this.src = null }
  load () {}

  emit (type) { (this._listeners[type] || []).slice().forEach(fn => fn()) }

  /** Move the playhead and let the engine react, the way a real element's timeupdate would. */
  seekTo (seconds) { this.currentTime = seconds; this.emit('timeupdate') }

  /** Wind to the point where the crossfade into the next track is due. */
  runToFadePoint () { this.seekTo(this.duration - FADE_MS / 1000) }
}

/** Elements the engine is currently holding, in the order it created them. */
const live = () => FakeAudio.made.filter(a => a.src !== null)

const make = (opts = {}) => new BackgroundMusic(TRACKS, { volume: 0.2, fadeMs: FADE_MS, ...opts })

beforeEach(() => {
  FakeAudio.made = []
  vi.stubGlobal('Audio', FakeAudio)
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  delete window.LP_AUDIO
})

describe('starting', () => {
  it('plays nothing until it is started', () => {
    make()
    expect(FakeAudio.made).toHaveLength(0)
  })

  it('starts one track, faded up from silence rather than arriving at full volume', () => {
    const music = make()
    music.start()

    expect(FakeAudio.made).toHaveLength(1)
    expect(FakeAudio.made[0].paused).toBe(false)
    expect(FakeAudio.made[0].volume).toBe(0)

    vi.advanceTimersByTime(FADE_MS)
    expect(FakeAudio.made[0].volume).toBeCloseTo(0.2, 5)
  })

  it('ignores a second start, so a double press cannot stack two beds', () => {
    const music = make()
    music.start()
    music.start()
    expect(FakeAudio.made).toHaveLength(1)
  })

  it('does nothing at all when there are no tracks', () => {
    const music = new BackgroundMusic([], { fadeMs: FADE_MS })
    music.start()
    expect(FakeAudio.made).toHaveLength(0)
  })
})

describe('handing over between tracks', () => {
  it('brings the next track up before the current one has finished', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS)

    const first = FakeAudio.made[0]
    first.runToFadePoint()

    // Both are sounding: that overlap is the crossfade.
    expect(live()).toHaveLength(2)
    vi.advanceTimersByTime(FADE_MS / 2)
    const second = FakeAudio.made[1]
    expect(first.volume).toBeGreaterThan(0)
    expect(second.volume).toBeGreaterThan(0)
    expect(first.volume).toBeLessThan(0.2)
    expect(second.volume).toBeLessThan(0.2)

    // …and when it is over the outgoing track is let go of, not left paused forever.
    vi.advanceTimersByTime(FADE_MS)
    expect(second.volume).toBeCloseTo(0.2, 5)
    expect(live()).toEqual([second])
    expect(first.paused).toBe(true)
  })

  it('hands over anyway when a track simply ends, so the lesson never falls silent', () => {
    const music = make()
    music.start()
    FakeAudio.made[0].emit('ended')

    expect(live()).toHaveLength(2)
    expect(FakeAudio.made[1].paused).toBe(false)
  })

  it('plays the whole set before any track comes round again', () => {
    const music = make()
    music.start()

    for (let i = 0; i < TRACKS.length - 1; i++) {
      FakeAudio.made[FakeAudio.made.length - 1].emit('ended')
      vi.advanceTimersByTime(FADE_MS)
    }

    const played = FakeAudio.made.map(a => a.url)
    expect(new Set(played)).toEqual(new Set(TRACKS))
  })

  it('never opens the new round with the track that just played', () => {
    // The reshuffle is random, so this is worth running more than once.
    for (let attempt = 0; attempt < 25; attempt++) {
      FakeAudio.made = []
      const music = make()
      music.start()

      for (let i = 0; i < TRACKS.length; i++) {
        FakeAudio.made[FakeAudio.made.length - 1].emit('ended')
        vi.advanceTimersByTime(FADE_MS)
      }

      const played = FakeAudio.made.map(a => a.url)
      expect(played[TRACKS.length]).not.toBe(played[TRACKS.length - 1])
    }
  })
})

describe('following the player controls', () => {
  it('goes silent on mute and comes back at the same level', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS)

    music.setMuted(true)
    expect(FakeAudio.made[0].volume).toBe(0)

    music.setMuted(false)
    expect(FakeAudio.made[0].volume).toBeCloseTo(0.2, 5)
  })

  it('stays under the narration at every setting of the volume slider', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS)

    music.setVolume(1)
    expect(FakeAudio.made[0].volume).toBeCloseTo(0.2, 5)

    music.setVolume(0.5)
    expect(FakeAudio.made[0].volume).toBeCloseTo(0.1, 5)

    music.setVolume(0)
    expect(FakeAudio.made[0].volume).toBe(0)
  })

  it('mutes a track that is still fading in, not just the one at full volume', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS / 2)

    music.setMuted(true)
    vi.advanceTimersByTime(FADE_MS)
    expect(FakeAudio.made[0].volume).toBe(0)
  })

  it('holds where it is when the lesson is paused, and picks up from the same bar', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS)

    music.pause()
    expect(FakeAudio.made[0].paused).toBe(true)

    music.resume()
    expect(FakeAudio.made[0].paused).toBe(false)
  })

  it('pauses both tracks when the lesson is paused mid-crossfade', () => {
    const music = make()
    music.start()
    FakeAudio.made[0].emit('ended')

    music.pause()
    expect(live().every(a => a.paused)).toBe(true)
  })
})

describe('stopping', () => {
  it('fades away at the end of a lesson instead of cutting on the last word', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS)

    music.stop()
    vi.advanceTimersByTime(FADE_MS / 2)
    expect(FakeAudio.made[0].volume).toBeGreaterThan(0)
    expect(FakeAudio.made[0].volume).toBeLessThan(0.2)

    vi.advanceTimersByTime(FADE_MS)
    expect(FakeAudio.made[0].paused).toBe(true)
    expect(live()).toHaveLength(0)
  })

  it('can be started again while the last bed is still fading out', () => {
    // The wizard preview does exactly this: stop at the end of the timeline, play again straight
    // away. The dying track must not take the new one down with it.
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS)
    const first = FakeAudio.made[0]

    music.stop()
    music.start()
    const second = FakeAudio.made[1]

    vi.advanceTimersByTime(FADE_MS * 2)
    expect(first.paused).toBe(true)
    expect(second.paused).toBe(false)
    expect(second.volume).toBeCloseTo(0.2, 5)
  })

  it('releases every element on destroy, since nothing else frees an Audio', () => {
    const music = make()
    music.start()
    FakeAudio.made[0].emit('ended')

    music.destroy()
    expect(FakeAudio.made.every(a => a.paused && a.src === null)).toBe(true)
  })

  it('stops ticking once nothing is fading, so an idle lesson runs no timer', () => {
    const music = make()
    music.start()
    vi.advanceTimersByTime(FADE_MS * 2)
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('createBackgroundMusic', () => {
  it('returns nothing when the teacher left music off', () => {
    window.LP_AUDIO = { music: { tracks: TRACKS, volume: 0.2, fade_ms: FADE_MS } }
    expect(createBackgroundMusic(false)).toBeNull()
  })

  it('returns nothing when the page ships no soundtrack', () => {
    expect(createBackgroundMusic(true)).toBeNull()
    window.LP_AUDIO = { music: { tracks: [] } }
    expect(createBackgroundMusic(true)).toBeNull()
  })

  it('builds the bed from the page manifest', () => {
    window.LP_AUDIO = { music: { tracks: TRACKS, volume: 0.11, fade_ms: 2500 } }
    const music = createBackgroundMusic(true)
    expect(music.tracks).toEqual(TRACKS)
    expect(music.base).toBe(0.11)
    expect(music.fadeMs).toBe(2500)
  })
})
