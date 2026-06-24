/**
 * lesson-player.js — Game loop engine for /lesson/{code}
 *
 * Phases:  LOADING → TITLE_SCREEN → INTRO → TEAM_REVEAL → GAME_BRIEF → GAME_ACTIVE → TIME_UP
 * Special: INTEL_DROP overlays during GAME_ACTIVE and extends the timer.
 *
 * LLM script tags parsed from lesson.script:
 *   [position:bottom-right]  — move avatar to preset
 *   [position:left-centre]
 *   [position:middle]
 *   [position:right-centre]
 *   [teams]                  — transition to TEAM_REVEAL
 *   [game]                   — transition to GAME_BRIEF → GAME_ACTIVE
 *   [intel_drop]             — trigger INTEL_DROP mid-game
 */

import Alpine from 'alpinejs'
import { Avatar3DPlayer } from './avatar-3d.js'
import QRCode from 'qrcode'

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

function pickKbDirection (index) {
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
      } else if (inner === 'teams') {
        events.push({ time: estimatedTime, type: 'teams', value: null })
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
let _avatarInstance = null
let _bgInstance     = null   // background-only Avatar3DPlayer (skybox, no character)
let _bgCanvas       = null   // module-level ref so both Alpine instances share it
let _sceneQueue     = []   // [{kind, config, audio_url, script, image_url, alignment}] for scene-based playback
let _mapInstance    = null   // MapLibre map block instance (lives outside Alpine's proxy)
let _mapTimer       = null   // timed-mode auto-advance timer
let _initDone       = false  // guard: prevent double-init from Vite HMR / Alpine re-mount

// ── Alpine component ──────────────────────────────────────────────────────────
// Alpine is imported directly (no Livewire on this page) — register before start()
Alpine.data('lessonGame', (lesson) => ({
    // ── State ──────────────────────────────────────────────────────────
    phase:  'LOADING',          // LOADING | TITLE_SCREEN | INTRO | TEAM_REVEAL | GAME_BRIEF | GAME_ACTIVE | TIME_UP | INTEL_DROP | ENDED
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

    // Team reveal
    teamRevealCountdown: 5,
    _teamRevealInterval: null,
    teams: [],

    // Intel drop
    intelDropMessage: '',

    // Audio controls
    audioPlaying: false,
    audioMuted: false,
    _audioMutedVolume: 1.0,  // remember pre-mute volume

    // Map block
    showMapContinue: false,  // interactive map slide → show the Continue button

    // Internals  (_avatar lives outside Alpine proxy — see _avatarInstance module var)
    _audio:             null,
    _intelAudio:        null,   // intel-drop sfx — tracked so teardown can stop it
    _kbHandler:         null,   // keydown listener ref — removed on destroy()
    _navStop:           null,   // pagehide / livewire:navigating handler ref
    _scriptEvents:      [],
    _lastEventIndex:    0,
    _kbInterval:        null,
    _kbIndex:           0,
    _kbImages:          [],
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

      // Normalise all media URLs to the current page origin so localhost vs 127.0.0.1 mismatches don't block audio
      const fixUrl = (u) => u ? u.replace(/^https?:\/\/[^/]+/, window.location.origin) : u
      if (lesson.audio_url)    lesson.audio_url    = fixUrl(lesson.audio_url)
      if (lesson.visemes_url)  lesson.visemes_url  = fixUrl(lesson.visemes_url)
      if (lesson.cover_image_url) lesson.cover_image_url = fixUrl(lesson.cover_image_url)
      lesson.scenes?.forEach(s => { s.audio_url = fixUrl(s.audio_url); s.image_url = fixUrl(s.image_url) })
      lesson.scene_images?.forEach(s => { s.url = fixUrl(s.url) })
      lesson.slideshow_images?.forEach(s => { s.url = fixUrl(s.url) })

      const { year, location } = extractYearAndLocation(lesson)
      this.lessonYear     = year
      this.lessonLocation = location

      // Build image list: cover first, then slideshow images, then scene renders as fallback
      const coverImg    = lesson.cover_image_url ? [{ url: lesson.cover_image_url }] : []
      const slideshowImgs = lesson.slideshow_images ?? []
      const sceneImgs   = (slideshowImgs.length === 0) ? (lesson.scene_images ?? []) : []
      this._kbImages    = [...coverImg, ...slideshowImgs, ...sceneImgs]

      this._buildBackgroundLayer()
      this._buildAvatarWrapper()

      // Start Ken Burns immediately for title screen — faster interval (4-7s) for drama
      if (this._kbImages.length > 0) {
        this._showBgImage(this._kbImages[0], 'A')
        if (this._kbImages.length > 1) {
          this._bgSlideMin = 6000
          this._bgSlideMax = 6000
          this._startKenBurns()
        }
      }

      // Fire avatar + bg init in background — they should be ready by play time
      // but do NOT block startLesson on them (GLB load has no timeout and can hang).
      this._initAvatar()  // fire-and-forget
      this._initBgScene() // fire-and-forget

      // Only audio metadata is strictly required before lesson can start
      this._assetsReady = this._loadAudio()

      this.phase = 'TITLE_SCREEN'

      // Render QR code into the canvas element
      this._renderQr()

      // Attach keyboard listeners for audio controls
      this._attachKeyboardListeners()

      // new Audio() objects play independently of the DOM, so without explicit
      // teardown the narration keeps playing after the user leaves the page —
      // including the app's wire:navigate SPA transitions. Stop it on the way out.
      this._navStop = () => this._stopAllAudio()
      window.addEventListener('pagehide', this._navStop)
      document.addEventListener('livewire:navigating', this._navStop)
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
      // Unlock browser audio NOW — must happen synchronously before any await breaks the gesture chain
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext
        if (AudioCtx) { const ctx = new AudioCtx(); ctx.resume(); ctx.close() }
      } catch (_) {}

      this.phase = 'LOADING'
      try {
        await Promise.race([
          this._assetsReady,
          new Promise((_, rej) => setTimeout(() => rej(new Error('_assetsReady timeout after 15s')), 15000))
        ])
      } catch (e) {
        console.warn('lesson-player: assets load error (starting anyway):', e?.message)
      }

      // 3D bg canvas takes over — stop Ken Burns slideshow and reveal 3D scene
      clearInterval(this._kbInterval)
      this._kbInterval = null
      this._showBgScene()

      this.phase = 'INTRO'
      this._playIntro()
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

    _showBgImage (img, layer) {
      const el = layer === 'A' ? this._bgLayerA : this._bgLayerB
      if (!el || !img?.url) return

      const kbDir = pickKbDirection(this._kbIndex)
      el.style.backgroundImage = `url(${img.url})`
      el.style.backgroundSize  = 'cover'
      el.style.transform = `scale(${kbDir.fromScale}) translate(${kbDir.fromX}%, ${kbDir.fromY}%)`

      // Animate Ken Burns — scale + pan simultaneously
      requestAnimationFrame(() => {
        el.style.transition = `opacity ${this._bgFadeDuration}ms ease-in-out, transform ${this._bgSlideMax}ms linear`
        el.style.transform  = `scale(${kbDir.toScale}) translate(${kbDir.toX}%, ${kbDir.toY}%)`
      })
    },

    _startKenBurns () {
      const intervalMs = this._bgSlideMin + Math.random() * (this._bgSlideMax - this._bgSlideMin)
      this._kbInterval = setInterval(() => this._advanceBg(), intervalMs)
    },

    _advanceBg () {
      if (this._kbImages.length < 2) return
      this._kbIndex = (this._kbIndex + 1) % this._kbImages.length
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

    async _initAvatar () {
      // Destroy any existing instance first — prevents dual RAF loops from Vite HMR
      // or any re-init scenario where two Avatar3DPlayer instances fight over morph targets
      if (_avatarInstance) {
        try { _avatarInstance.destroy?.() } catch (_) {}
        _avatarInstance = null
        window._av = null
      }

      const canvas = document.getElementById('lesson-avatar-canvas')
      if (!canvas) return

      // Skip avatar entirely if no GLB is available
      const avatarUrl = lesson.avatar_glb_url || null
      if (!avatarUrl) {
        console.info('lesson-player: no avatar GLB — skipping 3D avatar')
        return
      }

      try {
        _avatarInstance = new Avatar3DPlayer(canvas, { characterUrl: avatarUrl, alpha: true })
        window._av = _avatarInstance
        await _avatarInstance.init()

        // Transparent background — 3D bg scene shows through the avatar canvas
        if (_avatarInstance._scene) {
          _avatarInstance._scene.background = null
          _avatarInstance._renderer.setClearAlpha(0)
        }

        // Lock camera — no orbit so avatar always faces viewer front-on
        if (_avatarInstance._controls) {
          _avatarInstance._controls.enabled = false
        }

        // Auto-load idle animation so avatar stands naturally instead of T-pose
        const gender      = (lesson.avatar_gender ?? 'male') === 'female' ? 'feminine' : 'masculine'
        const genderShort = gender === 'feminine' ? 'F' : 'M'
        const idleUrl     = `/avatars/animation-library/${gender}/glb/idle/${genderShort}_Standing_Idle_001.glb`
        _avatarInstance.loadBodyAnimation(idleUrl).catch(e =>
          console.warn('lesson-player: idle animation load failed', e)
        )
      } catch (e) {
        console.warn('lesson-player: avatar init failed', e)
      }
    },

    // ── Background 3D scene (skybox only, no character) ────────────────
    async _initBgScene () {
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

      // After transition snaps, re-engage gaze/blink
      setTimeout(() => {
        if (_avatarInstance) {
          _avatarInstance._gazeTimer = 0
          _avatarInstance._blinkNext = 200
        }
      }, 750)
    },

    // ── Audio ──────────────────────────────────────────────────────────
    async _loadAudio () {
      // Build scene queue — use lesson-level audio if available, otherwise chain scenes
      if (lesson.audio_url) {
        _sceneQueue = [{ audio_url: lesson.audio_url, script: lesson.script, image_url: null }]
      } else if (lesson.scenes?.length) {
        // Keep audio scenes AND map blocks (map blocks have no audio but are played as slides).
        _sceneQueue = lesson.scenes
          .filter(s => s.audio_url || s.kind === 'map')
          .map(s => ({ kind: s.kind, config: s.config ?? null, scene_view: s.scene_view, audio_url: s.audio_url, script: s.script, image_url: s.image_url, alignment: s.alignment ?? null }))
      }

      if (!_sceneQueue.length) return

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

      // Pre-load metadata of first scene so duration is known
      this._audio = new Audio(_sceneQueue[0].audio_url)
      this._audio.preload = 'metadata'
      this._audio.load() // must call load() explicitly to start fetching
      await new Promise(resolve => {
        if (this._audio.readyState >= 1) { resolve(); return } // already loaded
        this._audio.addEventListener('loadedmetadata', resolve, { once: true })
        this._audio.addEventListener('error', resolve, { once: true })
        setTimeout(resolve, 3000) // safety timeout — never block indefinitely
      })
      this._scriptEvents = parseScriptTags(_sceneQueue[0].script, this._audio.duration)
    },

    // ── Script event processing ────────────────────────────────────────
    _processScriptEvents () {
      if (!this._audio) return
      const t = this._audio.currentTime

      while (this._lastEventIndex < this._scriptEvents.length) {
        const evt = this._scriptEvents[this._lastEventIndex]
        if (evt.time > t) break
        this._lastEventIndex++

        if (evt.type === 'position') {
          this._moveAvatarTo(evt.value)
        } else if (evt.type === 'teams' && this.phase === 'INTRO') {
          this._transitionToTeamReveal()
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
      this._sceneIndex = 0
      this._playScene(0)
    },

    _sceneIndex: 0,

    _playScene (index) {
      const scene = _sceneQueue[index]
      if (!scene) { this._onAudioEnded(); return }

      // Map block — render the historical atlas as a slide (no audio).
      if (scene.kind === 'map') { this._playMapScene(index, scene); return }

      // Swap background. Default scenes are a flat Ken Burns slide (2D); skybox is opt-in per scene.
      if (scene.image_url) {
        if (scene.scene_view === 'skybox' && _bgInstance) {
          this._showBgScene()                                   // reveal the 3D canvas, fade out the flat layer
          _bgInstance.setSkyboxFromUrl(scene.image_url, 0.3).catch(() => {})
        } else {
          this._showFlatScene(scene.image_url)                  // flat 2D Ken Burns of the scene image
        }
      }

      if (_avatarInstance) {
        // Use ElevenLabs alignment for precise phoneme visemes, fall back to amplitude jaw
        if (scene.alignment?.length) {
          _avatarInstance.speakWithElevenLabsAlignment(scene.audio_url, scene.alignment, { zoom: false, delay: 0 })
        } else {
          _avatarInstance.speakWithVisemes(scene.audio_url, { zoom: false, delay: 0 })
        }

        // Bridge avatar's internal audio to our event system
        const bridgeAudio = () => {
          if (_avatarInstance._audio) {
            this._audio = _avatarInstance._audio
            this._attachAudioListeners()
            this._lastEventIndex = 0
            this._scriptEvents = parseScriptTags(scene.script, this._audio.duration || 0)
            this._audio.addEventListener('timeupdate', () => this._processScriptEvents(), { once: false })
            this._audio.addEventListener('ended', () => this._afterSceneAudio(index, scene), { once: true })
          } else {
            setTimeout(bridgeAudio, 50)
          }
        }
        bridgeAudio()
      } else {
        // No avatar — plain audio fallback
        this._audio = new Audio(scene.audio_url)
        this._attachAudioListeners()
        this._lastEventIndex = 0
        this._audio.addEventListener('loadedmetadata', () => {
          this._scriptEvents = parseScriptTags(scene.script, this._audio.duration)
        })
        this._audio.addEventListener('timeupdate', () => this._processScriptEvents())
        this._audio.addEventListener('ended', () => this._afterSceneAudio(index, scene), { once: true })
        this._audio.play().catch(e => console.warn('lesson-player: autoplay blocked', e))
      }
    },

    // ── Map block slide ────────────────────────────────────────────────
    _playMapScene (index, scene) {
      // Silence any narration carried over from the previous scene.
      if (this._audio && !this._audio.paused) { this._audio.pause(); this.audioPlaying = false }

      const cfg = scene.config || {}
      const mode = cfg.playback_mode === 'timed' ? 'timed' : 'interactive'

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
          interactive: mode === 'interactive',
        })
      }

      this.showMapContinue = (mode === 'interactive')
      if (mode === 'timed') {
        const hold = Math.max(2, Number(cfg.hold_seconds) || 7) * 1000
        clearTimeout(_mapTimer)
        _mapTimer = setTimeout(() => this._advanceFromMap(index), hold)
      }
    },

    // Called by the Continue button (interactive) and the timer (timed).
    advanceMap () {
      this._advanceFromMap(this._sceneIndex)
    },

    _advanceFromMap (index) {
      clearTimeout(_mapTimer)
      _mapTimer = null
      if (_mapInstance) { try { _mapInstance.destroy() } catch (_) {} _mapInstance = null }
      const stage = document.getElementById('lesson-map-stage')
      if (stage) { stage.style.display = 'none'; stage.innerHTML = '' }
      this.showMapContinue = false
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

    async _transitionToTeamReveal () {
      if (this.phase !== 'INTRO') return
      this.phase = 'TEAM_REVEAL'

      if (this._audio && !this._audio.paused) this._audio.pause()
      this._moveAvatarTo('left-centre')

      await this._loadTeams()
      this._renderTeamGrid()
      this._startTeamRevealCountdown()
    },

    async _loadTeams () {
      try {
        const res = await fetch(`/api/lesson/${lesson.lesson_code}/teams`)
        if (res.ok) {
          const data = await res.json()
          this.teams = data.teams ?? []
        }
      } catch (e) {
        // Teams optional at this stage; game can proceed without
      }
    },

    _renderTeamGrid () {
      const grid = document.getElementById('team-list-grid')
      if (!grid) return
      grid.innerHTML = ''

      const TEAM_COLORS = ['amber', 'sky', 'emerald', 'rose', 'violet', 'orange']

      this.teams.forEach((team, i) => {
        const color = TEAM_COLORS[i % TEAM_COLORS.length]
        const card = document.createElement('div')
        card.className = `rounded-2xl border border-${color}-500/30 bg-slate-900/80 backdrop-blur-sm p-5`
        card.innerHTML = `
          <p class="font-history text-xl font-bold text-${color}-400 mb-3">${team.name}</p>
          <ul class="space-y-1">
            ${(team.members ?? []).map(m =>
              `<li class="text-sm text-slate-300">${m.name ?? m}</li>`
            ).join('')}
          </ul>
        `
        grid.appendChild(card)
      })
    },

    _startTeamRevealCountdown () {
      this.teamRevealCountdown = 5
      this._teamRevealInterval = setInterval(() => {
        this.teamRevealCountdown--
        if (this.teamRevealCountdown <= 0) {
          clearInterval(this._teamRevealInterval)
          this._transitionToGameBrief()
        }
      }, 1000)
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
      if (scene && scene.kind === 'game') {
        this._gameResumeIndex = index + 1
        this.canResumeAfterGame = this._gameResumeIndex < _sceneQueue.length
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
      this.phase = 'ENDED'
      this.audioPlaying = false
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

    // ── Audio Control Methods ──────────────────────────────────────────
    _attachAudioListeners () {
      if (!this._audio) return
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

    toggleMute () {
      if (!this._audio) return
      if (this.audioMuted) {
        this._audio.volume = this._audioMutedVolume
        this.audioMuted = false
      } else {
        this._audioMutedVolume = this._audio.volume
        this._audio.volume = 0
        this.audioMuted = true
      }
    },

    // ── Keyboard shortcuts ─────────────────────────────────────────────
    _attachKeyboardListeners () {
      this._kbHandler = (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return

        if (e.code === 'Space') {
          e.preventDefault()
          this.toggleAudio()
        } else if (e.code === 'Escape') {
          e.preventDefault()
          this.stopAudio()
        } else if (e.code === 'KeyM') {
          e.preventDefault()
          this.toggleMute()
        }
      }
      document.addEventListener('keydown', this._kbHandler)
    },

    // ── Teardown ───────────────────────────────────────────────────────
    // Fully stop every Audio object. They live outside the DOM, so nothing
    // else releases them when the page goes away.
    _stopAllAudio () {
      for (const a of [this._audio, this._intelAudio]) {
        if (!a) continue
        try { a.pause(); a.src = ''; a.load?.() } catch (_) {}
      }
      this._audio = null
      this._intelAudio = null
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
      clearInterval(this._timerInterval);      this._timerInterval = null
      clearInterval(this._teamRevealInterval); this._teamRevealInterval = null
      clearInterval(this._kbInterval);         this._kbInterval = null
      try { _avatarInstance?.destroy?.() } catch (_) {}
      try { _bgInstance?.destroy?.() } catch (_) {}
      try { _mapInstance?.destroy?.() } catch (_) {}
      _avatarInstance = null
      _bgInstance = null
      _mapInstance = null
      _initDone = false  // allow a later mount (e.g. opening another lesson) to re-init
    },
  }))

Alpine.start()
