/**
 * lesson-player.js — Game loop engine for /lesson/{code}
 *
 * Phases:  LOADING → TITLE_SCREEN → INTRO → GAME_BRIEF → GAME_ACTIVE → TIME_UP
 * Special: INTEL_DROP overlays during GAME_ACTIVE and extends the timer.
 *
 * LLM script tags parsed from lesson.script:
 *   [position:bottom-right]  — move avatar to preset
 *   [position:left-centre]
 *   [position:middle]
 *   [position:right-centre]
 *   [game]                   — transition to GAME_BRIEF → GAME_ACTIVE
 *   [intel_drop]             — trigger INTEL_DROP mid-game
 */

import Alpine from 'alpinejs'
import QRCode from 'qrcode'
import { resolveAnchorTime, pickShotIndex } from './scene/shot-sync.js'
import { renderGallery } from './gallery-scene.js'
import { isTopAnchored, normalizeFit, PORTRAIT_TOP_CSS } from './scene/background-fit.js'
import { sameOriginMediaUrl } from './media-url.js'
import { sceneTransitionFrames, easingBezier, EASINGS } from './scene/animations.js'

// Easing key → cubic-bezier, for the scene transition set in the wizard's Animate tab.
const SCENE_EASINGS = Object.fromEntries(EASINGS.map(e => [e.key, easingBezier(e.key)]))
import { buildCues, cueAt } from './scene/captions.js'
import { createBackgroundMusic } from './scene/background-music.js'
import { Sfx } from './scene/sfx.js'

// The 3D avatar CHARACTER is retired — the narrator is a flat 2D portrait badge (player.blade.php).
// The 3D SKYBOX background stays an OPT-IN: a lesson with any scene_view:'skybox' scene lazy-loads
// the Three.js chunk (avatar-3d.js, via the dynamic import in _initBgScene) for the skybox; pure-2D
// lessons never download it. `this._usesSkybox` (set in init from the scenes) is that runtime switch.

// ── Avatar canvas position presets ───────────────────────────────────────────
// Each preset is a CSS class set applied to the #avatar-wrap element.
// The inner Three.js scene always fills the wrapper; we just move the wrapper.
const AVATAR_POSITIONS = {
  'bottom-right':  'absolute bottom-0 right-0 w-[38vw] max-w-[500px] translate-y-4',
  'left-centre':   'absolute top-1/2 left-0 w-[32vw] max-w-[420px] -translate-y-1/2',
  'middle':        'absolute top-1/2 left-1/2 w-[32vw] max-w-[420px] -translate-x-1/2 -translate-y-1/2',
  'right-centre':  'absolute top-1/2 right-0 w-[32vw] max-w-[420px] -translate-y-1/2',
}

const DEFAULT_POSITION = 'bottom-right'

// ── Ken Burns helper ──────────────────────────────────────────────────────────
// Ken Burns: scale from 110% → 105% while panning corner-to-corner.
// Using CSS scale() + translate() so background-size stays 'cover' (no black bars).
// Each direction pair: start scale/position → end scale/position.
const KB_DIRECTIONS = [
  { fromScale: 1.10, fromX:  2, fromY:  2, toScale: 1.05, toX: -2, toY: -2 }, // top-right → bottom-left
  { fromScale: 1.10, fromX: -2, fromY:  2, toScale: 1.05, toX:  2, toY: -2 }, // top-left  → bottom-right
  { fromScale: 1.10, fromX:  2, fromY: -2, toScale: 1.05, toX: -2, toY:  2 }, // bottom-right → top-left
  { fromScale: 1.10, fromX: -2, fromY: -2, toScale: 1.05, toX:  2, toY:  2 }, // bottom-left  → top-right
  { fromScale: 1.10, fromX:  0, fromY:  2, toScale: 1.05, toX:  0, toY: -2 }, // top-centre  → bottom-centre
]

// Teacher-selectable Ken Burns moves (scenes.kb_direction). null/'auto' rotates the classic set.
const KB_NAMED = {
  left_right: { fromScale: 1.08, fromX: -2.5, fromY: 0, toScale: 1.08, toX:  2.5, toY: 0 },
  right_left: { fromScale: 1.08, fromX:  2.5, fromY: 0, toScale: 1.08, toX: -2.5, toY: 0 },
  zoom_in:    { fromScale: 1.02, fromX:  0,   fromY: 0, toScale: 1.12, toX:  0,   toY: 0 },
  zoom_out:   { fromScale: 1.12, fromX:  0,   fromY: 0, toScale: 1.02, toX:  0,   toY: 0 },
}

function pickKbDirection (index, named = null) {
  if (named && KB_NAMED[named]) return KB_NAMED[named]
  return KB_DIRECTIONS[index % KB_DIRECTIONS.length]
}

// ── Script tag parser ─────────────────────────────────────────────────────────
// Returns sorted array of { time: seconds, type, value }
function parseScriptTags (script, audioDuration) {
  if (!script || !audioDuration) return []

  const events = []
  // Strip tags and count plain chars to estimate timestamp
  const plainText = script.replace(/\[[^\]]+\]/g, '')
  const totalChars = plainText.length

  let charPos = 0
  const tokenRegex = /(\[[^\]]+\]|[^\[]+)/g
  let m

  while ((m = tokenRegex.exec(script)) !== null) {
    const token = m[1]
    if (token.startsWith('[')) {
      const inner = token.slice(1, -1)
      const estimatedTime = (charPos / totalChars) * audioDuration

      if (inner.startsWith('position:')) {
        events.push({ time: estimatedTime, type: 'position', value: inner.replace('position:', '') })
      } else if (inner === 'game') {
        events.push({ time: estimatedTime, type: 'game', value: null })
      } else if (inner === 'intel_drop') {
        events.push({ time: estimatedTime, type: 'intel_drop', value: null })
      }
    } else {
      charPos += token.replace(/\s+/g, '').length
    }
  }

  return events.sort((a, b) => a.time - b.time)
}

// ── Extract year and location from topic/title ────────────────────────────────
function extractYearAndLocation (lesson) {
  const text = (lesson.title ?? '') + ' ' + (lesson.topic ?? '')

  // Match 4-digit year or year with BC/AD suffix
  const yearMatch = text.match(/\b(\d{1,4}\s*(?:BC|AD|BCE|CE)?)\b/i)
  const year = yearMatch ? yearMatch[1].replace(/\s+/, '').toUpperCase() : ''

  // Extract location: look for "in <Location>" or use topic as fallback
  const locMatch = text.match(/\bin\s+([A-Z][a-zA-Z\s,]+?)(?:\s*[,.]|$)/i)
  const location = locMatch ? locMatch[1].trim() : lesson.topic ?? ''

  return { year, location }
}

// ── Module-level Avatar3D instance ───────────────────────────────────────────
// Three.js objects contain non-configurable properties (modelViewMatrix etc.) that
// break when stored inside Alpine's reactive Proxy. Keep the instance here, outside
// Alpine's data object, and access it via closure.
let _bgInstance     = null   // background-only Avatar3DPlayer (skybox, no character)
let _bgCanvas       = null   // module-level ref so both Alpine instances share it
let _sceneQueue     = []   // [{kind, config, audio_url, script, image_url, alignment}] for scene-based playback
let _mapInstance    = null   // MapLibre map block instance (lives outside Alpine's proxy)
let _mapTimer       = null   // timed-mode auto-advance timer
let _voyageInstance = null   // voyage tour instance (kind 'voyage'; outside Alpine's proxy)
let _galleryInstance = null  // gallery slideshow instance (kind 'gallery'; outside Alpine's proxy)
let _autoAdvanceRaf = null    // voyage auto-advance progress loop
let _music          = null   // BackgroundMusic bed (owns its own Audio elements; outside Alpine's proxy)
let _initDone       = false  // guard: prevent double-init from Vite HMR / Alpine re-mount
let _parallax       = null   // live ParallaxScene instance (layered bg+hero shot, E3b)
let _parallaxMod    = null   // cached ./scene/ParallaxScene.js module (lazy-loaded once)

// ── Alpine component ──────────────────────────────────────────────────────────
// Alpine is imported directly (no Livewire on this page) — register before start()
Alpine.data('lessonGame', (lesson) => ({
    // ── State ──────────────────────────────────────────────────────────
    phase:  'LOADING',          // LOADING | TITLE_SCREEN | WELCOME_VIDEO | INTRO | GAME_BRIEF | GAME_ACTIVE | TIME_UP | INTEL_DROP | ENDED
    canResumeAfterGame: false,  // shows the "Continue the lesson" button on the TIME_UP screen
    lesson: lesson,             // exposed to templates (title screen meta, etc.)
    prevPhase: null,            // phase before INTEL_DROP, to return to

    // Location/Year overlay
    lessonYear:     '',
    lessonLocation: '',

    // Countdown timer
    timerSeconds:       0,
    timerDisplay:       '10:00',
    showBigTimer:       false,
    _timerInterval:     null,
    _gameDurationSecs:  600,    // 10 min default; overridden by strategy_game.duration_minutes

    // Intel drop
    intelDropMessage: '',

    // Audio controls
    audioPlaying: false,
    audioMuted: false,
    volumeLevel: 1.0,  // narration volume (0..1) — the deck's slider; survives scene changes
    playbackPaused: false,  // student paused the stage (narration AND any scene animation)
    // The big centre play/pause glyph. It answers a press — it is not a state light. It used to
    // show whenever nothing happened to be playing, which on an interactive scene (a voyage
    // landfall waiting for the class) meant a 96px triangle parked over the map for as long as
    // anyone looked at it. Now: it flashes when the student presses play or pause, and it stays up
    // while the lesson is actually paused. Nothing else brings it back.
    playbackGlyph: false,
    readingOverlay: false,  // a gallery/reading surface owns the screen → the player shows no chrome
    // The bottom-left chapter line. A voyage leg reports its own place + date (they change as the
    // ship sails); everything else falls back to the scene's chapter name and year.
    infoPlace: '',
    infoDate: '',
    infoAtTop: false,       // teacher chose the top date chip instead of the bottom line

    // Subtitles — the narration written along the bottom while it is spoken. The teacher sets
    // the starting state per lesson; a student toggling it (C) is remembered for this browser,
    // because someone who needs captions needs them in every lesson, not just this one.
    captionsOn:        false,
    captionText:       '',
    _cues:             [],

    // Chapters (Micrio-style serial-tour bar + list)
    chapters:          [],   // [{name, dur, index, kind}] — games excluded; `index` = queue index
    currentChapterName: '',
    currentIsGame:     false, // current scene is a quiz/game → hide the chapter bar
    sceneProgress:     0,    // 0-1 through the current chapter's audio
    chaptersOpen:      false,

    // Map block
    showMapContinue: false,  // interactive map slide → show the Continue button
    autoAdvanceProgress: 0,  // 0..1 — voyage auto-advance progress line at the arrival

    // Internals
    _audio:             null,
    _intelAudio:        null,   // intel-drop sfx — tracked so teardown can stop it
    _kbHandler:         null,   // keydown listener ref — removed on destroy()
    _navStop:           null,   // pagehide / livewire:navigating handler ref
    _scriptEvents:      [],
    _lastEventIndex:    0,
    _kbInterval:        null,
    _kbIndex:           0,
    _kbImages:          [],
    _usesSkybox:        false,  // true when any scene opts into scene_view:'skybox' → lazy-load 3D skybox
    _bgLayerA:          null,
    _bgLayerB:          null,
    _bgActive:          'A',
    _bgFadeDuration:    1000,   // ms
    _bgSlideMin:        8000,
    _bgSlideMax:        12000,
    _avatarWrap:        null,
    _currentPosition:   DEFAULT_POSITION,

    // ── Lifecycle ──────────────────────────────────────────────────────
    async init () {
      // Guard: Vite HMR re-registers Alpine.data which can trigger a second init()
      // on the same page load. Skip if already running.
      if (_initDone) return
      _initDone = true

      // Normalise OUR media URLs to the page origin so a localhost vs 127.0.0.1 mismatch doesn't
      // block audio. Anything on another host — a CDN-hosted cover, say — is left alone: rewriting
      // its origin turned https://res.cloudinary.com/<cloud>/… into a 404 on our own domain.
      const fixUrl = (u) => sameOriginMediaUrl(u, window.location.origin)
      if (lesson.audio_url)    lesson.audio_url    = fixUrl(lesson.audio_url)
      if (lesson.visemes_url)  lesson.visemes_url  = fixUrl(lesson.visemes_url)
      if (lesson.cover_image_url) lesson.cover_image_url = fixUrl(lesson.cover_image_url)
      lesson.scenes?.forEach(s => { s.audio_url = fixUrl(s.audio_url); s.image_url = fixUrl(s.image_url) })
      lesson.scene_images?.forEach(s => { s.url = fixUrl(s.url) })
      lesson.slideshow_images?.forEach(s => { s.url = fixUrl(s.url) })

      // Subtitles start where the teacher set them for this lesson — unless this browser has
      // already made its own choice, which wins: a pupil who needs captions should not have to
      // switch them back on in every lesson.
      const remembered = (() => { try { return localStorage.getItem('lp:captions') } catch (_) { return null } })()
      this.captionsOn = remembered === null ? !!lesson.subtitles : remembered === '1'

      // The quiz's right-answer chime obeys the deck from the first question, and is fetched now
      // so the first correct answer isn't waiting on a download.
      this._syncSoundLevels()
      Sfx.preload('correct')

      const { year, location } = extractYearAndLocation(lesson)
      this.lessonYear     = year
      this.lessonLocation = location

      // Build image list: cover first, then slideshow images, then scene renders as fallback
      const coverImg    = lesson.cover_image_url ? [{ url: lesson.cover_image_url }] : []
      const slideshowImgs = lesson.slideshow_images ?? []
      const sceneImgs   = (slideshowImgs.length === 0) ? (lesson.scene_images ?? []) : []
      // The 3D skybox is opt-in per scene; if ANY scene uses it the lesson lazy-loads the
      // Three.js skybox engine (see _initBgScene). Pure-2D lessons never touch Three.js.
      this._usesSkybox = (lesson.scenes ?? []).some(s => s.scene_view === 'skybox')
      // For pure-2D lessons, fold each scene's panorama into the Ken-Burns slideshow so a
      // would-be skybox image still shows flat. Skybox lessons render those in 3D instead.
      const scenePanoramas = this._usesSkybox
        ? []
        : (lesson.scenes ?? [])
            .map(s => s.skybox_image_path || s.image_url)
            .filter(Boolean)
            .map(url => ({ url }))
      this._kbImages    = [...coverImg, ...slideshowImgs, ...sceneImgs, ...scenePanoramas]

      // Voyage/gallery lessons keep their imagery inside scene configs (gallery
      // slides), not on the lesson/scene rows — without this fallback their title
      // screen sits on a bare navy background.
      if (this._kbImages.length === 0) {
        const seen = new Set()
        this._kbImages = (lesson.scenes ?? [])
          .flatMap(s => s.config?.images ?? [])
          .filter(img => img?.url && !seen.has(img.url) && seen.add(img.url))
          .map(img => ({ url: img.url }))
      }

      this._buildBackgroundLayer()
      this._buildAvatarWrapper()

      // Start Ken Burns immediately for title screen — faster interval (4-7s) for drama
      if (this._kbImages.length > 0) {
        if (this._kbImages.length > 1) {
          this._bgSlideMin = 6000
          this._bgSlideMax = 6000
        }
        this._showBgImage(this._kbImages[0], 'A')
        // Also run for a SINGLE image. The drift is one CSS transition, so it ends after
        // _bgSlideMax and then sits perfectly still — which is what a lesson with one cover (every
        // voyage lesson) showed: twelve seconds of movement and then a frozen title screen. The
        // interval re-triggers the same drift instead of leaving it parked at its end frame.
        this._startKenBurns()
      }

      // Fire bg (skybox) init in background — should be ready by play time, but do
      // NOT block startLesson on it (Three.js chunk load has no timeout and can hang).
      this._initBgScene() // fire-and-forget

      // Only audio metadata is strictly required before lesson can start
      this._assetsReady = this._loadAudio()

      // Pick + preload the welcome video (full vs lite) while the title screen is up,
      // so it plays instantly on "Start lesson".
      if (lesson.narrator_welcome_url && this.$refs.welcomeVideo) {
        this.$refs.welcomeVideo.src = this._welcomeSrc()
      }

      this.phase = 'TITLE_SCREEN'

      // Render QR code into the canvas element
      this._renderQr()

      // Embedded autoplay (wizard "Play" preview): skip the title screen and play straight through,
      // optionally starting at a given scene index. Voyage lessons have no narration audio, so
      // starting without a click gesture is safe.
      try {
        const params = new URLSearchParams(window.location.search)
        this._embed = params.get('embed') === '1'
        const sc = parseInt(params.get('scene'), 10)
        this._startSceneIndex = Number.isFinite(sc) ? Math.max(0, sc) : 0
        // Autoplay only when a human actually asked for it in THIS visit.
        //
        // The wizard's Preview step embeds this player with autoplay=1, which is right when the
        // teacher clicks through to it — and wrong on a reload. A tab left parked on Preview
        // started narrating out loud every time it was refreshed, from a hidden iframe, with
        // nothing on screen to say where the voice was coming from.
        //
        // hasBeenActive is false on a cold load and true once the page has been clicked or typed
        // in, which is exactly the distinction. Browsers without it (Safari) keep the old
        // behaviour; their own autoplay policy is stricter anyway.
        if (params.get('autoplay') === '1') {
          const asked = window.navigator.userActivation?.hasBeenActive ?? true
          if (asked) {
            setTimeout(() => { if (this.phase === 'TITLE_SCREEN') this.startLesson() }, 300)
          }
        }
      } catch (_) { /* no params */ }

      // Attach keyboard listeners for audio controls
      this._attachKeyboardListeners()
      this._attachStagePause()

      // new Audio() objects play independently of the DOM, so without explicit
      // teardown the narration keeps playing after the user leaves the page —
      // including the app's wire:navigate SPA transitions. Stop it on the way out.
      this._navStop = () => this._stopAllAudio()
      window.addEventListener('pagehide', this._navStop)
      document.addEventListener('livewire:navigating', this._navStop)

      // A landfall gallery (and any future reading surface) announces itself so the player can
      // clear its chrome out of the reader's way. See setReadingOverlayOpen in voyage-tour.js.
      this._readingOverlayHandler = (e) => { this.readingOverlay = !!e.detail?.open }
      window.addEventListener('lesson:reading-overlay', this._readingOverlayHandler)

      // A voyage leg owns the place and date on the chapter line while it sails. See publishInfo
      // in voyage-tour.js — the tour reports, the player is the only one that draws.
      this._chapterInfoHandler = (e) => {
        this.infoPlace = e.detail?.place || ''
        this.infoDate = e.detail?.date || ''
        this.infoAtTop = !!e.detail?.atTop
      }
      window.addEventListener('lesson:chapter-info', this._chapterInfoHandler)
    },

    _renderQr () {
      const url = `${window.location.origin}/lesson/${lesson.lesson_code}`
      const opts = (width) => ({ width, margin: 1, color: { dark: '#ffffff', light: '#00000000' } })

      const small = document.getElementById('title-qr-canvas')
      if (small) QRCode.toCanvas(small, url, opts(160)).catch(() => {})

      const large = document.getElementById('qr-modal-canvas')
      if (large) QRCode.toCanvas(large, url, opts(320)).catch(() => {})
    },

    // ── Title screen "Start lesson" button ─────────────────────────────
    async startLesson () {
      // Locked-mode (best effort): go fullscreen on the user's play gesture so tab/app
      // switching takes deliberate effort. Not supported on iPhone Safari — never block.
      // Skip when embedded (the wizard preview iframe) — no gesture, and fullscreen would be wrong.
      if (!this._embed) document.documentElement.requestFullscreen?.().catch(() => {})
      // Unlock browser audio NOW — must happen synchronously before any await breaks the gesture chain
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext
        if (AudioCtx) { const ctx = new AudioCtx(); ctx.resume(); ctx.close() }
      } catch (_) {}

      // Narrator welcome video plays once, full-screen, before the first block. It runs
      // synchronously off this click gesture (so it keeps its sound), while lesson assets
      // keep preloading in the background. _endWelcomeVideo() advances into playback.
      if (lesson.narrator_welcome_url && !this._welcomePlayed) {
        this._welcomePlayed = true
        this.phase = 'WELCOME_VIDEO'
        // Play synchronously within the click gesture so autoplay-with-sound is allowed.
        // The overlay (x-show) becomes visible on Alpine's reactive flush a tick later.
        this._playWelcomeVideo()
        return
      }

      this._enterPlayback()
    },

    // ── Narrator welcome video ─────────────────────────────────────────
    _welcomePlayed: false,

    // Choose full vs lite source. Network Information API (Chrome/Android/Edge) is
    // authoritative when present; iOS Safari lacks it, so phones fall back to lite
    // (the clip renders ~390px wide there — the 480px lite file is plenty).
    _welcomeSrc () {
      const full = lesson.narrator_welcome_url
      const lite = lesson.narrator_welcome_lite_url
      if (!lite) return full

      const c = navigator.connection || navigator.mozConnection || navigator.webkitConnection
      if (c) {
        if (c.saveData) return lite
        if (['slow-2g', '2g', '3g'].includes(c.effectiveType)) return lite
        return full // 4g / wifi
      }

      // No connection API → can't detect cellular. Default phones to lite, desktops to full.
      const isPhone = /iPhone|Android.*Mobile/i.test(navigator.userAgent)
        || Math.min(window.innerWidth, window.innerHeight) <= 480
      return isPhone ? lite : full
    },

    _playWelcomeVideo () {
      const v = this.$refs.welcomeVideo
      if (!v) { this._enterPlayback(); return }
      if (!v.getAttribute('src')) v.src = this._welcomeSrc() // safety: ensure a source



      // Advance to the first block when the intro ends (or fails) — only once.
      v.addEventListener('ended', () => this._endWelcomeVideo(), { once: true })
      v.addEventListener('error', () => this._endWelcomeVideo(), { once: true })

      try { v.currentTime = 0 } catch (_) {}
      v.play().catch(e => {
        // Autoplay-with-sound blocked despite the gesture — don't strand the student.
        console.warn('lesson-player: welcome video autoplay blocked', e)
        this._endWelcomeVideo()
      })
    },

    _endWelcomeVideo () {
      if (this.phase !== 'WELCOME_VIDEO') return // guard against double-fire (ended + skip)
      const v = this.$refs.welcomeVideo
      try { if (v) { v.pause(); v.removeAttribute('src'); v.load() } } catch (_) {}
      this._enterPlayback()
    },

    // ── Load assets, then start the first block ────────────────────────
    async _enterPlayback () {
      this.phase = 'LOADING'
      try {
        await Promise.race([
          this._assetsReady,
          new Promise((_, rej) => setTimeout(() => rej(new Error('_assetsReady timeout after 15s')), 15000))
        ])
      } catch (e) {
        console.warn('lesson-player: assets load error (starting anyway):', e?.message)
      }

      // Skybox lessons: the 3D canvas takes over — stop Ken Burns and reveal the skybox.
      // Pure-2D lessons keep the Ken-Burns slideshow running the whole lesson (each scene's
      // image is fed into it by _playScene).
      if (this._usesSkybox) {
        clearInterval(this._kbInterval)
        this._kbInterval = null
        this._showBgScene()
      }

      // Story game (Spel-verhaal): lazy-load the branch/meter engine once. Without
      // meters the engine never constructs — plain branching keeps its pre-existing
      // behaviour (both options play sequentially; branching was never wired before).
      if (lesson.game_type === 'story_game' && lesson.game_config?.meters?.length && !this._storyGame) {
        try {
          const { StoryGameEngine } = await import('./scene/StoryGameEngine.js')
          const host = document.createElement('div')
          host.id = 'story-game-overlay'
          host.className = 'absolute inset-0 z-40 pointer-events-none'
          document.getElementById('lesson-stage')?.appendChild(host)
          this._storyGame = new StoryGameEngine(host, {
            meters: lesson.game_config.meters,
            failThreshold: lesson.game_config.fail_threshold ?? 0,
            scenes: _sceneQueue,
            onAdvanceTo: (i) => { this._sceneIndex = i; this._playScene(i) },
            onOverrideContinue: () => this._advanceScene(this._sceneIndex),
          })
        } catch (e) { console.warn('lesson-player: story game engine failed to load', e) }
      }

      // The music bed starts with the lesson, not with the title screen: the student pressed Play
      // a moment ago, so the browser will let it make a sound. It is created here rather than in
      // init() so a lesson opened and never started never downloads a track.
      this._startBackgroundMusic()

      this.phase = 'INTRO'
      this._playIntro()
    },

    // ── Background music ───────────────────────────────────────────────
    // A per-lesson choice by the teacher. The bed shuffles and crossfades on its own; all this
    // has to do is hand it the student's volume and stop it when the lesson does.
    _startBackgroundMusic () {
      if (_music) return
      _music = createBackgroundMusic(lesson.background_music)
      if (!_music) return
      _music.setMuted(this.audioMuted)
      _music.setVolume(this.volumeLevel)
      _music.start()
    },

    // ── Background / Ken Burns ─────────────────────────────────────────
    _buildBackgroundLayer () {
      const bg = document.getElementById('background-layer')
      if (!bg) return

      // Layer A
      const a = document.createElement('div')
      a.className = 'absolute inset-0 bg-cover bg-center transition-opacity ease-in-out overflow-hidden'
      a.style.transitionDuration = `${this._bgFadeDuration}ms`
      a.style.opacity = '1'
      bg.appendChild(a)
      this._bgLayerA = a

      // Layer B
      const b = document.createElement('div')
      b.className = 'absolute inset-0 bg-cover bg-center transition-opacity ease-in-out overflow-hidden'
      b.style.transitionDuration = `${this._bgFadeDuration}ms`
      b.style.opacity = '0'
      bg.appendChild(b)
      this._bgLayerB = b
    },

    // Per-scene motion settings, set by _playScene before each _showFlatScene call.
    _kbAnimated: true,
    _kbNamedDirection: null,
    _embedBg: null,   // Sketchfab-3D / video iframe used as a scene background
    _embedRO: null,   // ResizeObserver keeping a 'cover' video sized to the stage

    _hideEmbedBg () {
      if (this._embedRO) { try { this._embedRO.disconnect() } catch (_) { /* gone */ } this._embedRO = null }
      if (this._embedBg) { this._embedBg.remove(); this._embedBg = null }
    },

    // A Sketchfab 3D model or a video (YouTube/Vimeo/…) as the full-bleed scene background.
    _showEmbedBg (embed) {
      const bg = document.getElementById('background-layer')
      if (!bg || !embed || !embed.src) return
      this._hideEmbedBg()
      const wrap = document.createElement('div')
      wrap.id = 'lesson-embed-bg'
      wrap.className = 'absolute inset-0 overflow-hidden'
      wrap.style.cssText = 'position:absolute;inset:0;overflow:hidden;background:#000;z-index:1;'
      const iframe = document.createElement('iframe')
      iframe.src = embed.src
      iframe.title = embed.kind === 'sketchfab' ? '3D background' : 'Video background'
      iframe.setAttribute('allow', 'autoplay; fullscreen; xr-spatial-tracking; encrypted-media; picture-in-picture')
      iframe.setAttribute('allowfullscreen', 'true')
      iframe.style.border = '0'
      iframe.style.position = 'absolute'
      const coverVideo = embed.kind === 'video' && (embed.fit || 'cover') === 'cover'
      if (!coverVideo) {
        // Sketchfab fills; a 'fit' video letterboxes inside a full-bleed iframe.
        iframe.style.inset = '0'; iframe.style.width = '100%'; iframe.style.height = '100%'
      } else {
        // Cover a 16:9 video: overflow the shorter axis + centre; keep sized on resize.
        iframe.style.left = '50%'; iframe.style.top = '50%'; iframe.style.transform = 'translate(-50%,-50%)'
        const fit = () => {
          const cw = wrap.clientWidth || 1, ch = wrap.clientHeight || 1, a = 16 / 9
          if (cw / ch > a) { iframe.style.width = cw + 'px'; iframe.style.height = (cw / a) + 'px' }
          else { iframe.style.height = ch + 'px'; iframe.style.width = (ch * a) + 'px' }
        }
        fit()
        try { this._embedRO = new ResizeObserver(fit); this._embedRO.observe(wrap) } catch (_) { /* no RO */ }
      }
      wrap.appendChild(iframe)
      bg.appendChild(wrap)
      this._embedBg = wrap
    },

    _showBgImage (img, layer) {
      const el = layer === 'A' ? this._bgLayerA : this._bgLayerB
      if (!el || !img?.url) return

      const fit = normalizeFit(this._bgFit)

      el.style.backgroundImage = `url(${img.url})`
      el.style.backgroundSize  = fit
      el.style.backgroundColor = ''

      // Crop anchor. Centre unless the scene is explicitly marked as having a face up top, in
      // which case the crop starts 20% down — the face, with a little headroom. (Same anchor the
      // wizard's 3D stage uses via PORTRAIT_TOP_BIAS, so both crop identically.)
      const anchor = (w, h) => {
        el.style.backgroundPosition = isTopAnchored(fit, this._bgFocus, w, h)
          ? PORTRAIT_TOP_CSS
          : 'center center'
      }

      // The stored hint is now the only signal, so this settles it in one go. There used to be a
      // second pass here that loaded the file to measure its shape and re-anchored tall images to
      // the top; that is exactly what cropped a ship down to its mast, and it cost an extra image
      // load per scene to do it.
      anchor(0, 0)

      // Motion off for this scene → a calm, static image. 'contain' is always static: the teacher
      // asked to see the whole work, and a Ken Burns zoom would crop straight back into it.
      if (this._kbAnimated === false || fit === 'contain') {
        el.style.transition = `opacity ${this._bgFadeDuration}ms ease-in-out`
        el.style.transform = 'none'
        return
      }

      const kbDir = pickKbDirection(this._kbPass ?? this._kbIndex, this._kbNamedDirection)
      el.style.transform = `scale(${kbDir.fromScale}) translate(${kbDir.fromX}%, ${kbDir.fromY}%)`

      // Animate Ken Burns — scale + pan simultaneously
      requestAnimationFrame(() => {
        el.style.transition = `opacity ${this._bgFadeDuration}ms ease-in-out, transform ${this._bgSlideMax}ms linear`
        el.style.transform  = `scale(${kbDir.toScale}) translate(${kbDir.toX}%, ${kbDir.toY}%)`
      })
    },

    /**
     * Apply the scene's transition (Animate tab) to the incoming background layer.
     *
     * Crossfade is what the player has always done and stays the default. A slide moves the
     * INCOMING layer only — sliding both at once reads as a shove rather than a change of scene —
     * and 'cut' means exactly that: no fade, no movement, straight to the next picture.
     */
    _applySceneTransition (scene) {
      const t = (scene?.config || {}).transition || {}
      const type = t.type || 'crossfade'
      const seconds = Number.isFinite(t.duration) ? t.duration : 0.8
      const ms = type === 'cut' ? 0 : Math.max(0, Math.min(3, seconds)) * 1000
      const ease = SCENE_EASINGS[t.ease] || SCENE_EASINGS.move

      this._bgFadeDuration = ms
      this._sceneTransition = { type, ms, ease }

      const incoming = this._bgActive === 'A' ? this._bgLayerB : this._bgLayerA
      if (!incoming) return

      const frames = sceneTransitionFrames(type)
      // Reset any transform left by the previous scene's slide before the next one starts, or the
      // layer begins its entrance from wherever the last transition happened to leave it.
      incoming.style.transition = 'none'
      incoming.style.transform = frames ? frames.from : 'none'

      if (!frames) return

      requestAnimationFrame(() => {
        incoming.style.transition = `opacity ${ms}ms ${ease}, transform ${ms}ms ${ease}`
        incoming.style.transform = frames.to
      })
    },

    // No image on this scene: a solid brand-navy backdrop instead of a stale leftover photo.
    _showFlatColor (color) {
      this._destroyParallax()
      if (_bgCanvas) _bgCanvas.style.opacity = '0'
      const bgLayer = document.getElementById('background-layer')
      if (bgLayer) { bgLayer.style.transition = 'opacity 1s ease-in-out'; bgLayer.style.opacity = '1' }
      if (this._kbInterval) { clearInterval(this._kbInterval); this._kbInterval = null }

      const el = this._bgActive === 'A' ? this._bgLayerB : this._bgLayerA
      if (!el) return
      el.style.backgroundImage = 'none'
      el.style.backgroundColor = color || '#0f172a'
      el.style.transform = 'none'
      el.style.opacity = '1'
      const other = this._bgActive === 'A' ? this._bgLayerA : this._bgLayerB
      if (other) other.style.opacity = '0'
      this._bgActive = this._bgActive === 'A' ? 'B' : 'A'
    },

    _startKenBurns () {
      const intervalMs = this._bgSlideMin + Math.random() * (this._bgSlideMax - this._bgSlideMin)
      this._kbInterval = setInterval(() => this._advanceBg(), intervalMs)
    },

    _advanceBg () {
      if (this._kbImages.length < 1) return
      this._kbIndex = (this._kbIndex + 1) % this._kbImages.length
      // Every drift gets the next direction. This used to key off _kbIndex, which is the IMAGE
      // index — fine while pictures kept changing, but with a single cover it stays 0, so each
      // repeat replayed the identical move and the picture snapped back to the start to do it.
      this._kbPass = (this._kbPass ?? 0) + 1
      const next = this._kbImages[this._kbIndex]

      if (this._bgActive === 'A') {
        this._showBgImage(next, 'B')
        this._bgLayerB.style.opacity = '1'
        this._bgLayerA.style.opacity = '0'
        this._bgActive = 'B'
      } else {
        this._showBgImage(next, 'A')
        this._bgLayerA.style.opacity = '1'
        this._bgLayerB.style.opacity = '0'
        this._bgActive = 'A'
      }
    },

    // ── Avatar wrapper & Three.js ──────────────────────────────────────
    _buildAvatarWrapper () {
      // Guard: if wrapper already exists (double-init from Vite HMR), reuse it
      const existing = document.getElementById('avatar-wrap')
      if (existing) { this._avatarWrap = existing; return }

      const canvas = document.getElementById('lesson-avatar-canvas')
      if (!canvas) return

      // Replace the full-screen blade canvas with a small positioned wrapper+canvas.
      // The avatar renders at bottom-right (portrait 9/16) with transparent background
      // so the 3D background scene shows through.
      const stage = document.getElementById('lesson-stage')
      canvas.remove()

      const wrap = document.createElement('div')
      wrap.id = 'avatar-wrap'
      wrap.className = `z-20 transition-all duration-700 ease-in-out ${AVATAR_POSITIONS[DEFAULT_POSITION]}`
      wrap.style.aspectRatio = '9/16'

      const newCanvas = document.createElement('canvas')
      newCanvas.id = 'lesson-avatar-canvas'
      newCanvas.className = 'w-full h-full'
      wrap.appendChild(newCanvas)
      stage.appendChild(wrap)

      this._avatarWrap = wrap
    },

    // ── Background 3D scene (skybox only, no character) ────────────────
    async _initBgScene () {
      // Skybox is opt-in — only lessons with a scene_view:'skybox' scene load the Three.js skybox.
      if (!this._usesSkybox) return

      // Destroy any stale instance (e.g. Vite HMR)
      if (_bgInstance) {
        try { _bgInstance.destroy?.() } catch (_) {}
        _bgInstance = null
      }

      const stage = document.getElementById('lesson-stage')
      if (!stage) return

      // Remove old bg canvas if present (re-init guard)
      document.getElementById('lesson-bg-canvas')?.remove()

      const canvas = document.createElement('canvas')
      canvas.id        = 'lesson-bg-canvas'
      // z-[1]: above Ken Burns (z-0), below gradient overlay (z-10) and avatar (z-20)
      // opacity-0: hidden during title screen — revealed when lesson starts via _showBgScene()
      canvas.className = 'absolute inset-0 w-full h-full pointer-events-none opacity-0 transition-opacity duration-1000 ease-in-out'
      canvas.style.zIndex = '1'
      stage.insertBefore(canvas, stage.firstChild)

      _bgCanvas = canvas   // module-level — set immediately (before any await) so _showBgScene never races

      // Wait a tick so the browser lays out the canvas (clientWidth/Height becomes non-zero).
      // Use setTimeout (not rAF) — rAF pauses in background/unfocused tabs.
      await new Promise(r => setTimeout(r, 50))

      try {
        // Dynamic import keeps the heavy Three.js skybox chunk out of the eager bundle.
        const { Avatar3DPlayer } = await import('./avatar-3d.js')
        _bgInstance = new Avatar3DPlayer(canvas, { characterUrl: null })
        await _bgInstance.init()

        // Disable user orbit — keep controls alive for autoRotate only
        if (_bgInstance._controls) {
          _bgInstance._controls.enableZoom   = false
          _bgInstance._controls.enablePan    = false
          _bgInstance._controls.enableRotate = false
          _bgInstance._controls.autoRotate   = true
          _bgInstance._controls.autoRotateSpeed = 0.12   // very slow pan
        }

        // Neutral dark bg while no skybox is loaded
        _bgInstance.setSceneBackground('#0c1a2e')

        // Pre-load first available image as initial skybox — fire-and-forget,
        // do NOT await here so this never blocks _assetsReady / startLesson
        const initialBg = lesson.cover_image_url
          || lesson.slideshow_images?.[0]?.url
          || lesson.scene_images?.[0]?.url
          || lesson.scenes?.[0]?.image_url
        if (initialBg) {
          _bgInstance.setSkyboxFromUrl(initialBg, 0.35).catch(() => {})
        }
      } catch (e) {
        console.warn('lesson-player: bg scene init failed', e)
      }
    },

    // Fade the 3D background scene in (called when lesson starts)
    _showBgScene () {
      // Pure-2D lesson: no 3D canvas — keep the Ken-Burns slideshow visible.
      if (!this._usesSkybox) return
      if (!_bgCanvas) return
      // Apply immediately (no rAF — rAF pauses in background/unfocused tabs)
      _bgCanvas.classList.remove('opacity-0')
      _bgCanvas.style.opacity = '1'

      // Fade out the CSS Ken Burns layers behind the 3D canvas
      const bgLayer = document.getElementById('background-layer')
      if (bgLayer) {
        bgLayer.style.transition = 'opacity 1.2s ease-in-out'
        bgLayer.style.opacity = '0'
      }
    },

    // Flat 2D scene render: the scene image on the CSS Ken Burns layers, 3D canvas hidden.
    // This is the default per scene (skybox is opt-in via scene_view).
    _showFlatScene (url) {
      if (!url) return
      this._destroyParallax()   // a flat shot replaces any live layered (parallax) scene
      if (_bgCanvas) _bgCanvas.style.opacity = '0'
      const bgLayer = document.getElementById('background-layer')
      if (bgLayer) {
        bgLayer.style.transition = 'opacity 1s ease-in-out'
        bgLayer.style.opacity = '1'
      }
      // Stop the title auto-slideshow so it doesn't fight the per-scene image.
      if (this._kbInterval) { clearInterval(this._kbInterval); this._kbInterval = null }
      // Crossfade to this scene's image with a fresh Ken Burns move.
      this._kbIndex = (this._kbIndex || 0) + 1
      const layer = this._bgActive === 'A' ? 'B' : 'A'
      this._showBgImage({ url }, layer)
      if (layer === 'B') {
        this._bgLayerB.style.opacity = '1'
        this._bgLayerA.style.opacity = '0'
        this._bgActive = 'B'
      } else {
        this._bgLayerA.style.opacity = '1'
        this._bgLayerB.style.opacity = '0'
        this._bgActive = 'A'
      }
    },

    // ── Storyboard shots: switch the flat image exactly when the narration reaches
    //    each shot's anchor sentence (via ElevenLabs character timings). ───────────
    _shotPlan: null,
    _shotCursor: 0,
    _parallaxReq: 0,   // async guard: only the latest layered-shot request may render

    // Route one shot to the right renderer: LAYERED (2-layer parallax) when it carries
    // a bg_url, otherwise today's flat Ken Burns path — every existing lesson stays flat.
    _showShot (shot, fallbackUrl = null) {
      if (shot?.bg_url || (Array.isArray(shot?.layers) && shot.layers.length)) { this._showLayeredShot(shot); return }
      this._showFlatScene(shot?.image_url || shot?.url || fallbackUrl)
    },

    // Layered shot (E3b): bg layer + optional transparent hero PNG, hero panning at
    // ~0.6× the background rate. Lazy-imports the ParallaxScene chunk once (same
    // pattern as the story-game engine) so flat-only lessons never download it.
    async _showLayeredShot (shot) {
      const req = ++this._parallaxReq
      try {
        if (!_parallaxMod) _parallaxMod = await import('./scene/ParallaxScene.js')
        if (req !== this._parallaxReq) return   // a newer shot superseded this one mid-import

        const host = document.getElementById('background-layer')
        if (!host) return

        // Same reveal path as _showFlatScene: hide the 3D canvas, show the 2D layer host.
        if (_bgCanvas) _bgCanvas.style.opacity = '0'
        host.style.transition = 'opacity 1s ease-in-out'
        host.style.opacity = '1'
        if (this._kbInterval) { clearInterval(this._kbInterval); this._kbInterval = null }

        // Derive pan/zoom from the same Ken Burns move a flat scene would have used.
        this._kbIndex = (this._kbIndex || 0) + 1
        let motion = { panX: 0, panY: 0, zoom: 1 }   // Motion off → calm static layers
        if (this._kbAnimated !== false) {
          const kb = pickKbDirection(this._kbIndex, this._kbNamedDirection)
          // Classic Ken Burns moves SHRINK (1.10 → 1.05); as a layer end-scale that would
          // dip below 1 and expose edges past the 6% bleed. Layers start at scale 1, so
          // express the move's zoom as magnitude-only, clamped to a gentle 1.0–1.12.
          const zoom = Math.min(1.12, 1 + Math.abs(kb.toScale - kb.fromScale))
          motion = { panX: kb.toX - kb.fromX, panY: kb.toY - kb.fromY, zoom }
        }

        this._destroyParallax()
        _parallax = new _parallaxMod.ParallaxScene(host)
        _parallax.show({
          bgUrl: shot.bg_url,
          heroUrl: shot.hero_url || null,
          // Multiplane shots (E3c): ordered back→front, each {url, depth, kind, …}.
          layers: Array.isArray(shot.layers) && shot.layers.length ? shot.layers : null,
          motion,
        })
      } catch (e) {
        console.warn('lesson-player: parallax scene failed, falling back to flat', e)
        this._showFlatScene(shot.image_url || shot.url || shot.bg_url)
      }
    },

    _destroyParallax () {
      if (!_parallax) return
      try { _parallax.destroy() } catch (_) { /* already detached */ }
      _parallax = null
    },

    _startShotPlayback (scene) {
      const shots = scene.shots || []
      this._showShot(shots[0], scene.image_url)
      this._shotCursor = 0
      // time: seconds when the shot should appear; null = unresolved (even-split fallback).
      // resolveAnchorTime / pickShotIndex are pure + unit-tested in scene/shot-sync.js.
      // bg_url/hero_url (optional, E3b) mark a shot as layered — see _showShot.
      this._shotPlan = shots.map((shot, i) => ({
        url: shot.image_url,
        bg_url: shot.bg_url ?? null,
        hero_url: shot.hero_url ?? null,
        layers: shot.layers ?? null,
        time: i === 0 ? 0 : resolveAnchorTime(scene.alignment, shot.anchor_sentence)
      }))
    },

    // Called on every audio timeupdate (piggybacks on _processScriptEvents).
    _processShots () {
      if (!this._audio) return

      // Chapter progress (drives the active segment fill in the chapter bar).
      this.sceneProgress = this._audio.duration
        ? Math.min(1, this._audio.currentTime / this._audio.duration)
        : 0

      // Drive the layered-scene parallax from narration progress (same tick as anchors).
      if (_parallax && this._audio.duration) {
        _parallax.update(this._audio.currentTime / this._audio.duration)
      }

      this._updateCaptions()

      if (!this._shotPlan || this._shotPlan.length < 2) return

      const target = pickShotIndex(this._shotPlan, this._audio.currentTime, this._audio.duration || 0)
      const next = this._shotPlan[target]
      if (target !== this._shotCursor && (next.url || next.bg_url || next.layers?.length)) {
        this._shotCursor = target
        this._showShot(next)
      }
    },

    _moveAvatarTo (position) {
      const posKey = position in AVATAR_POSITIONS ? position : DEFAULT_POSITION
      if (posKey === this._currentPosition) return
      this._currentPosition = posKey

      const wrap = this._avatarWrap
      if (!wrap) return

      // Swap CSS position classes on the small avatar wrapper
      Object.values(AVATAR_POSITIONS).forEach(cls =>
        cls.split(' ').forEach(c => wrap.classList.remove(c))
      )
      AVATAR_POSITIONS[posKey].split(' ').forEach(c => wrap.classList.add(c))
    },

    // ── Audio ──────────────────────────────────────────────────────────
    async _loadAudio () {
      // Build scene queue — use lesson-level audio if available, otherwise chain scenes
      if (lesson.audio_url) {
        _sceneQueue = [{ audio_url: lesson.audio_url, script: lesson.script, image_url: null }]
      } else if (lesson.scenes?.length) {
        // EVERY scene is playable — no kind filter. A quiz (or any element) can be attached to any
        // lesson and must never be silently dropped. Each kind renders via its branch in _playScene;
        // a plain scene with no narration audio holds briefly on its visual, then advances.
        _sceneQueue = lesson.scenes
          .map(s => ({
            id: s.id ?? null,   // needed by editSceneHref → deep-link "Edit scene" to THIS exact scene
            kind: s.kind, game_type: s.game_type ?? null, config: s.config ?? null, scene_view: s.scene_view,
            branch_group: s.branch_group ?? null, branch_role: s.branch_role ?? null,
            branch_choice_label: s.branch_choice_label ?? null,
            audio_url: s.audio_url, script: s.script, image_url: s.image_url,
            image_credit: s.image_credit ?? null,   // shown under the deck — CC BY obliges us to
            chapter_name: s.chapter_name ?? null, year: s.year ?? null,
            duration_seconds: s.duration_seconds ?? null,
            // shots entries carry image_url + anchor_sentence, plus optional bg_url/hero_url
            // (layered parallax shots, E3b) — the whole array passes through untouched.
            shots: s.shots ?? null, alignment: s.alignment ?? null,
            background_color: s.background_color ?? null,
            kb_animated: s.kb_animated, kb_direction: s.kb_direction ?? null,
            quiz_questions: s.quiz_questions ?? null,
            quiz_shuffle: s.quiz_shuffle ?? 'per_player',
          }))
      }

      if (!_sceneQueue.length) return

      // Chapter list for the Micrio-style serial-tour bar. Quiz/strategy/debate (kind 'game') are NOT
      // narrative chapters — exclude them, but keep each chapter's ORIGINAL queue index so the bar's
      // progress + click still map to _sceneIndex.
      this.chapters = _sceneQueue
        .map((s, i) => ({
          name: s.chapter_name || ('Chapter ' + (i + 1)),
          dur:  Number(s.duration_seconds) || 0,
          index: i,
          kind: s.kind,
        }))
        .filter(c => c.kind !== 'game')
      this.currentChapterName = this.chapters[0]?.name || ''

      // Warm every narrated scene up front, BEFORE the early return below — a lesson can open with
      // a quiz or map scene that has no audio of its own, and those lessons need warming most.
      this._warmNarration()

      // A map block can be first in the queue — only preload audio when the first scene has it.
      if (!_sceneQueue[0].audio_url) return

      // Pre-fetch visemes (only for single-audio lessons with visemes_url)
      if (lesson.visemes_url) {
        try {
          const res  = await fetch(lesson.visemes_url)
          const data = await res.json()
          this._pendingVisemes = data.mouthCues ?? []
        } catch (e) { /* optional */ }
      }

      // Pre-load the first scene so duration is known. 'auto', not 'metadata': narration is around
      // 750 KB per scene, and with metadata-only the audio data is not fetched until playback starts,
      // which stalled the opening of every scene.
      this._audio = new Audio(_sceneQueue[0].audio_url)
      this._audio.preload = 'auto'
      this._audio.load() // must call load() explicitly to start fetching
      await new Promise(resolve => {
        if (this._audio.readyState >= 1) { resolve(); return } // already loaded
        this._audio.addEventListener('loadedmetadata', resolve, { once: true })
        this._audio.addEventListener('error', resolve, { once: true })
        setTimeout(resolve, 3000) // safety timeout — never block indefinitely
      })
      this._scriptEvents = parseScriptTags(_sceneQueue[0].script, this._audio.duration)
    },

    /**
     * Warm every scene's narration into the HTTP cache, in order, one at a time.
     *
     * A lesson is roughly 750 KB of audio per scene. Fetched on demand, jumping to a chapter meant
     * waiting for that download — one to two seconds on classroom wifi, on a click that should feel
     * instant. Warming them while the first scene plays makes every later jump a cache hit.
     *
     * Deliberately sequential and started after the first scene is ready, so it never competes with
     * the audio the class is listening to right now. Failures are ignored: this is an optimisation,
     * and the player still fetches on demand if a warm never happened.
     */
    _warmNarration () {
      if (this._warmingNarration) return
      this._warmingNarration = true

      const urls = _sceneQueue
        .map(s => s.audio_url)
        .filter((url, i) => url && i > 0)

      const warmNext = () => {
        const url = urls.shift()
        if (!url) { this._warmingNarration = false; return }

        const probe = new Audio()
        probe.preload = 'auto'
        probe.src = url

        let settled = false
        const done = () => {
          if (settled) return
          settled = true
          probe.removeEventListener('canplaythrough', done)
          probe.removeEventListener('error', done)
          // Hold the reference until the fetch settles, then let it go; the bytes stay in the
          // browser's HTTP cache, which is what the next play reads from.
          setTimeout(warmNext, 150)
        }

        probe.addEventListener('canplaythrough', done, { once: true })
        probe.addEventListener('error', done, { once: true })
        // A stalled fetch must never halt the chain — move on and let that scene load on demand.
        setTimeout(done, 20000)
        probe.load()
      }

      // Give the first scene a moment of clear air before competing for bandwidth.
      setTimeout(warmNext, 1200)
    },

    // ── Script event processing ────────────────────────────────────────
    _processScriptEvents () {
      if (!this._audio) return
      this._processShots()
      const t = this._audio.currentTime

      while (this._lastEventIndex < this._scriptEvents.length) {
        const evt = this._scriptEvents[this._lastEventIndex]
        if (evt.time > t) break
        this._lastEventIndex++

        if (evt.type === 'position') {
          this._moveAvatarTo(evt.value)
        } else if (evt.type === 'game' && this.phase === 'GAME_BRIEF') {
          this._transitionToGameActive()
        } else if (evt.type === 'intel_drop' && this.phase === 'GAME_ACTIVE') {
          this._triggerIntelDrop()
        }
      }
    },

    // ── Phase transitions ──────────────────────────────────────────────
    _playIntro () {
      // Background is set per scene by _playScene (flat Ken Burns by default, skybox opt-in).
      this._moveAvatarTo('bottom-right')
      if (!_sceneQueue.length) return
      // Honour an embedded start-scene (wizard "Play" from the selected leg), once.
      const start = Math.min(Math.max(0, this._startSceneIndex || 0), _sceneQueue.length - 1)
      this._startSceneIndex = 0
      this._sceneIndex = start
      this._playScene(start)
    },

    _sceneIndex: 0,

    // The small grey line under the deck: where the words came from, and who made the picture.
    //
    // The image half is not decoration. Almost everything the sourcer finds on Wikimedia Commons is
    // CC BY or CC BY-SA, and both licences require naming the author wherever the work is shown —
    // so this has to follow the scene, not sit on a credits page nobody opens. Reads _sceneIndex
    // (reactive) so it changes with the picture.
    get attributionLine () {
      const scenes = (_sceneQueue && _sceneQueue.length) ? _sceneQueue : (this.lesson?.scenes || [])
      const credit = scenes[this._sceneIndex]?.image_credit
      return [this.lesson?.source_attribution, credit].filter(Boolean).join('  ·  ')
    },

    // Owner-preview "Edit scene": deep-link to the wizard scene editor (step 4) for the EXACT scene
    // being viewed, carrying whether the gallery/landfall modal is currently open so it can reopen.
    // Reads this._sceneIndex (reactive) so the link tracks the live scene.
    editSceneHref (base) {
      // Prefer the live play queue; before playback starts it's empty, so fall back to the lesson's
      // scene list (always present from window.LESSON) — both are the same ordered scenes.
      const scenes = (_sceneQueue && _sceneQueue.length) ? _sceneQueue : (this.lesson?.scenes || [])
      const scene = scenes[this._sceneIndex]
      const params = new URLSearchParams({ step: '4' })
      if (scene?.id != null) params.set('scene', String(scene.id))
      params.set('modal', window.__voyageGalleryOpen ? '1' : '0')
      return `${base}?${params.toString()}`
    },

    // Jump to a chapter from the bar / list.
    goToChapter (i) {
      this.chaptersOpen = false
      if (i == null || i < 0 || i >= _sceneQueue.length || i === this._sceneIndex) return
      this._sceneIndex = i
      this._playScene(i)
    },

    /**
     * Silence and release the current narration.
     *
     * `new Audio()` plays independently of the DOM, so simply assigning a new one to `this._audio`
     * leaves the previous track audible — every jump between chapters stacked another voice. Pausing
     * is not enough either: the old element's `ended`/`timeupdate` listeners stay live and a late
     * `ended` would advance the queue from a scene the student already left. So detach the handlers,
     * stop the fetch, and drop the reference.
     */
    _stopAudio () {
      const a = this._audio
      this._audio = null
      // A pending no-audio scene hold is the same kind of stale timer — cancel it too.
      clearTimeout(this._noAudioHold)
      if (!a) return
      try {
        a.pause()
        a.removeAttribute('src')
        a.load()          // aborts any in-flight download
      } catch (_) { /* element already torn down */ }
      this.audioPlaying = false
    },

    _playScene (index) {
      const scene = _sceneQueue[index]
      if (!scene) { this._onAudioEnded(); return }

      // A landfall gallery freezes this scene's auto-advance while it is open, and it is a GLOBAL
      // flag. If a tour is torn down with its modal still up, nothing ever clears it and every
      // later voyage scene sits on a frozen countdown with no visible modal to explain why. A new
      // scene means no modal is open by definition, so this is the one place it can be asserted.
      window.__voyageGalleryOpen = false
      this.readingOverlay = false

      // Moving to a new scene is an explicit "play" — never carry a pause across a chapter jump,
      // or the next scene would arrive frozen with no obvious reason why. Clear the flag directly
      // rather than through setPlaybackPaused: that would resume the OUTGOING scene's narration a
      // line before _stopAudio tears it down.
      if (this.playbackPaused) {
        this.playbackPaused = false
        try { _voyageInstance?.setPaused?.(false) } catch (_) { /* map gone */ }
      }

      // The outgoing scene's place/date must not linger on the next one — a voyage leg republishes
      // its own within the frame, anything else falls back to its chapter name and year.
      this.infoPlace = ''
      this.infoDate = ''

      // Whatever was narrating belongs to the scene we are leaving — silence it before anything
      // else, or jumping chapters layers voice over voice.
      this._stopAudio()

      // Same for a quiz card: it only clears itself on completion, so leaving a quiz scene
      // through a chapter jump left the card painted over every later scene.
      this._quizOverlay?.hide()

      // Chapter caption + bar (Micrio serial-tour). Chapters are filtered (no games), so look the
      // current one up by its queue index. A game scene (quiz) is not a chapter → hide the bar.
      this.currentIsGame = scene.kind === 'game'
      this.currentChapterName = (this.chapters?.find(c => c.index === index)?.name) || scene.chapter_name || ''
      this.sceneProgress = 0

      // Story game: 'hold' = engine shows its choice overlay and resumes via onAdvanceTo;
      // a number = skip a non-chosen branch option to the reconvergence index.
      if (this._storyGame) {
        const redirect = this._storyGame.beforePlay(index, scene)
        if (redirect === 'hold') return
        if (redirect != null && redirect !== index) { this._sceneIndex = redirect; return this._playScene(redirect) }
      }

      // Reset per-scene shot playback state (storyboard scenes rebuild it below).
      this._shotPlan = null
      this._shotCursor = 0

      // Subtitles follow the narration of THIS scene. Cues are built on the first timeupdate,
      // once the audio has reported its real length — every scene here goes on to play (or skip)
      // its own track, so resetting for all of them keeps the last scene's lines off this one.
      this._captionSource = { script: scene.script, alignment: scene.alignment || null }
      this._cues = []
      this._cueDuration = 0
      this.captionText = ''

      // The persistent voyage map is kept ALIVE only between voyage legs. A voyage lesson can now
      // open/close with standalone story slides (kind 'gallery'), so when this scene is NOT a voyage
      // leg, fully release the map first — otherwise its dead DOM lingers and backward nav replays a
      // torn-down instance.
      if (scene.kind !== 'voyage' && _voyageInstance) {
        try { _voyageInstance.destroy() } catch (_) { /* map already gone */ }
        _voyageInstance = null
        this._cancelVoyageAuto()
      }

      // Teacher text annotations for this scene (URLs render as link chips → iframe modal).
      // Before the map early-return, so a map scene clears the previous scene's texts too.
      this._renderSceneTexts(scene)
      // Teacher clipart layers a voyage scene carries ON TOP of the map (read-only in playback).
      // Also before the early-returns, so leaving a decorated scene clears its clipart.
      this._renderSceneArtwork(scene)

      // Embed background (Sketchfab 3D / video) — a full-bleed iframe behind the scene overlay.
      // Works for flat AND game scenes (quiz/debate); map/voyage own their whole stage so skip them.
      const _embed = scene.config && scene.config.bg_embed
      if (_embed && _embed.src && scene.kind !== 'map' && scene.kind !== 'voyage') {
        this._showEmbedBg(_embed)
      } else {
        this._hideEmbedBg()
      }

      // Map block — render the historical atlas as a slide (no audio).
      if (scene.kind === 'map') { this._playMapScene(index, scene); return }

      // Voyage tour leg / image gallery — audio-less slides driven by Next/Previous only.
      if (scene.kind === 'voyage') { this._playVoyageScene(index, scene); return }
      if (scene.kind === 'gallery') { this._playGalleryScene(index, scene); return }
      // Game scene (quiz / strategy / debate) — may or may not have a narrated intro.
      if (scene.kind === 'game') { this._playGameScene(index, scene); return }

      // Per-scene background motion settings (Animated toggle + Ken Burns direction).
      this._kbAnimated = scene.kb_animated !== false
      this._kbNamedDirection = scene.kb_direction || null
      // Crop anchor for this scene's background ('top' for portraits — see _showBgImage).
      this._bgFocus = scene.config?.background_focus || scene.focus || 'center'
      // How the background fills the stage: 'cover' (fill, crop) or 'contain' (whole image, bars).
      this._bgFit = scene.config?.background_fit || scene.background_fit || 'cover'

      // How this scene replaces the one before it (Animate tab). Read before the swap below so
      // _showBgImage / _showFlatColor apply it to whichever layer is coming in.
      this._applySceneTransition(scene)

      // Swap background. Default scenes are a flat Ken Burns slide (2D); skybox is opt-in per
      // scene; no image at all = the scene's solid backdrop (brand navy by default).
      if (scene.image_url || scene.shots?.length) {
        if (scene.scene_view === 'skybox' && _bgInstance) {
          this._showBgScene()                                   // reveal the 3D canvas, fade out the flat layer
          _bgInstance.setSkyboxFromUrl(scene.image_url, 0.3).catch(() => {})
        } else if (scene.shots?.length > 1) {
          this._startShotPlayback(scene)                        // storyboard: images follow the narration
        } else {
          this._showShot(scene.shots?.[0], scene.image_url)     // flat 2D Ken Burns, or layered when the shot has bg_url
        }
      } else if (scene.kind !== 'map') {
        this._showFlatColor(scene.background_color)
      }

      // No narration audio (e.g. a not-yet-narrated scene): hold on the visual for its set duration
      // (or a short default), then advance — never build `new Audio(null)`, which would hang the queue.
      if (!scene.audio_url) {
        const holdMs = (Number(scene.duration_seconds) > 0 ? Number(scene.duration_seconds) : 4) * 1000
        clearTimeout(this._noAudioHold)
        this._noAudioHold = setTimeout(() => this._afterSceneAudio(index, scene), holdMs)
        return
      }

      // Narrator is a flat 2D portrait badge — play the scene audio directly.
      // Every handler is gated on `a` still being the CURRENT track: if the student jumps away
      // mid-sentence, this element is discarded and must not advance the queue behind them.
      const a = this._audio = new Audio(scene.audio_url)
      this._attachAudioListeners()
      this._lastEventIndex = 0
      a.addEventListener('loadedmetadata', () => {
        if (this._audio !== a) return
        this._scriptEvents = parseScriptTags(scene.script, a.duration)
      })
      a.addEventListener('timeupdate', () => { if (this._audio === a) this._processScriptEvents() })
      a.addEventListener('ended', () => { if (this._audio === a) this._afterSceneAudio(index, scene) }, { once: true })
      a.play().catch(e => console.warn('lesson-player: autoplay blocked', e))
    },

    // ── Game scene (quiz / strategy / debate) ──────────────────────────
    // Render the scene's backdrop like a normal slide, then run the challenge. If a spoken intro was
    // narrated we play it first and start the game when it ends (via _afterSceneAudio); with no audio
    // we go straight into the challenge — a quiz used to be silently skipped when its intro had no audio.
    _playGameScene (index, scene) {
      if (scene.image_url || scene.shots?.length) {
        this._showShot(scene.shots?.[0], scene.image_url)
      } else {
        this._showFlatColor(scene.background_color)
      }
      if (scene.audio_url) {
        // Same discipline as _playScene: a discarded track must not start the quiz behind the student.
        const a = this._audio = new Audio(scene.audio_url)
        this._attachAudioListeners()
        this._lastEventIndex = 0
        a.addEventListener('loadedmetadata', () => {
          if (this._audio !== a) return
          this._scriptEvents = parseScriptTags(scene.script, a.duration)
        })
        a.addEventListener('timeupdate', () => { if (this._audio === a) this._processScriptEvents() })
        a.addEventListener('ended', () => { if (this._audio === a) this._afterSceneAudio(index, scene) }, { once: true })
        a.play().catch(e => console.warn('lesson-player: autoplay blocked', e))
      } else {
        this._afterSceneAudio(index, scene)   // no narration → begin the quiz/challenge now
      }
    },

    // ── Map block slide ────────────────────────────────────────────────
    async _playMapScene (index, scene) {
      // Silence any narration carried over from the previous scene.
      if (this._audio && !this._audio.paused) { this._audio.pause(); this.audioPlaying = false }

      const cfg = scene.config || {}
      const mode = cfg.playback_mode === 'timed' ? 'timed' : 'interactive'

      // Load the MapLibre map block (+ its ~1 MB volcanoes/terrain chunk) on demand — it is no
      // longer eagerly bundled with the player. The module sets window.renderLessonMap on import.
      if (!window.renderLessonMap) {
        try {
          await import('./lesson-map.js')
        } catch (e) {
          console.warn('lesson-player: map block failed to load — skipping map scene', e)
          this._advanceScene(index)
          return
        }
      }

      const stage = document.getElementById('lesson-map-stage')
      if (stage && window.renderLessonMap) {
        stage.style.display = 'block'
        stage.innerHTML = ''
        // Inner child: MapLibre stamps position:relative on its container, which would collapse
        // a full-bleed host to height 0.
        const inner = document.createElement('div')
        inner.style.width = '100%'
        inner.style.height = '100%'
        stage.appendChild(inner)
        _mapInstance = window.renderLessonMap(inner, {
          qid: cfg.qid || null,
          year: cfg.year ?? 1600,
          projection: cfg.projection || 'mercator',
          // per-block override → lesson-wide default → app default
          style: cfg.map_style || lesson.map_style || 'soft-atlas',
          relief: Number(lesson.map_relief) || 0,   // 3D terrain, lesson-wide
          interactive: mode === 'interactive',
          annotations: cfg.annotations || [],   // read-only focus cities for students
        })

        // Map-pinned text labels follow the map as the student pans/zooms.
        const textHost = document.getElementById('lesson-text-overlay')
        if (this._textLayer && textHost) {
          this._textLayer.setProjector(_mapInstance.textProjector(textHost))
          _mapInstance.map.on('move', () => this._textLayer?.refreshPositions())
        }
      }

      // Narrate the map as a VOICE-OVER, exactly as a voyage leg does. A map scene is paced by the
      // map itself (interactive Continue, or the timed hold below), never by the length of the
      // audio, so this must not be wired to _afterSceneAudio or it would advance the queue twice.
      // Without this the script authored for a map scene was generated and stored but never heard.
      if (scene.audio_url) {
        const a = this._audio = new Audio(scene.audio_url)
        this._attachAudioListeners()
        this._lastEventIndex = 0
        a.addEventListener('loadedmetadata', () => {
          if (this._audio !== a) return
          this._scriptEvents = parseScriptTags(scene.script, a.duration)
        })
        a.addEventListener('timeupdate', () => { if (this._audio === a) this._processScriptEvents() })
        a.play().catch(e => console.warn('lesson-player: autoplay blocked', e))
      }

      this.showMapContinue = (mode === 'interactive')
      if (mode === 'timed') {
        const hold = Math.max(2, Number(cfg.hold_seconds) || 7) * 1000
        clearTimeout(_mapTimer)
        _mapTimer = setTimeout(() => this._advanceFromMap(index), hold)
      }
    },

    // ── Voyage tour leg (kind 'voyage') ────────────────────────────────
    async _playVoyageScene (index, scene) {
      // (_playScene has already silenced the previous scene's narration.)
      const cfg = scene.config || {}

      // Narrate the leg as a VOICE-OVER while the ship sails. Deliberately not wired to
      // _afterSceneAudio: a voyage leg is paced by the map (onArrived → showMapContinue →
      // _startVoyageAuto), not by the length of the audio, so the track must never advance
      // the queue. Without this the narration authored for each leg was generated and stored
      // but never heard.
      if (scene.audio_url) {
        const a = this._audio = new Audio(scene.audio_url)
        this._attachAudioListeners()
        this._lastEventIndex = 0
        a.addEventListener('loadedmetadata', () => {
          if (this._audio !== a) return
          this._scriptEvents = parseScriptTags(scene.script, a.duration)
        })
        a.addEventListener('timeupdate', () => { if (this._audio === a) this._processScriptEvents() })
        a.play().catch(e => console.warn('lesson-player: autoplay blocked', e))
      }
      if (!window.renderVoyageTour) {
        try {
          await import('./voyage-tour.js')
        } catch (e) {
          console.warn('lesson-player: voyage tour failed to load — skipping scene', e)
          this._advanceScene(index)
          return
        }
      }
      const stage = document.getElementById('lesson-map-stage')
      if (!stage || !window.renderVoyageTour) { this._advanceScene(index); return }
      stage.style.display = 'block'
      this.showMapContinue = false
      // ONE persistent map for the whole voyage — build it once, then play each leg on it (no
      // rebuild/re-fit between scenes). The map/ships/fog/trail survive; playLeg eases the camera.
      if (!_voyageInstance) {
        stage.innerHTML = ''
        const inner = document.createElement('div')
        inner.style.width = '100%'
        inner.style.height = '100%'
        stage.appendChild(inner)
        // Period place labels come from the landfalls themselves — each voyage scene's location
        // pinned at its leg's arrival. The teacher hides modern cities/borders via game_config.voyage_map.
        const legLabels = _sceneQueue
          .filter(s => s.kind === 'voyage' && s.location)
          .map(s => ({
            text: s.location,
            leg: Number((s.config || {}).leg) || 0,
            // Overview scene = the port the voyage leaves from; the rest = the leg's landfall.
            at: (s.config || {}).overview ? 'depart' : 'arrive',
          }))
        _voyageInstance = window.renderVoyageTour(inner, {
          voyage: cfg.voyage,
          def: lesson.game_config?.voyage_def || null,       // lesson's editable copy wins over the catalog
          view: cfg.view || 'globe',
          routeLine: lesson.game_config?.route_line || null, // styled/animated trail (lesson-wide)
          mapOptions: lesson.game_config?.voyage_map || null, // hide cities/borders, show place labels
          style: cfg.map_style || lesson.map_style || 'soft-atlas', // same palette as a map scene
          relief: Number(lesson.map_relief) || 0,                   // …and the same 3D terrain
          legLabels,
          paintedFog: lesson.game_config?.voyage_fog || null, // teacher-painted undiscovered regions
          overviewAnim: cfg.overview_anim || null,            // itinerary: draw the route on
          // On each arrival: show the carousel controls + start the 10s auto-advance.
          onArrived: () => { this.showMapContinue = true; this._startVoyageAuto() },
        })
      }
      // The itinerary scene: the whole voyage on one screen, no leg sailed. Same branch the editor
      // takes (see step3-scene-configurator) — without it the player sailed leg 0 instead, so a
      // scene the teacher had set to Overview arrived as just another waypoint.
      if (cfg.overview) {
        // Already fitted when it appears — no fly-in. A lesson that opens by panning across the
        // world before it settles reads as the map loading, not as the itinerary being presented.
        _voyageInstance.showOverview({ animate: false })
        // Nothing sails, so no arrival will ever fire: hand the class the same controls a landfall
        // gets — side arrows and the 10s auto-advance — or the lesson would stop here for good.
        this.showMapContinue = true
        this._startVoyageAuto()
        return
      }
      _voyageInstance.playLeg(Number(cfg.leg) || 0, {
        intro: !!cfg.intro,
        stopImages: cfg.stop_images || [],
        gallery: cfg.gallery || null,
        hotspot: cfg.hotspot || null,
      })
    },

    // ── Voyage carousel: side arrows + auto-advance after 10s ──────────────
    _startVoyageAuto () {
      if (lesson.game_type !== 'voyage') return
      this._cancelVoyageAuto()
      const DUR = 10000
      let elapsed = 0
      let last = performance.now()
      const tick = (now) => {
        // Hold the countdown while the landfall gallery modal is open, or while the student
        // has paused — an auto-advance that fires on a paused stage is just a lost stop.
        if (!window.__voyageGalleryOpen && !this.playbackPaused) elapsed += now - last
        last = now
        this.autoAdvanceProgress = Math.min(1, elapsed / DUR)
        if (elapsed >= DUR) { this.advanceMap(); return }
        _autoAdvanceRaf = requestAnimationFrame(tick)
      }
      _autoAdvanceRaf = requestAnimationFrame(tick)
    },
    _cancelVoyageAuto () {
      if (_autoAdvanceRaf) { cancelAnimationFrame(_autoAdvanceRaf); _autoAdvanceRaf = null }
      this.autoAdvanceProgress = 0
    },

    // ── Image gallery (kind 'gallery') — auto-cycling slideshow, Next/Previous to leave ──
    _playGalleryScene (index, scene) {
      if (this._audio && !this._audio.paused) { this._audio.pause(); this.audioPlaying = false }
      const cfg = scene.config || {}
      const stage = document.getElementById('lesson-map-stage')
      if (!stage) { this._advanceScene(index); return }
      stage.style.display = 'block'
      stage.innerHTML = ''
      _galleryInstance = renderGallery(stage, cfg)
      this.showMapContinue = true
    },

    // Called by the Continue button (interactive) and the timer (timed).
    advanceMap () {
      this._advanceFromMap(this._sceneIndex)
    },

    // Previous slide for voyage lessons (← / A). Replays the previous scene from its start.
    previousSlide () {
      if (this._sceneIndex <= 0) return
      this._teardownStageScene()
      this._sceneIndex = this._sceneIndex - 1
      this._playScene(this._sceneIndex)
    },

    _teardownStageScene (full = false) {
      clearTimeout(_mapTimer)
      _mapTimer = null
      this._cancelVoyageAuto()
      // Voyage lessons keep ONE persistent map across all legs — between scenes we only stop the
      // sail (playLeg re-drives it) and keep the map alive. Full teardown runs at lesson end /
      // player unmount (below), or when leaving voyage for a different scene kind.
      if (_voyageInstance && lesson.game_type === 'voyage' && !full) { this.showMapContinue = false; return }
      if (_galleryInstance) { try { _galleryInstance.destroy() } catch (_) {} _galleryInstance = null }
      this._textLayer?.setProjector(null)   // map gone — pinned labels fall back to screen spots
      if (_mapInstance) { try { _mapInstance.destroy() } catch (_) {} _mapInstance = null }
      if (_voyageInstance) { try { _voyageInstance.destroy() } catch (_) {} _voyageInstance = null }
      const stage = document.getElementById('lesson-map-stage')
      if (stage) { stage.style.display = 'none'; stage.innerHTML = '' }
      this.showMapContinue = false
    },

    _advanceFromMap (index) {
      this._teardownStageScene()
      this._advanceScene(index)
    },

    // Advance to the next queued scene, or end the run.
    _advanceScene (index) {
      const next = index + 1
      if (next < _sceneQueue.length) {
        this._sceneIndex = next
        this._playScene(next)
      } else {
        this._onAudioEnded()
      }
    },

    _onAudioEnded () {
      if (this.phase === 'GAME_BRIEF') {
        this._transitionToGameActive()
      } else {
        // End of the narrative. Games are now driven by their own kind='game' scene, so the
        // old end-of-narration team reveal is no longer the fallback — finish the lesson.
        this._endLesson()
      }
    },

    _transitionToGameBrief () {
      this.phase = 'GAME_BRIEF'
      this._moveAvatarTo('right-centre')

      // Narrate the setup if a brief-audio segment exists, but DON'T auto-advance — the challenge
      // stays on screen until the teacher taps "Begin the challenge" (beginGame), so students have
      // time to read the story-aligned scenario.
      if (lesson.game_brief_audio_url) {
        this._audio = new Audio(lesson.game_brief_audio_url)
        this._attachAudioListeners()
        this._audio.play().catch(() => {})
      }
    },

    _transitionToGameActive () {
      if (this.phase === 'TIME_UP') return
      this.phase = 'GAME_ACTIVE'

      if (this._audio && !this._audio.paused) this._audio.pause()
      this._moveAvatarTo('bottom-right')

      this.timerSeconds = lesson.game_duration_seconds ?? this._gameDurationSecs
      this.showBigTimer = true

      // Show big timer for 5 seconds then shrink to HUD
      setTimeout(() => { this.showBigTimer = false }, 5000)

      this._startTimer()

      // Schedule intel drop if configured
      if (lesson.intel_drop_at_seconds) {
        const msUntilDrop = lesson.intel_drop_at_seconds * 1000
        setTimeout(() => this._triggerIntelDrop(), msUntilDrop)
      }
    },

    // ── Game flow (driven by a kind='game' scene) ──────────────────────
    // When narration reaches a game scene we play its intro audio, then show the
    // story-aligned challenge brief. The teacher taps "Begin the challenge" to start
    // the timer and "Continue the lesson" afterwards to return to the narrative.
    _afterSceneAudio (index, scene) {
      // Story game: branch scenes hand control to the engine (choice overlay after a
      // question, delta/consequence/game-over flow after a chosen option).
      if (this._storyGame?.handleSceneEnd(index, scene)) return
      if (scene && scene.kind === 'game') {
        this._gameResumeIndex = index + 1
        this.canResumeAfterGame = this._gameResumeIndex < _sceneQueue.length
        // Quiz segments show the actual questions inline (navigable card overlay);
        // the timer/teams flow below belongs to strategy games only.
        const segmentQuestions = scene.quiz_questions?.length ? scene.quiz_questions : lesson.quiz_questions
        if (scene.game_type === 'quiz' && segmentQuestions?.length) {
          this._beginQuizFlow(segmentQuestions, scene.quiz_shuffle)
          return
        }
        this._beginGameFlow(scene)
        return
      }
      const next = index + 1
      if (next < _sceneQueue.length) {
        this._sceneIndex = next
        this._playScene(next)
      } else {
        this._onAudioEnded()
      }
    },

    async _renderSceneTexts (scene) {
      const host = document.getElementById('lesson-text-overlay')
      if (!host) return
      const texts = scene?.config?.texts || []
      if (!texts.length && !this._textLayer) return
      const { TextOverlayLayer } = await import('./scene/TextOverlayLayer.js')
      this._textLayer = this._textLayer || new TextOverlayLayer(host, { editable: false })
      this._textLayer.setTexts(texts)
      // The map block may already be up (this import is async) — pin labels to it now.
      if (_mapInstance) {
        this._textLayer.setProjector(_mapInstance.textProjector(host))
        _mapInstance.map.on('move', () => this._textLayer?.refreshPositions())
      }
    },

    // Read-only clipart layers a teacher placed ON TOP of a voyage map. Same %-coordinate box as
    // the text overlay (both full-bleed, above the map stage), so a layer sits where the editor
    // showed it. Any non-voyage scene has no such layers → the host hides itself.
    async _renderSceneArtwork (scene) {
      const host = document.getElementById('lesson-voyage-art')
      if (!host) return
      const layers = ((scene.shots || [])[0]?.layers || []).filter(l => l && (l.url || l.embed) && l.asset_id != null)
      if (!layers.length && !this._artLayer) { host.style.display = 'none'; return }
      const { ArtworkOverlay } = await import('./scene/ArtworkOverlay.js')
      this._artLayer = this._artLayer || new ArtworkOverlay(host, { readonly: true })
      const onTop = !!(scene.config || {}).clipart_on_top
      this._artLayer.setOnTop(onTop)
      this._artLayer.setStackLevels(30, 32)   // on the nodes: a z-index on the host would kill blending
      host.style.display = layers.length ? '' : 'none'
      this._artLayer.setLayers(layers)
      // Each layer arrives the way the teacher set it in the Animate tab. Run after setLayers,
      // which rebuilds the nodes the animation targets.
      this._artLayer.playEntrances()
    },

    // Quiz segment: step through the lesson's questions in the card overlay, then
    // resume the story where it left off.
    async _beginQuizFlow (questions, shuffleMode = 'per_player') {
      const host = document.getElementById('lesson-game-overlay')
      if (!host) { this._resumeAfterQuiz(); return }
      const { QuizOverlay } = await import('./scene/QuizOverlay.js')
      this._quizOverlay = this._quizOverlay || new QuizOverlay(host)
      this._quizOverlay.show({
        questions: questions || lesson.quiz_questions,
        submitUrl: lesson.quiz_score_url || null,
        leaderboardUrl: lesson.leaderboard_url || null,
        hasClassroom: Boolean(lesson.has_classroom),
        shuffleMode,
        onComplete: () => this._resumeAfterQuiz(),
      })
    },

    _resumeAfterQuiz () {
      const next = this._gameResumeIndex ?? this._sceneIndex + 1
      if (next < _sceneQueue.length) {
        this._sceneIndex = next
        this._playScene(next)
      } else {
        this._onAudioEnded()
      }
    },

    _beginGameFlow (scene) {
      this._gameScene = scene
      if (scene.duration_seconds) this._gameDurationSecs = scene.duration_seconds
      this._transitionToGameBrief()
    },

    beginGame () {
      if (this._audio && !this._audio.paused) this._audio.pause()
      this._transitionToGameActive()
    },

    resumeAfterGame () {
      if (this._timerInterval) clearInterval(this._timerInterval)
      this.canResumeAfterGame = false
      const next = this._gameResumeIndex
      if (next != null && next < _sceneQueue.length) {
        this.phase = 'INTRO'
        this._sceneIndex = next
        this._playScene(next)
      } else {
        this._endLesson()
      }
    },

    _endLesson () {
      if (this._audio && !this._audio.paused) this._audio.pause()
      if (this._timerInterval) clearInterval(this._timerInterval)
      // The bed fades away over the closing screen rather than cutting on the last word.
      try { _music?.stop() } catch (_) {}
      _music = null
      try { this._teardownStageScene(true) } catch (_) { /* tear down the persistent voyage map */ }
      this.phase = 'ENDED'
      this.audioPlaying = false
      // Story game: the blade has no ENDED markup (everything x-hides), so the run
      // summary rendered into the engine's overlay host IS the finale screen.
      if (this._storyGame) this._storyGame.showSummaryInto(document.getElementById('story-game-overlay'))
    },

    // ── Timer ──────────────────────────────────────────────────────────
    _startTimer () {
      this._timerInterval = setInterval(() => {
        if (this.timerSeconds <= 0) {
          clearInterval(this._timerInterval)
          this.phase = 'TIME_UP'
          this._moveAvatarTo('middle')
          return
        }
        this.timerSeconds--
        const m = Math.floor(this.timerSeconds / 60)
        const s = String(this.timerSeconds % 60).padStart(2, '0')
        this.timerDisplay = `${m}:${s}`
      }, 1000)

      // Set initial display
      const m = Math.floor(this.timerSeconds / 60)
      const s = String(this.timerSeconds % 60).padStart(2, '0')
      this.timerDisplay = `${m}:${s}`
    },

    // ── Intel drop ─────────────────────────────────────────────────────
    _triggerIntelDrop () {
      if (this.phase !== 'GAME_ACTIVE') return

      this.prevPhase = this.phase
      this.phase = 'INTEL_DROP'
      this.intelDropMessage = lesson.intel_drop_message ?? 'New intelligence has come in. Adapt your strategy!'

      // Play intel drop audio if available
      if (lesson.intel_drop_audio_url) {
        this._intelAudio = new Audio(lesson.intel_drop_audio_url)
        this._intelAudio.play().catch(() => {})
        this._intelAudio.addEventListener('ended', () => this._endIntelDrop())
      } else {
        setTimeout(() => this._endIntelDrop(), 8000)
      }

      // Extend timer by 5 minutes
      this.timerSeconds += 5 * 60
    },

    _endIntelDrop () {
      this.phase = 'GAME_ACTIVE'
      this.prevPhase = null
      this.intelDropMessage = ''
    },

    // ── Playback control ───────────────────────────────────────────────
    /**
     * "Playing" for the overlay is broader than narration: on a voyage the ship sailing
     * between stops IS playback (showMapContinue only turns true at a landfall). The
     * player chrome hides while this is true and the pointer is away from the edges.
     */
    get isPlaying () {
      if (this.playbackPaused) return false
      if (this.audioPlaying) return true
      return lesson.game_type === 'voyage'
        && (this.phase === 'INTRO' || this.phase === 'GAME_ACTIVE')
        && !this.showMapContinue
    },

    /**
     * The one play/pause for the whole stage. A scene plays in more ways than narration —
     * a voyage leg sails, a landfall counts down to the next one — and every one of them has
     * to stop on a single press, whether it came from the deck button, Space, or a click on
     * the stage. Anything scene-specific hangs off this, never off the audio element alone.
     */
    togglePlayback () {
      this.setPlaybackPaused(!this.playbackPaused)
    },

    /**
     * Show the centre glyph for a moment, as feedback for a press.
     *
     * While paused it simply stays (setPlaybackPaused leaves `playbackGlyph` true and the view
     * keeps it on for as long as `playbackPaused` is). On resume it fades out on its own.
     */
    _flashPlaybackGlyph () {
      this.playbackGlyph = true
      clearTimeout(this._glyphTimer)
      if (this.playbackPaused) return          // a pause is a state worth showing — leave it up
      this._glyphTimer = setTimeout(() => { this.playbackGlyph = false }, 700)
    },

    setPlaybackPaused (pause) {
      this.playbackPaused = !!pause
      this._flashPlaybackGlyph()

      if (this._audio) {
        if (this.playbackPaused) {
          this._audio.pause()
        } else {
          this._audio.play().catch(e => console.warn('lesson-player: resume blocked', e))
        }
      }

      // The sailing ship freezes mid-ocean and picks up from the same spot; the landfall's
      // auto-advance countdown stops accumulating (see _startVoyageAuto).
      try { _voyageInstance?.setPaused?.(this.playbackPaused) } catch (_) { /* map gone */ }

      // Pausing the lesson pauses everything the lesson is making, music included — a bed still
      // playing over a frozen picture is how you get a teacher hunting for a second stop button.
      if (this.playbackPaused) _music?.pause()
      else _music?.resume()
    },

    _attachAudioListeners () {
      if (!this._audio) return
      // Every scene builds a fresh Audio, so the chosen volume/mute must be re-applied here —
      // without this, a muted lesson came back at full volume on the next scene.
      this._audio.volume = this.audioMuted ? 0 : this.volumeLevel
      this._audio.addEventListener('play', () => { this.audioPlaying = true }, { once: false })
      this._audio.addEventListener('pause', () => { this.audioPlaying = false }, { once: false })
      this._audio.addEventListener('ended', () => { this.audioPlaying = false }, { once: false })
    },

    toggleAudio () {
      if (!this._audio) return
      if (this._audio.paused) {
        this._audio.play().catch(e => console.warn('audio play failed:', e))
        this.audioPlaying = true
      } else {
        this._audio.pause()
        this.audioPlaying = false
      }
    },

    stopAudio () {
      if (!this._audio) return
      this._audio.pause()
      this._audio.currentTime = 0
      this.audioPlaying = false
    },

    /**
     * Mute means the lesson, not just the narration: the music bed and the quiz's right-answer
     * chime go quiet with it. A teacher who pressed M in a quiet classroom expects silence, and
     * a chime arriving anyway reads as the button being broken.
     */
    toggleMute () {
      this.audioMuted = !this.audioMuted
      if (this._audio) this._audio.volume = this.audioMuted ? 0 : this.volumeLevel
      this._syncSoundLevels()
    },

    /** Deck volume slider. Dragging to 0 mutes; dragging up from 0 unmutes. */
    setVolume (v) {
      const level = Math.min(1, Math.max(0, Number(v) || 0))
      this.audioMuted = level === 0
      if (level > 0) this.volumeLevel = level
      if (this._audio) this._audio.volume = level
      this._syncSoundLevels()
    },

    /** Push the deck's mute + volume out to everything else that makes a sound. */
    _syncSoundLevels () {
      const level = this.audioMuted ? 0 : this.volumeLevel
      _music?.setMuted(this.audioMuted)
      _music?.setVolume(this.volumeLevel)
      Sfx.setMuted(this.audioMuted)
      Sfx.setVolume(level)
    },

    // ── Subtitles ──────────────────────────────────────────────────────
    // Cues are built from the scene script against the audio's REAL length, which only the client
    // knows — duration_seconds is often unset, and Azure (the current narrator) returns no word
    // timings at all. Built once per scene, on the first timeupdate that reports a duration.
    _updateCaptions () {
      if (!this.captionsOn || !this._audio) { this.captionText = ''; return }

      const duration = this._audio.duration
      if (!Number.isFinite(duration) || duration <= 0) return

      if (!this._cues.length || this._cueDuration !== duration) {
        this._cues = buildCues(this._captionSource?.script, duration, this._captionSource?.alignment)
        this._cueDuration = duration
      }

      this.captionText = cueAt(this._cues, this._audio.currentTime)?.text || ''
    },

    /** Pick a state outright (the subtitles menu); toggleCaptions is the keyboard's way in. */
    setCaptions (on) {
      this.captionsOn = !!on
      // Someone who needs captions needs them in every lesson, so the choice outlives this one.
      try { localStorage.setItem('lp:captions', this.captionsOn ? '1' : '0') } catch (_) { /* private mode */ }
      this._updateCaptions()
    },

    toggleCaptions () {
      this.setCaptions(!this.captionsOn)
    },

    // ── Keyboard shortcuts ─────────────────────────────────────────────
    _attachKeyboardListeners () {
      this._kbHandler = (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return

        // Voyage lessons are slide decks: next = → or B, previous = ← or A. Nothing else needed.
        if (lesson.game_type === 'voyage' && (e.code === 'ArrowRight' || e.code === 'KeyB')) {
          e.preventDefault()
          this._advanceFromMap(this._sceneIndex)
          return
        }
        if (lesson.game_type === 'voyage' && (e.code === 'ArrowLeft' || e.code === 'KeyA')) {
          e.preventDefault()
          this.previousSlide()
          return
        }
        // The keys students already know from YouTube: K play/pause (Space too), M mute,
        // C subtitles, F fullscreen, ↑/↓ volume. A modifier means the key belongs to the
        // browser (⌘F is Find), so leave those alone.
        if (e.metaKey || e.ctrlKey || e.altKey) return

        if (e.code === 'Space' || e.code === 'KeyK') {
          e.preventDefault()
          this.togglePlayback()
        } else if (e.code === 'Escape') {
          e.preventDefault()
          this.stopAudio()
        } else if (e.code === 'KeyM') {
          e.preventDefault()
          this.toggleMute()
        } else if (e.code === 'KeyC') {
          e.preventDefault()
          this.toggleCaptions()
        } else if (e.code === 'KeyF') {
          e.preventDefault()
          this.toggleFullscreen()
        } else if (e.code === 'ArrowUp' || e.code === 'ArrowDown') {
          e.preventDefault()
          const step = e.code === 'ArrowUp' ? 0.05 : -0.05
          this.setVolume((this.audioMuted ? 0 : this.volumeLevel) + step)
        }
      }
      document.addEventListener('keydown', this._kbHandler)
    },

    /**
     * Fullscreen on the whole page, so the stage AND its controls go with it. Best effort:
     * iPhone Safari has no Element.requestFullscreen, and an embedded preview only gets it when
     * the host iframe allows it — never let either case throw at the student.
     */
    toggleFullscreen () {
      try {
        if (document.fullscreenElement) document.exitFullscreen?.()
        else document.documentElement.requestFullscreen?.().catch(() => {})
      } catch (_) { /* unsupported */ }
    },

    // ── Click the stage to pause ───────────────────────────────────────
    // Anywhere that isn't itself clickable acts as play/pause, the way a video does. Two things
    // must not be mistaken for that click: a control (button, link, map hotspot — everything
    // clickable in this player draws a pointer cursor, which is what we test for), and a map
    // pan or pinch, which is a pointerdown and a pointerup like any other.
    _attachStagePause () {
      const stage = document.getElementById('lesson-stage')
      if (!stage) return

      const DRAG_SLOP_PX = 6
      const HOLD_MS = 400

      let startX = 0
      let startY = 0
      let startT = 0

      const isControl = (el) => {
        for (let n = el; n && n !== stage; n = n.parentElement) {
          if (n.nodeType !== 1) continue
          if (n.matches('button, a, input, select, textarea, label, [role="button"], [data-no-pause]')) return true
          if (getComputedStyle(n).cursor === 'pointer') return true
        }
        return false
      }

      stage.addEventListener('pointerdown', (e) => {
        startX = e.clientX
        startY = e.clientY
        startT = performance.now()
      })

      stage.addEventListener('pointerup', (e) => {
        if (this.phase !== 'INTRO' && this.phase !== 'GAME_ACTIVE') return
        if (this.currentIsGame) return               // the quiz card owns its own clicks
        if (window.__voyageGalleryOpen) return       // so does an open landfall gallery
        if (Math.hypot(e.clientX - startX, e.clientY - startY) > DRAG_SLOP_PX) return  // a map pan
        if (performance.now() - startT > HOLD_MS) return                               // a press-and-hold
        if (isControl(e.target)) return

        this.togglePlayback()
      })
    },

    // ── Teardown ───────────────────────────────────────────────────────
    // Fully stop every Audio object. They live outside the DOM, so nothing
    // else releases them when the page goes away.
    // Leaving the page: silence the narration AND the intel-drop overlay.
    _stopAllAudio () {
      this._stopAudio()
      const intel = this._intelAudio
      this._intelAudio = null
      if (intel) {
        try { intel.pause(); intel.removeAttribute('src'); intel.load?.() } catch (_) {}
      }
      try { _music?.destroy() } catch (_) {}
      _music = null
      this.audioPlaying = false
    },

    // Alpine lifecycle hook — runs when the component element is removed,
    // including Livewire wire:navigate transitions. Belt-and-suspenders with
    // the pagehide / livewire:navigating listeners wired in init().
    destroy () {
      this._stopAllAudio()
      if (this._navStop) {
        window.removeEventListener('pagehide', this._navStop)
        document.removeEventListener('livewire:navigating', this._navStop)
        this._navStop = null
      }
      if (this._kbHandler) {
        document.removeEventListener('keydown', this._kbHandler)
        this._kbHandler = null
      }
      if (this._readingOverlayHandler) {
        window.removeEventListener('lesson:reading-overlay', this._readingOverlayHandler)
        this._readingOverlayHandler = null
      }
      if (this._chapterInfoHandler) {
        window.removeEventListener('lesson:chapter-info', this._chapterInfoHandler)
        this._chapterInfoHandler = null
      }
      clearInterval(this._timerInterval); this._timerInterval = null
      clearInterval(this._kbInterval);    this._kbInterval = null
      try { _bgInstance?.destroy?.() } catch (_) {}
      try { _mapInstance?.destroy?.() } catch (_) {}
      try { _voyageInstance?.destroy?.() } catch (_) {}
      _bgInstance = null
      _mapInstance = null
      _voyageInstance = null
      _initDone = false  // allow a later mount (e.g. opening another lesson) to re-init
    },
  }))

Alpine.start()
