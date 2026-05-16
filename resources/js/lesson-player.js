/**
 * lesson-player.js — Game loop engine for /lesson/{code}
 *
 * Phases:  LOADING → INTRO → TEAM_REVEAL → GAME_BRIEF → GAME_ACTIVE → TIME_UP
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
const KB_DIRECTIONS = [
  { fromX: 0,    fromY: 0,    toX: 3,    toY: 2    },
  { fromX: 3,    fromY: 0,    toX: 0,    toY: 2    },
  { fromX: 0,    fromY: 2,    toX: 3,    toY: 0    },
  { fromX: 1.5,  fromY: 1,    toX: 0,    toY: 0    },
  { fromX: 0,    fromY: 1,    toX: 2,    toY: 0    },
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

// ── Alpine component ──────────────────────────────────────────────────────────
// Alpine is imported directly (no Livewire on this page) — register before start()
Alpine.data('lessonGame', (lesson) => ({
    // ── State ──────────────────────────────────────────────────────────
    phase: 'LOADING',           // LOADING | INTRO | TEAM_REVEAL | GAME_BRIEF | GAME_ACTIVE | TIME_UP | INTEL_DROP
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

    // Internals
    _avatar:            null,
    _audio:             null,
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
      const { year, location } = extractYearAndLocation(lesson)
      this.lessonYear     = year
      this.lessonLocation = location

      this._kbImages = lesson.slideshow_images ?? []

      this._buildBackgroundLayer()
      this._buildAvatarWrapper()

      if (this._kbImages.length > 0) {
        this._showBgImage(this._kbImages[0], 'A')
        if (this._kbImages.length > 1) this._startKenBurns()
      }

      await this._initAvatar()
      await this._loadAudio()

      this.phase = 'INTRO'
      this._playIntro()
    },

    // ── Background / Ken Burns ─────────────────────────────────────────
    _buildBackgroundLayer () {
      const bg = document.getElementById('background-layer')
      if (!bg) return

      // Layer A
      const a = document.createElement('div')
      a.className = 'absolute inset-0 bg-cover bg-center transition-opacity ease-in-out'
      a.style.transitionDuration = `${this._bgFadeDuration}ms`
      a.style.opacity = '1'
      bg.appendChild(a)
      this._bgLayerA = a

      // Layer B
      const b = document.createElement('div')
      b.className = 'absolute inset-0 bg-cover bg-center transition-opacity ease-in-out'
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
      el.style.backgroundSize = '115%'
      el.style.transform = `translate(${kbDir.fromX}%, ${kbDir.fromY}%)`

      // Animate Ken Burns
      requestAnimationFrame(() => {
        el.style.transition = `opacity ${this._bgFadeDuration}ms ease-in-out, transform ${this._bgSlideMax}ms linear`
        el.style.transform = `translate(${kbDir.toX}%, ${kbDir.toY}%)`
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
      const canvas = document.getElementById('lesson-avatar-canvas')
      if (!canvas) return

      // Replace full-screen canvas with a smaller positioned wrapper+canvas
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
      const canvas = document.getElementById('lesson-avatar-canvas')
      if (!canvas) return

      // Find active avatar GLB — lesson avatar or first available
      const avatarUrl = lesson.avatar_glb_url ?? '/avatars/avatar.glb'

      try {
        this._avatar = new Avatar3DPlayer(canvas, { characterUrl: avatarUrl })
        // Make scene background transparent so background images show through
        await this._avatar.init()
        if (this._avatar._scene) {
          this._avatar._scene.background = null
          this._avatar._renderer.setClearAlpha(0)
        }
      } catch (e) {
        console.warn('lesson-player: avatar init failed', e)
      }
    },

    _moveAvatarTo (position) {
      const posKey = position in AVATAR_POSITIONS ? position : DEFAULT_POSITION
      if (posKey === this._currentPosition) return
      this._currentPosition = posKey

      const wrap = this._avatarWrap
      if (!wrap) return

      // Remove old position classes and apply new ones
      Object.values(AVATAR_POSITIONS).forEach(cls =>
        cls.split(' ').forEach(c => wrap.classList.remove(c))
      )
      AVATAR_POSITIONS[posKey].split(' ').forEach(c => wrap.classList.add(c))

      // After transition, tell avatar to snap eyes to camera
      setTimeout(() => {
        if (this._avatar) {
          this._avatar._gazeTimer = 0
          this._avatar._blinkNext = 200
        }
      }, 750)
    },

    // ── Audio ──────────────────────────────────────────────────────────
    async _loadAudio () {
      if (!lesson.audio_url) return

      this._audio = new Audio(lesson.audio_url)
      this._audio.preload = 'auto'

      if (lesson.visemes_url) {
        try {
          const res = await fetch(lesson.visemes_url)
          const data = await res.json()
          if (this._avatar) {
            this._avatar._visemeTimings = data.mouthCues ?? []
          }
        } catch (e) {
          // Visemes optional
        }
      }

      // Parse script events keyed to audio duration
      this._audio.addEventListener('loadedmetadata', () => {
        this._scriptEvents = parseScriptTags(lesson.script, this._audio.duration)
      })

      // Drive script events on timeupdate
      this._audio.addEventListener('timeupdate', () => this._processScriptEvents())

      this._audio.addEventListener('ended', () => this._onAudioEnded())
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
      this._moveAvatarTo('bottom-right')
      if (this._audio) {
        this._audio.play().catch(e => {
          // Autoplay blocked — show a tap-to-start overlay (future enhancement)
          console.warn('lesson-player: autoplay blocked', e)
        })
      }
    },

    _onAudioEnded () {
      if (this.phase === 'INTRO') {
        this._transitionToTeamReveal()
      } else if (this.phase === 'GAME_BRIEF') {
        this._transitionToGameActive()
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

      // If a game brief audio segment exists, play it
      if (lesson.game_brief_audio_url) {
        this._audio = new Audio(lesson.game_brief_audio_url)
        this._audio.addEventListener('ended', () => this._transitionToGameActive())
        this._audio.play().catch(() => this._transitionToGameActive())
      } else {
        // No brief audio — go straight to game after short delay
        setTimeout(() => this._transitionToGameActive(), 3000)
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
        const intelAudio = new Audio(lesson.intel_drop_audio_url)
        intelAudio.play().catch(() => {})
        intelAudio.addEventListener('ended', () => this._endIntelDrop())
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
  }))

Alpine.start()
