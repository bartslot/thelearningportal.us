/**
 * QuizOverlay — quiz questions with Duolingo-style gamification.
 *
 * Positive-only scoring: +10 per correct answer (+5 streak bonus from 3 in a row),
 * nothing lost on a wrong answer — it's a lesson, not a test. Correct answers pop,
 * burst particles and float "+10" into the score; wrong answers wobble gently and
 * reveal the right one. Answering IS the navigation: the card moves on by itself once
 * the student has had a moment with the answer, and the last one opens the score screen.
 *
 * The card is animation-led, not text-led (Figma "Multiple choice questions",
 * History-Portal-Game node 1291:1843). Nothing tells a class in words how they did:
 *
 *   1. the question pops in, scaling up past its size and settling
 *   2. a big number counts down in the middle of four blank bars, which is the read-gate —
 *      the answers are underneath, genuinely hidden, so there is nothing to read ahead
 *   3. the number scales away and the bars lift one at a time, revealing the answers in order
 *   4. answering pops the right one and wobbles a wrong one — no "Nice!", no "Almost!"
 *
 * The explanation stays: it is what the question TEACHES, not a verdict on the answer, and it is
 * the only place a class is told why the right answer is right.
 *
 * Styling is Tailwind + daisyUI theme tokens (the hp-modals gradient on a base-100 scrim, primary
 * amber, success/error for right and wrong) — no hard-coded hexes, so the quiz follows the
 * `learningportal` theme like everything else. Only the keyframes live in CSS (app.css);
 * all animation is transform/opacity only.
 *
 * Shared by the student player, wizard Preview and the Configure canvas.
 */
import { Sfx } from './sfx.js'
import { t } from '../i18n.js'
import { mountBigCountdown, BIG_COUNTDOWN_LEAVE_MS } from '../big-countdown.js'

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

// Shared chrome. `qz-card` carries the entrance animation (app.css). No border: the Figma card is
// the gradient and a shadow, and an outline drawn around it is chrome the design does not have.
const SCRIM = 'absolute inset-0 flex items-center justify-center bg-base-100/90 backdrop-blur-md'
const CARD = 'qz-card card hp-modals rounded-box shadow-2xl text-base-content'

// ── The read-gate, as the Figma tells it ───────────────────────────────────────────────────────
// Blank bars stand where the answers are, a number counts down in the middle of them, and then the
// bars lift one at a time.
//
// The Figma runs this at 800ms lead / 1000ms apart / 800ms each — 4.6s of cascade. That is right
// for the file it was drawn in, a ten-second loop watched once. It is wrong here, where a class
// sits through it six to twenty times in one lesson: it put the whole gate at 6.6-7.0s per
// question, up from 2.0-7.0s, and the French quiz walk in Playwright went from 58s to 72s.
//
// So the choreography is the Figma's and only the tempo is ours: the three numbers keep their
// 0.8 : 1 : 0.87 proportions, scaled to about a third. The bars still lift strictly one after
// another, with a beat between each finishing and the next starting — no overlap, no wave.
const REVEAL_LEAD_MS = 240
const REVEAL_STAGGER_MS = 320
const REVEAL_MS = 280

// The countdown is NOT scaled. It counts real seconds, one tick per second, and it counts at
// least the Figma's 3, 2, 1 — a number that ticks faster than a second is not a countdown, and
// a countdown that starts at 2 is a truncated one. See _readGateMs: this is in SECONDS because
// the gate is built up from whole seconds rather than rounded down to them.
const MIN_COUNTDOWN_SECONDS = 3

/** How long the bars take to lift, start to finish, for `n` answers. */
const revealMs = (n) => REVEAL_LEAD_MS + Math.max(0, n - 1) * REVEAL_STAGGER_MS + REVEAL_MS

/** A class that asked for less motion gets the answers at once, not a cascade. */
const prefersReducedMotion = () =>
  typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches

// How long the answered card holds before it moves on by itself: long enough to take in which
// answer was right, plus reading time for an explanation. Same reasoning as the read-gate, in the
// other direction.
const ADVANCE_MS = 1600
const ADVANCE_MS_PER_CHAR = 45
const ADVANCE_MS_MAX = 7000

// The end of the quiz hands the class back to the lesson on its own — a "Continue" button there is
// a dead end nobody has a reason NOT to press. The countdown says what is about to happen instead,
// and a tap anywhere skips the wait.
const AUTO_CONTINUE_SECONDS = 5

/**
 * "Lesson starts in 5", with the number in its own element so _startAutoContinue can tick it.
 *
 * The number is interpolated INTO the translation rather than glued onto the end of it: German
 * puts the verb last and French needs "dans", so a language that cannot move the number ends up
 * writing English word order in its own words. Both end screens render this, hence one function.
 */
const LESSON_STARTS_IN = (seconds) =>
  t('Lesson starts in :count', { count: `<span class="font-bold text-primary">${seconds}</span>` })

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
    this._bigCountdown = null   // the number in the middle of the bars, while the gate is shut
    this._revealTimers = []     // one per bar, lifting the answers into view in order
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

  /**
   * Reading time before the answers unlock: base 2s + ~55ms per character of question and options,
   * capped at 7s. Kills the 1-second straight-line sprint without feeling like a punishment.
   *
   * The bars lifting one at a time is part of that reading time, not something added after it —
   * the last answer is not on screen until the cascade ends. So the gate is a countdown plus a
   * cascade, and the countdown is a WHOLE NUMBER OF SECONDS.
   *
   * That last part is the whole reason this is not one expression. Measured in a browser with the
   * remainder left ragged, the first number sat on screen for 600ms and the rest for 1000ms: the
   * clock ticked correctly, it just started part-way through a second. A countdown whose first
   * number flashes past is not counting, so the seconds are decided first and the gate is built
   * from them, never the other way round.
   */
  static _readGateMs(q) {
    const text = String(q.question || '') + (q.options || []).join('')
    const read = Math.min(7000, 2000 + Math.round(text.length * 55 / 10))
    if (prefersReducedMotion()) return read

    const options = Math.min((q.options || []).length || LETTERS.length, LETTERS.length)
    const cascade = revealMs(options)
    const seconds = Math.max(MIN_COUNTDOWN_SECONDS, Math.round((read - cascade) / 1000))

    return seconds * 1000 + cascade
  }

  /** When the bars start lifting: the gate, less the time the cascade itself takes. */
  _cascadeStartsAt(index) {
    const deadline = this._gateUntil.get(index) ?? 0
    if (prefersReducedMotion()) return deadline
    const options = (this._display[index] || []).length || LETTERS.length
    return deadline - revealMs(Math.min(options, LETTERS.length))
  }

  get isVisible() { return this._questions.length > 0 }

  show({ questions, onComplete = null, submitUrl = null, leaderboardUrl = null, hasClassroom = false, shuffleMode = 'per_player', quizSceneId = null }) {
    this._questions = Array.isArray(questions) ? questions.filter(q => q?.question) : []
    this._index = 0
    this._answered = new Map()
    this._score = 0
    this._streak = 0
    this._onComplete = onComplete
    this._submitUrl = submitUrl
    // Which quiz scene this run is. The score resets to zero at every quiz, so the server
    // needs to know WHICH one it is being told about — otherwise a lesson's second quiz is
    // indistinguishable from a retry of the first, and one of them has to be wrong.
    this._quizSceneId = quizSceneId
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
    this._clearGateMotion()
    this.host.innerHTML = ''
    this.host.style.pointerEvents = 'none'
    if (this._gateTimer) { clearTimeout(this._gateTimer); this._gateTimer = null }
    if (this._advanceTimer) { clearTimeout(this._advanceTimer); this._advanceTimer = null }
    if (this._countdownTimer) { clearInterval(this._countdownTimer); this._countdownTimer = null }
    if (this._onVisibility) { document.removeEventListener('visibilitychange', this._onVisibility); this._onVisibility = null }
  }

  /**
   * Drop the gate's countdown and its pending bar-lifts.
   *
   * Every rebuild of the card throws the old nodes away, so a cascade left running would be
   * ticking against elements that are no longer on screen — and on the score screen it would be
   * ticking against no card at all.
   */
  _clearGateMotion() {
    this._revealTimers.forEach(clearTimeout)
    this._revealTimers = []
    this._bigCountdown?.el.remove()
    this._bigCountdown = null
  }

  _showFocusVeil() {
    this._focusDrops++
    if (this.host.querySelector('[data-focus-veil]')) return
    const veil = document.createElement('div')
    veil.dataset.focusVeil = '1'
    veil.className = 'absolute inset-0 z-20 flex cursor-pointer flex-col items-center justify-center gap-3.5 bg-base-100/95 text-base-content'
    veil.innerHTML = `
      <div class="text-5xl">&#128064;</div>
      <div class="text-[22px] font-extrabold">${t('Quiz paused')}</div>
      <div class="text-[15px] text-base-content/60">${t('Stay with the story. Tap to continue.')}</div>`
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
    // Whatever the last card had running belongs to nodes this render is about to throw away.
    this._clearGateMotion()
    const total = this._questions.length
    const chosen = this._answered.get(this._index)   // DISPLAY index
    const answered = chosen !== undefined
    const mapping = this._display[this._index] || (q.options || []).map((_, i) => i)

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

    const options = mapping.map(originalIndex => (q.options || [])[originalIndex]).slice(0, LETTERS.length)

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
      // Nothing says "wait" while the gate is shut: the bar over the answer is the whole message.
      const cursor = answered ? 'cursor-default' : (gated ? 'cursor-default' : 'cursor-pointer active:scale-[0.985]')
      // The bar that hides this answer while the gate is shut. It lives INSIDE the row rather than
      // in a stack of its own, so it covers exactly this row however many lines the answer wraps to
      // — a separate overlay of equal-height bars let the tall ones show their text between bars.
      // `-inset-px` swallows the row's own border, which would otherwise ring the bar in a
      // different colour. Opaque on purpose: it hides the answer, it does not dim it.
      const ghost = gated
        ? `<span data-ghost="${i}" class="qz-ghost pointer-events-none absolute -inset-px rounded-xl border"></span>`
        : ''
      return `
        <button data-opt="${i}" ${answered || gated ? 'disabled' : ''}
                class="${anim} ${tone} ${cursor} relative flex w-full items-center gap-4 rounded-xl border px-4 py-3
                       text-left text-[20px] font-medium transition-[background-color,border-color,transform,opacity] duration-150">
          <span class="${LETTER_CLASSES[i]} inline-flex h-[30px] w-9 shrink-0 items-center justify-center
                       rounded-lg text-[15px] font-extrabold">${LETTERS[i]}</span>
          <span>${this._escape(opt)}</span>
          ${ghost}
        </button>`
    }).join('')

    // What the question TEACHES, once it has been answered. No praise and no scolding: whether the
    // answer was right is already said by the pop, the wobble and the colour of the row. This line
    // is the only thing on the card that a class could not work out from the animation.
    const explanation = answered && q.explanation
      ? `<div class="qz-rise mt-3.5 text-sm leading-relaxed text-base-content/60">${this._escape(q.explanation)}</div>`
      : ''

    const streakBadge = this._streak >= STREAK_FROM
      ? `<span class="qz-streak mr-2.5 text-sm font-extrabold text-secondary" title="${this._streak} in a row">▲ ${this._streak} streak</span>`
      : ''

    this.host.innerHTML = `
      <div class="${SCRIM}">
        <div class="${CARD} relative mx-4 w-full max-w-3xl p-8 sm:px-12 sm:py-10">
          <div class="qz-question mb-8 text-center text-2xl leading-snug font-medium drop-shadow-[0_4px_4px_rgba(0,0,0,0.8)] sm:text-4xl">${this._escape(q.question)}</div>
          <div data-answers class="relative flex flex-col gap-2.5">${optionsHtml}</div>
          ${explanation}
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

    if (gated) this._startGateCountdown(gateLeft)

    if (effects?.kind === 'correct') this._playCorrectEffects(effects)
  }

  /**
   * The big number in the middle of the bars, counting down to the moment they start lifting.
   *
   * `data-gate-note` / `data-gate-secs` are the same handles the sentence carried, so anything
   * watching for "the answers are still locked" still sees it — it is a number now, not a line
   * of text telling a class to read.
   */
  _startGateCountdown(gateLeft) {
    this._clearGateMotion()

    const stack = this.host.querySelector('[data-answers]')
    if (!stack) return

    const untilCascade = Math.max(0, this._cascadeStartsAt(this._index) - performance.now())
    this._bigCountdown = mountBigCountdown(stack, {
      text: String(Math.ceil((untilCascade || gateLeft) / 1000)),
      digitClass: 'text-6xl text-base-content',
    })
    this._bigCountdown.el.dataset.gateNote = '1'
    this._bigCountdown.digit.dataset.gateSecs = '1'

    // A cascade that has already started (a re-render mid-gate) picks up where the clock says.
    this._scheduleReveal(untilCascade)
  }

  /**
   * Lift the bars off the answers, one at a time, and unlock the card when the last one is gone.
   *
   * Each bar carries its own delay rather than one timer walking the list, so the whole cascade is
   * declared in one pass and a torn-down card cancels all of it together.
   */
  _scheduleReveal(delay) {
    const ghosts = [...this.host.querySelectorAll('[data-ghost]')]

    if (prefersReducedMotion()) {
      this._revealTimers.push(setTimeout(() => {
        ghosts.forEach(g => g.remove())
        this._bigCountdown?.el.remove()
      }, delay))
      return
    }

    // The number goes first: it is standing over the bar that is about to lift.
    this._revealTimers.push(setTimeout(() => {
      const timer = this._bigCountdown
      this._bigCountdown = null
      timer?.leave()
    }, Math.max(0, delay - BIG_COUNTDOWN_LEAVE_MS)))

    ghosts.forEach((ghost, i) => {
      this._revealTimers.push(setTimeout(() => {
        // How long the bar takes to lift is REVEAL_MS, in both places it has to be known: the
        // timer that removes the node, and the CSS animation the class starts. Handing the
        // stylesheet the number means there is one, rather than a constant here and a duration
        // in app.css that agree until the day somebody tunes one of them.
        ghost.style.setProperty('--qz-reveal-ms', `${REVEAL_MS}ms`)
        ghost.classList.add('qz-ghost-out')
        // Leave nothing behind: the bar is `pointer-events-none`, but an invisible node stacked
        // over an answer is one CSS change away from swallowing the click on it.
        this._revealTimers.push(setTimeout(() => ghost.remove(), REVEAL_MS))
      }, delay + REVEAL_LEAD_MS + i * REVEAL_STAGGER_MS))
    })
  }

  // Read-gate countdown tick: counts the big number down and, when the gate opens, enables the
  // answers in place. Never re-renders the card, so its entrance animation plays once.
  _gateTick() {
    this._gateTimer = null
    if (!this.isVisible || this._answered.has(this._index)) return
    const gateLeft = Math.max(0, (this._gateUntil.get(this._index) ?? 0) - performance.now())

    if (gateLeft > 50) {
      // The number counts down to the first bar lifting, not to the unlock — from there on the
      // bars themselves show how much is left, which is the whole point of the redesign.
      const untilCascade = this._cascadeStartsAt(this._index) - performance.now()
      if (untilCascade > 0) this._bigCountdown?.setText(String(Math.ceil(untilCascade / 1000)))
      this._gateTimer = setTimeout(() => this._gateTick(), Math.min(gateLeft + 30, 250))
      return
    }

    // Gate open — unlock the options without a rebuild.
    if (!this._openedAt.has(this._index)) this._openedAt.set(this._index, performance.now())
    this._clearGateMotion()
    this.host.querySelectorAll('[data-ghost]').forEach(g => g.remove())
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
      // …and it does not get the wobble either. A question that reaches ahead of the story is
      // asking what a class already knows, so missing it is not a mistake to animate as one —
      // the right answer just pops. That reassurance used to be a sentence; now it is the motion.
      this._render({ kind: q.asks_ahead ? 'ahead' : 'wrong' })
    }

    this._scheduleAdvance(q)
  }

  /**
   * Answering IS the navigation — there is no Next button to press. The card holds long enough to
   * take in which answer was right (longer when there's an explanation), then moves to the next
   * question, or to the score screen if that was the last one.
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
    this._clearGateMotion()
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
        <div class="mb-2.5 text-[13px] uppercase tracking-[0.12em] text-base-content/60">${t('Join the leaderboard')}</div>
        ${this._hasClassroom ? `
        <div class="mb-2 flex justify-center gap-2">
          <input data-class-code type="text" maxlength="8" placeholder="${t('Class code…')}"
                 class="input input-bordered w-32 bg-base-300 text-center uppercase" />
        </div>` : ''}
        <div class="flex justify-center gap-2">
          <input data-nickname type="text" maxlength="24" placeholder="${t('Your name…')}"
                 class="input input-bordered w-52 bg-base-300" />
          <button data-submit class="btn btn-primary">${t('Submit')}</button>
        </div>
        <div data-join-error class="mt-1.5 min-h-4 text-xs text-error"></div>
      </div>` : ''

    this.host.innerHTML = `
      <div class="${SCRIM}">
        <div class="${CARD} mx-4 w-full max-w-lg p-10 text-center">
          <div class="mb-4 flex justify-center gap-2.5">${starsHtml}</div>
          <div class="mb-1.5 text-[15px] uppercase tracking-[0.15em] text-base-content/60">
            ${t(':correct of :total correct', { correct, total })}
          </div>
          <div data-final-score class="mb-6 text-6xl font-black text-warning">0</div>
          ${joinHtml}
          ${this._submitUrl
            ? `<button data-done class="btn btn-ghost btn-lg">${t('Skip')} ›</button>`
            : `<div data-countdown class="text-[15px] text-base-content/60">${LESSON_STARTS_IN(AUTO_CONTINUE_SECONDS)}</div>`}
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
      if (nickname.length < 2) { if (errorEl) errorEl.textContent = t('Pick a name (at least 2 letters).'); return }
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
            quiz_scene_id: this._quizSceneId,
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
        submitBtn.textContent = t('Submit')
        if (errorEl) errorEl.textContent = err?.message === 'HTTP 422'
          ? t('Check the class code, ask your teacher.')
          : t('Could not submit, try again.')
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
            ${this._escape(entry.nickname)}${isOwn ? ` · ${t('you')}` : ''}
          </span>
          <span class="text-[15px] font-extrabold text-warning">${entry.score}</span>
        </div>`
    }).join('')

    const ownOutsideTop = rank !== null && rank > top.length
      ? `<div class="mt-2.5 text-sm font-bold text-warning">${t('You are #:rank of :players. Keep climbing!', { rank, players })}</div>`
      : ''

    // Two keys rather than one with a plural marker: t() is a lookup, not Laravel's trans_choice,
    // and a language whose singular and plural differ has to be able to say both.
    const playerCount = players === 1
      ? t(':count player', { count: players })
      : t(':count players', { count: players })

    this.host.innerHTML = `
      <div class="${SCRIM}">
        <div class="${CARD} mx-4 max-h-[calc(100vh-60px)] w-full max-w-lg overflow-y-auto p-8 text-center">
          <div class="mb-1 text-[13px] uppercase tracking-[0.2em] text-primary">${t('Leaderboard')}</div>
          <div class="mb-4 text-[13px] text-base-content/50">${playerCount}</div>
          <div class="flex flex-col gap-1 text-left">${rows || `<span class="text-base-content/50">${t('No scores yet. You could be first!')}</span>`}</div>
          ${ownOutsideTop}
          <div data-countdown class="mt-6 text-[15px] text-base-content/60">${LESSON_STARTS_IN(AUTO_CONTINUE_SECONDS)}</div>
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
