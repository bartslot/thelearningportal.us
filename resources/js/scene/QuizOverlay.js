/**
 * QuizOverlay — quiz questions with Duolingo-style gamification.
 *
 * Positive-only scoring: +10 per correct answer (+5 streak bonus from 3 in a row),
 * nothing lost on a wrong answer — it's a lesson, not a test. Correct answers pop,
 * burst particles and float "+10" into the score; wrong answers wobble gently and
 * reveal the right one. Answering IS the navigation: the card moves on by itself once
 * the student has had a moment with the feedback, and the last one opens the score screen.
 *
 * Styling is Tailwind + daisyUI theme tokens (base-200 card on a base-100 scrim, primary
 * amber, success/error for right and wrong) — no hard-coded hexes, so the quiz follows the
 * `learningportal` theme like everything else. Only the keyframes live in CSS (app.css);
 * all animation is transform/opacity only.
 *
 * Shared by the student player, wizard Preview and the Configure canvas.
 */
import { Sfx } from './sfx.js'

const LETTERS = ['A', 'B', 'C', 'D']
// The four answer letters keep their Kahoot-style colour coding, drawn from the theme's
// semantic palette rather than raw hexes.
const LETTER_CLASSES = [
  'bg-error text-error-content',
  'bg-info text-info-content',
  'bg-warning text-warning-content',
  'bg-success text-success-content',
]
const POINTS_CORRECT = 10
const STREAK_BONUS = 5
const STREAK_FROM = 3
const PRAISE = ['Nice!', 'Great!', 'Perfect!', 'Brilliant!', 'On fire!']
const ENCOURAGE = ['Almost!', 'Good try!', 'Keep going!']

// Shared chrome. `qz-card` carries the entrance animation (app.css).
const SCRIM = 'absolute inset-0 flex items-center justify-center bg-base-100/80 backdrop-blur-md'
const CARD = 'qz-card card bg-base-200 border border-base-300 rounded-box shadow-2xl text-base-content'

// How long the answered card holds before it moves on by itself: long enough for the praise,
// plus reading time for an explanation. Same reasoning as the read-gate, in the other direction.
const ADVANCE_MS = 1600
const ADVANCE_MS_PER_CHAR = 45
const ADVANCE_MS_MAX = 7000

// The end of the quiz hands the class back to the lesson on its own — a "Continue" button there is
// a dead end nobody has a reason NOT to press. The countdown says what is about to happen instead,
// and a tap anywhere skips the wait.
const AUTO_CONTINUE_SECONDS = 5

export class QuizOverlay {
  constructor(hostEl) {
    this.host = hostEl
    this._questions = []
    this._index = 0
    this._answered = new Map()   // question index -> chosen DISPLAY index
    this._score = 0
    this._streak = 0
    this._onComplete = null
    // Integrity/engagement telemetry (reported to the teacher, invisible to students)
    this._display = []           // per question: display position -> original option index
    this._gateUntil = new Map()  // question index -> ts when answers unlock (read-gate)
    this._openedAt = new Map()   // question index -> ts when answers became clickable
    this._responses = []         // {ms, displayIndex} per answered question
    this._focusDrops = 0
    this._gateTimer = null
    this._advanceTimer = null   // answering is the navigation: the card moves on by itself
    this._countdownTimer = null // …and the last screen hands back to the lesson on its own
    this._onVisibility = null
  }

  // Fisher-Yates: each player sees the options in a different order, so a photographed
  // "the answer is B" (or a bored A-A-A-A) stops meaning anything.
  static _shuffledIndices(n) {
    const arr = Array.from({ length: n }, (_, i) => i)
    for (let i = arr.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1))
      ;[arr[i], arr[j]] = [arr[j], arr[i]]
    }
    return arr
  }

  // Mulberry32-seeded Fisher-Yates: identical order on every device (digibord + paper safe).
  static _seededShuffle(n, seed) {
    let s = seed >>> 0
    const rnd = () => { s = (s + 0x6D2B79F5) >>> 0; let t = Math.imul(s ^ (s >>> 15), 1 | s); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296 }
    const arr = Array.from({ length: n }, (_, i) => i)
    for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(rnd() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]] }
    return arr
  }

  // Reading time before answers unlock: base 2s + ~55ms per character of question+options,
  // capped at 7s. Kills the 1-second straight-line sprint without feeling like a punishment.
  static _readGateMs(q) {
    const text = String(q.question || '') + (q.options || []).join('')
    return Math.min(7000, 2000 + Math.round(text.length * 55 / 10))
  }

  get isVisible() { return this._questions.length > 0 }

  show({ questions, onComplete = null, submitUrl = null, leaderboardUrl = null, hasClassroom = false, shuffleMode = 'per_player' }) {
    this._questions = Array.isArray(questions) ? questions.filter(q => q?.question) : []
    this._index = 0
    this._answered = new Map()
    this._score = 0
    this._streak = 0
    this._onComplete = onComplete
    this._submitUrl = submitUrl
    this._leaderboardUrl = leaderboardUrl
    this._hasClassroom = hasClassroom
    this._classCode = (() => { try { return localStorage.getItem('lp_class_code') || '' } catch { return '' } })()
    if (!this._questions.length) { this.hide(); return }
    // Stable per-quiz salt so 'once' order is consistent within a class but not a cross-lesson
    // cheat sheet. Derived from the first question's text — deterministic (same salt → same
    // order on every device/render), yet different across quizzes.
    this._shuffleSalt = (questions?.[0]?.question || '').split('').reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7)
    this._display = this._questions.map((q, qi) => {
      const n = (q.options || []).length || 4
      if (shuffleMode === 'off') return Array.from({ length: n }, (_, i) => i)
      if (shuffleMode === 'once') return QuizOverlay._seededShuffle(n, qi + 1 + this._shuffleSalt)   // same order for everyone, salted per quiz
      return QuizOverlay._shuffledIndices(n)                                     // per player
    })
    this._gateUntil = new Map()
    this._openedAt = new Map()
    this._responses = []
    this._focusDrops = 0
    // Focus proctoring: leaving the tab/app pauses the quiz behind a veil and is counted.
    this._onVisibility = () => { if (document.hidden && this.isVisible) this._showFocusVeil() }
    document.addEventListener('visibilitychange', this._onVisibility)
    this.host.style.pointerEvents = 'auto'
    this._render()
  }

  hide() {
    this._questions = []
    this.host.innerHTML = ''
    this.host.style.pointerEvents = 'none'
    if (this._gateTimer) { clearTimeout(this._gateTimer); this._gateTimer = null }
    if (this._advanceTimer) { clearTimeout(this._advanceTimer); this._advanceTimer = null }
    if (this._countdownTimer) { clearInterval(this._countdownTimer); this._countdownTimer = null }
    if (this._onVisibility) { document.removeEventListener('visibilitychange', this._onVisibility); this._onVisibility = null }
  }

  _showFocusVeil() {
    this._focusDrops++
    if (this.host.querySelector('[data-focus-veil]')) return
    const veil = document.createElement('div')
    veil.dataset.focusVeil = '1'
    veil.className = 'absolute inset-0 z-20 flex cursor-pointer flex-col items-center justify-center gap-3.5 bg-base-100/95 text-base-content'
    veil.innerHTML = `
      <div class="text-5xl">&#128064;</div>
      <div class="text-[22px] font-extrabold">Quiz paused</div>
      <div class="text-[15px] text-base-content/60">Stay with the story — tap to continue.</div>`
    veil.addEventListener('click', () => veil.remove())
    this.host.firstElementChild?.appendChild(veil) || this.host.appendChild(veil)
  }

  _integritySummary() {
    const times = this._responses.map(r => r.ms).filter(ms => ms >= 0)
    const avg = times.length ? Math.round(times.reduce((a, b) => a + b, 0) / times.length) : 0
    const rapid = times.filter(ms => ms < 2000).length
    let maxRun = 0, run = 0, prev = null
    for (const r of this._responses) {
      run = r.displayIndex === prev ? run + 1 : 1
      prev = r.displayIndex
      maxRun = Math.max(maxRun, run)
    }
    return { avg_ms: avg, rapid_guesses: rapid, same_letter_streak: maxRun, focus_drops: this._focusDrops }
  }

  _correctCount() {
    let n = 0
    this._answered.forEach((chosenDisplay, i) => {
      const mapping = this._display[i] || []
      if (mapping[chosenDisplay] === Number(this._questions[i]?.correct_index)) n++
    })
    return n
  }

  _render(effects = null) {
    const q = this._questions[this._index]
    if (!q) return
    const total = this._questions.length
    const chosen = this._answered.get(this._index)   // DISPLAY index
    const answered = chosen !== undefined
    const mapping = this._display[this._index] || (q.options || []).map((_, i) => i)
    const wasCorrect = answered && mapping[chosen] === Number(q.correct_index)

    // Read-gate: answers unlock only after a reading delay (first arrival on the question).
    if (!answered && !this._gateUntil.has(this._index)) {
      this._gateUntil.set(this._index, performance.now() + QuizOverlay._readGateMs(q))
    }
    const gateLeft = answered ? 0 : Math.max(0, (this._gateUntil.get(this._index) ?? 0) - performance.now())
    const gated = gateLeft > 50
    if (!gated && !answered && !this._openedAt.has(this._index)) {
      this._openedAt.set(this._index, performance.now())
    }
    if (gated) {
      if (this._gateTimer) clearTimeout(this._gateTimer)
      // Tick in place — a full _render() would rebuild the card and replay its qz-slide-in
      // entrance every 500ms, making the whole modal flash until the gate opens.
      this._gateTimer = setTimeout(() => this._gateTick(), Math.min(gateLeft + 30, 250))
    }

    const options = mapping.map(originalIndex => (q.options || [])[originalIndex]).slice(0, 4)

    const optionsHtml = options.map((opt, i) => {
      const isCorrect = mapping[i] === Number(q.correct_index)
      // One full class string per state — Tailwind only ships classes it can see written out.
      let tone = 'bg-base-300/50 border-base-300 hover:border-primary/60 hover:bg-base-300'
      let anim = ''
      if (answered && isCorrect) {
        tone = 'bg-success/25 border-success'
        // The chosen right answer pops; on a wrong answer the same pop reveals the right one.
        anim = effects?.kind ? 'qz-correct' : ''
      } else if (answered && i === chosen && !isCorrect) {
        tone = 'bg-error/20 border-error'
        if (effects?.kind === 'wrong') anim = 'qz-wrong'
      }
      const cursor = answered ? 'cursor-default' : (gated ? 'cursor-wait opacity-45' : 'cursor-pointer active:scale-[0.985]')
      return `
        <button data-opt="${i}" ${answered || gated ? 'disabled' : ''}
                class="${anim} ${tone} ${cursor} flex w-full items-center gap-3 rounded-box border px-4 py-3
                       text-left text-[17px] transition-[background-color,border-color,transform,opacity] duration-150">
          <span class="${LETTER_CLASSES[i]} inline-flex h-7 w-7 shrink-0 items-center justify-center
                       rounded-lg text-[13px] font-bold">${LETTERS[i]}</span>
          <span>${this._escape(opt)}</span>
        </button>`
    }).join('')

    // Feedback strip under the options: praise or encouragement + explanation.
    let feedback = ''
    if (answered) {
      const word = effects?.word
        ?? (wasCorrect
          ? (q.asks_ahead ? 'You already knew this!' : PRAISE[this._index % PRAISE.length])
          : (q.asks_ahead ? "No worries — you'll hear this later in the story!" : ENCOURAGE[this._index % ENCOURAGE.length]))
      feedback = `
        <div class="qz-rise mt-3.5">
          <span class="text-base font-extrabold ${wasCorrect ? 'text-success' : 'text-warning'}">${word}</span>
          ${q.explanation ? `<span class="ml-2 text-sm leading-relaxed text-base-content/60">${this._escape(q.explanation)}</span>` : ''}
        </div>`
    }

    const streakBadge = this._streak >= STREAK_FROM
      ? `<span class="qz-streak mr-2.5 text-sm font-extrabold text-secondary" title="${this._streak} in a row">▲ ${this._streak} streak</span>`
      : ''

    this.host.innerHTML = `
      <div class="${SCRIM}">
        <div class="${CARD} relative mx-4 w-full max-w-2xl p-8">
          <div class="mb-5 text-2xl sm:text-3xl font-semibold leading-snug">${this._escape(q.question)}</div>
          ${gated ? `<div data-gate-note class="mb-2.5 flex items-center gap-2 text-[13px] text-base-content/60">
              <span class="loading loading-ring loading-xs text-primary"></span>
              Read the question&hellip; answers unlock in <span data-gate-secs>${Math.ceil(gateLeft / 1000)}</span>s</div>` : ''}
          <div class="flex flex-col gap-2.5">${optionsHtml}</div>
          ${feedback}
          <div class="mt-6 flex items-center justify-center gap-1.5">
            ${this._questions.map((_, i) => {
              const done = this._answered.has(i)
              // _answered holds a DISPLAY index — map it through _display[i] before comparing
              // to the (unshuffled) correct_index, mirroring _correctCount. Without the mapping
              // a correct answer in a shuffled position colours its dot wrong.
              const map = this._display[i] || []
              const ok = done && map[this._answered.get(i)] === Number(this._questions[i].correct_index)
              const tone = i === this._index ? 'bg-primary' : (done ? (ok ? 'bg-success' : 'bg-base-content/40') : 'bg-base-content/15')
              return `<span class="${tone} h-2 w-2 rounded-full transition-colors"></span>`
            }).join('')}
          </div>
          <div class="flex items-center justify-between">
            <span class="flex items-center text-[13px] text-base-content/60">
              ${streakBadge}
              <span data-score class="mr-3.5 inline-flex items-center gap-1.5 text-[15px] font-extrabold text-warning">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-[15px] w-[15px]"><path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/></svg>
                <span data-score-value>${this._score}</span>
              </span>
              <span data-pager>${this._index + 1} / ${total}</span>
            </span>
          </div>
        </div>
      </div>`

    this.host.querySelectorAll('[data-opt]').forEach(btn => {
      btn.addEventListener('click', () => this._answer(Number(btn.dataset.opt), btn))
    })

    if (effects?.kind === 'correct') this._playCorrectEffects(effects)
  }

  // Read-gate countdown tick: updates the seconds label and, when the gate opens, enables
  // the answers in place. Never re-renders the card, so its entrance animation plays once.
  _gateTick() {
    this._gateTimer = null
    if (!this.isVisible || this._answered.has(this._index)) return
    const gateLeft = Math.max(0, (this._gateUntil.get(this._index) ?? 0) - performance.now())
    const note = this.host.querySelector('[data-gate-note]')

    if (gateLeft > 50) {
      const secs = note?.querySelector('[data-gate-secs]')
      if (secs) secs.textContent = Math.ceil(gateLeft / 1000)
      this._gateTimer = setTimeout(() => this._gateTick(), Math.min(gateLeft + 30, 250))
      return
    }

    // Gate open — unlock the options without a rebuild.
    if (!this._openedAt.has(this._index)) this._openedAt.set(this._index, performance.now())
    note?.remove()
    this.host.querySelectorAll('[data-opt]').forEach(btn => {
      btn.disabled = false
      btn.classList.remove('cursor-wait', 'opacity-45')
      btn.classList.add('cursor-pointer', 'active:scale-[0.985]')
    })
  }

  _answer(displayIndex, buttonEl) {
    if (this._answered.has(this._index)) return
    if ((this._gateUntil.get(this._index) ?? 0) > performance.now() + 50) return   // still gated
    const q = this._questions[this._index]
    const mapping = this._display[this._index] || (q.options || []).map((_, i) => i)
    const correct = mapping[displayIndex] === Number(q.correct_index)
    this._answered.set(this._index, displayIndex)
    const openedAt = this._openedAt.get(this._index)
    this._responses.push({
      ms: openedAt !== undefined ? Math.round(performance.now() - openedAt) : -1,
      displayIndex,
      snapshot: {
        question_order: this._index + 1,
        question_text: String(q.question || ''),
        chosen_text: String((q.options || [])[mapping[displayIndex]] ?? ''),
        correct_text: String((q.options || [])[Number(q.correct_index)] ?? ''),
        was_correct: correct,
        response_ms: openedAt !== undefined ? Math.round(performance.now() - openedAt) : null,
        asks_ahead: Boolean(q.asks_ahead),
      },
    })

    if (correct) {
      this._streak++
      const bonus = this._streak >= STREAK_FROM ? STREAK_BONUS : 0
      const gained = POINTS_CORRECT + bonus
      const from = this._score
      this._score += gained
      const rect = buttonEl.getBoundingClientRect()
      this._render({ kind: 'correct', gained, from, at: { x: rect.left + rect.width / 2, y: rect.top } })
    } else {
      if (!q.asks_ahead) this._streak = 0   // guessing ahead is never punished
      this._render({ kind: 'wrong' })
    }

    this._scheduleAdvance(q)
  }

  /**
   * Answering IS the navigation — there is no Next button to press. The card holds long enough
   * to read the feedback (longer when there's an explanation), then moves to the next question,
   * or to the score screen if that was the last one.
   */
  _scheduleAdvance(q) {
    if (this._advanceTimer) clearTimeout(this._advanceTimer)
    const explanation = String(q?.explanation || '')
    const wait = Math.min(ADVANCE_MS_MAX, ADVANCE_MS + explanation.length * ADVANCE_MS_PER_CHAR)
    this._advanceTimer = setTimeout(() => {
      this._advanceTimer = null
      if (!this.isVisible) return
      if (this._index < this._questions.length - 1) { this._index++; this._render() }
      else this._renderScoreScreen()
    }, wait)
  }

  _playCorrectEffects({ gained, from, at }) {
    // The chime that says "yes" before the child has read anything. It follows the player's mute
    // button and volume slider like every other sound in the lesson (see scene/sfx.js).
    Sfx.play('correct')

    // Score ticker: count up with a bump.
    const scoreEl = this.host.querySelector('[data-score]')
    const valueEl = this.host.querySelector('[data-score-value]')
    if (scoreEl && valueEl) {
      scoreEl.classList.add('qz-score-bump')
      const target = this._score
      const start = performance.now()
      const tick = (now) => {
        const t = Math.min(1, (now - start) / 500)
        valueEl.textContent = Math.round(from + (target - from) * (1 - Math.pow(1 - t, 3)))
        if (t < 1) requestAnimationFrame(tick)
      }
      requestAnimationFrame(tick)
    }

    // Floating "+10" from the clicked answer. These two live on <body>, outside the card, and are
    // placed at the pixel the answer was clicked — so position stays inline, everything else is a class.
    const float = document.createElement('div')
    float.textContent = `+${gained}`
    float.className = 'pointer-events-none fixed z-90 -translate-x-1/2 text-[26px] font-black text-warning drop-shadow-lg'
    float.style.cssText = `left:${at.x}px; top:${at.y}px; animation: qz-float-up 0.9s ease-out forwards;`
    document.body.appendChild(float)
    setTimeout(() => float.remove(), 950)

    // Particle burst (10 dots, transform-only) in the theme's celebration colours.
    const sparkTones = ['bg-warning', 'bg-success', 'bg-info', 'bg-primary']
    for (let i = 0; i < 10; i++) {
      const p = document.createElement('div')
      const angle = (Math.PI * 2 * i) / 10 + Math.random() * 0.5
      const dist = 46 + Math.random() * 34
      p.className = `pointer-events-none fixed z-89 h-2 w-2 rounded-full ${sparkTones[i % sparkTones.length]}`
      p.style.cssText = `left:${at.x}px; top:${at.y}px;
        --dx:${Math.cos(angle) * dist}px; --dy:${Math.sin(angle) * dist - 20}px;
        animation: qz-burst 0.65s ease-out forwards;`
      document.body.appendChild(p)
      setTimeout(() => p.remove(), 700)
    }
  }

  _renderScoreScreen() {
    const total = this._questions.length
    const correct = this._correctCount()
    const ratio = total ? correct / total : 0
    const stars = ratio >= 0.9 ? 3 : ratio >= 0.6 ? 2 : 1

    // `backwards` fill keeps the 0% frame (hidden) during the stagger delay; once the animation
    // ends the star simply rests at its natural, fully-visible state — no dependence on the
    // keyframe's end opacity. The delay is data-driven, so it stays inline.
    const starsHtml = [0, 1, 2].map(i => `
      <svg viewBox="0 0 24 24" class="qz-star h-14 w-14 ${i < stars ? 'fill-warning' : 'fill-base-content/10'}"
           style="animation-delay:${0.15 + i * 0.22}s;">
        <path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
      </svg>`).join('')

    // Competition block: nickname entry when submission is enabled (student player).
    const savedName = (() => { try { return localStorage.getItem('lp_quiz_nickname') || '' } catch { return '' } })()
    const joinHtml = this._submitUrl ? `
      <div data-join class="qz-rise mb-6" style="animation-delay:0.6s;">
        <div class="mb-2.5 text-[13px] uppercase tracking-[0.12em] text-base-content/60">Join the leaderboard</div>
        ${this._hasClassroom ? `
        <div class="mb-2 flex justify-center gap-2">
          <input data-class-code type="text" maxlength="8" placeholder="Class code…"
                 class="input input-bordered w-32 bg-base-300 text-center uppercase" />
        </div>` : ''}
        <div class="flex justify-center gap-2">
          <input data-nickname type="text" maxlength="24" placeholder="Your name…"
                 class="input input-bordered w-52 bg-base-300" />
          <button data-submit class="btn btn-primary">Submit</button>
        </div>
        <div data-join-error class="mt-1.5 min-h-4 text-xs text-error"></div>
      </div>` : ''

    this.host.innerHTML = `
      <div class="${SCRIM}">
        <div class="${CARD} mx-4 w-full max-w-lg p-10 text-center">
          <div class="mb-4 flex justify-center gap-2.5">${starsHtml}</div>
          <div class="mb-1.5 text-[15px] uppercase tracking-[0.15em] text-base-content/60">
            ${correct} / ${total} correct
          </div>
          <div data-final-score class="mb-6 text-6xl font-black text-warning">0</div>
          ${joinHtml}
          ${this._submitUrl
            ? '<button data-done class="btn btn-ghost btn-lg">Skip ›</button>'
            : `<div data-countdown class="text-[15px] text-base-content/60">Lesson starts in <span class="font-bold text-primary">${AUTO_CONTINUE_SECONDS}</span></div>`}
        </div>
      </div>`

    // Restore remembered values via property assignment (never string-interpolated into
    // an attribute — a stored value containing a quote would otherwise break out and XSS).
    const nickEl = this.host.querySelector('[data-nickname]')
    if (nickEl) nickEl.value = savedName
    const classEl = this.host.querySelector('[data-class-code]')
    if (classEl) classEl.value = this._classCode

    // Count the final score up from 0 — the payoff moment.
    const el = this.host.querySelector('[data-final-score]')
    const start = performance.now()
    const target = this._score
    const tick = (now) => {
      const t = Math.min(1, (now - start) / 900)
      el.textContent = Math.round(target * (1 - Math.pow(1 - t, 3)))
      if (t < 1) requestAnimationFrame(tick)
    }
    requestAnimationFrame(tick)

    this.host.querySelector('[data-done]')?.addEventListener('click', () => this._finish())
    // Nothing left to decide (no leaderboard to join) → hand back to the lesson by itself.
    if (!this._submitUrl) this._startAutoContinue()

    // Competition: submit score under a nickname → show the leaderboard.
    const submitBtn = this.host.querySelector('[data-submit]')
    const nicknameEl = this.host.querySelector('[data-nickname]')
    const submit = async () => {
      const classCodeEl = this.host.querySelector('[data-class-code]')
      this._classCode = (classCodeEl?.value || '').trim().toUpperCase()
      try { if (this._classCode) localStorage.setItem('lp_class_code', this._classCode) } catch { /* private mode */ }
      const nickname = (nicknameEl?.value || '').trim()
      const errorEl = this.host.querySelector('[data-join-error]')
      if (nickname.length < 2) { if (errorEl) errorEl.textContent = 'Pick a name (at least 2 letters).'; return }
      try { localStorage.setItem('lp_quiz_nickname', nickname) } catch { /* private mode */ }
      submitBtn.disabled = true
      submitBtn.textContent = '…'
      try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        const res = await fetch(this._submitUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          body: JSON.stringify({
            nickname, score: this._score, correct, total,
            integrity: this._integritySummary(),
            answers: this._responses.map(r => r.snapshot).filter(Boolean),
            class_code: this._classCode || null,
            member_name: this._classCode ? nickname : null,
          }),
        })
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        const data = await res.json()
        this._renderLeaderboard(data, nickname)
      } catch (err) {
        submitBtn.disabled = false
        submitBtn.textContent = 'Submit'
        if (errorEl) errorEl.textContent = err?.message === 'HTTP 422'
          ? 'Check the class code — ask your teacher.'
          : 'Could not submit — try again.'
      }
    }
    submitBtn?.addEventListener('click', submit)
    nicknameEl?.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit() })
  }

  // Podium for the top 3, list for the rest, own entry highlighted with rank.
  _renderLeaderboard({ top = [], players = 0, rank = null }, ownNickname = '') {
    // Gold, silver, bronze — from the theme, not a hex list.
    const medals = ['bg-warning text-warning-content', 'bg-base-content text-base-100', 'bg-secondary text-secondary-content']
    const rows = top.map((entry, i) => {
      const isOwn = rank !== null && i === rank - 1 && entry.nickname === ownNickname
      const medal = i < 3
        ? `<span class="${medals[i]} inline-flex h-6.5 w-6.5 items-center justify-center rounded-full text-[13px] font-black">${i + 1}</span>`
        : `<span class="w-6.5 text-center text-[13px] font-bold text-base-content/50">${i + 1}</span>`
      const row = isOwn
        ? 'bg-primary/15 border-primary/50'
        : (i % 2 ? 'bg-base-content/5 border-transparent' : 'border-transparent')
      return `
        <div class="qz-rise ${row} flex items-center gap-3 rounded-box border px-3.5 py-2.5"
             style="animation-delay:${(0.08 * i).toFixed(2)}s;">
          ${medal}
          <span class="flex-1 truncate text-left text-[15px] ${i < 3 || isOwn ? 'font-bold' : 'font-medium'}">
            ${this._escape(entry.nickname)}${isOwn ? ' · you' : ''}
          </span>
          <span class="text-[15px] font-extrabold text-warning">${entry.score}</span>
        </div>`
    }).join('')

    const ownOutsideTop = rank !== null && rank > top.length
      ? `<div class="mt-2.5 text-sm font-bold text-warning">You're #${rank} of ${players} — keep climbing!</div>`
      : ''

    this.host.innerHTML = `
      <div class="${SCRIM}">
        <div class="${CARD} mx-4 max-h-[calc(100vh-60px)] w-full max-w-lg overflow-y-auto p-8 text-center">
          <div class="mb-1 text-[13px] uppercase tracking-[0.2em] text-primary">Leaderboard</div>
          <div class="mb-4 text-[13px] text-base-content/50">${players} player${players === 1 ? '' : 's'}</div>
          <div class="flex flex-col gap-1 text-left">${rows || '<span class="text-base-content/50">No scores yet — you could be first!</span>'}</div>
          ${ownOutsideTop}
          <div data-countdown class="mt-6 text-[15px] text-base-content/60">
            Lesson starts in <span class="font-bold text-primary">${AUTO_CONTINUE_SECONDS}</span>
          </div>
        </div>
      </div>`

    this._startAutoContinue()
  }

  /** Close the overlay and hand the lesson back. The one exit every end screen uses. */
  _finish() {
    const done = this._onComplete
    this.hide()
    done?.()
  }

  /**
   * Count down out loud, then continue: "Lesson starts in 5 … 4 … 3". A tap anywhere on the card
   * skips the rest of the wait, so nobody is held up by a number ticking down.
   */
  _startAutoContinue(seconds = AUTO_CONTINUE_SECONDS) {
    const label = this.host.querySelector('[data-countdown] span')
    if (!label) return
    let left = seconds
    this.host.querySelector('.qz-card')?.addEventListener('click', () => this._finish())
    if (this._countdownTimer) clearInterval(this._countdownTimer)
    this._countdownTimer = setInterval(() => {
      left -= 1
      if (left > 0) { label.textContent = String(left); return }
      clearInterval(this._countdownTimer)
      this._countdownTimer = null
      this._finish()
    }, 1000)
  }

  _escape(text) {
    const div = document.createElement('div')
    div.textContent = String(text ?? '')
    // textContent→innerHTML encodes < > & but NOT " or ' — unsafe for attribute
    // positions (e.g. value="${this._escape(x)}"). Encode those explicitly too.
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;')
  }
}
