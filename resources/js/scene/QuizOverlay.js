/**
 * QuizOverlay — quiz questions with Duolingo-style gamification.
 *
 * Positive-only scoring: +10 per correct answer (+5 streak bonus from 3 in a row),
 * nothing lost on a wrong answer — it's a lesson, not a test. Correct answers pop,
 * burst particles and float "+10" into the score; wrong answers wobble gently and
 * reveal the right one. The last question leads to a score screen with 1-3 stars.
 *
 * Shared by the student player, wizard Preview and the Configure canvas.
 * All animation is transform/opacity only.
 */
const BRAND_NAVY = '#0f172a'
const AMBER = '#f59e0b'
const LETTERS = ['A', 'B', 'C', 'D']
const LETTER_COLORS = ['#e11d48', '#0284c7', '#d97706', '#059669']
const POINTS_CORRECT = 10
const STREAK_BONUS = 5
const STREAK_FROM = 3
const PRAISE = ['Nice!', 'Great!', 'Perfect!', 'Brilliant!', 'On fire!']
const ENCOURAGE = ['Almost!', 'Good try!', 'Keep going!']

let stylesInjected = false
function injectStyles() {
  if (stylesInjected) return
  stylesInjected = true
  const style = document.createElement('style')
  style.textContent = `
    @keyframes qz-pop { 0% { transform: scale(1); } 45% { transform: scale(1.08); } 100% { transform: scale(1); } }
    @keyframes qz-wobble { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-7px); } 55% { transform: translateX(6px); } 80% { transform: translateX(-3px); } }
    @keyframes qz-float-up { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 15% { opacity: 1; transform: translateY(-8px) scale(1.15); } 100% { transform: translateY(-64px) scale(1); opacity: 0; } }
    @keyframes qz-burst { 0% { transform: translate(0,0) scale(1); opacity: 1; } 100% { transform: translate(var(--dx), var(--dy)) scale(0.3); opacity: 0; } }
    @keyframes qz-score-pop { 0% { transform: scale(1); } 50% { transform: scale(1.35); } 100% { transform: scale(1); } }
    @keyframes qz-star-in { 0% { transform: scale(0) rotate(-30deg); opacity: 0; } 60% { transform: scale(1.25) rotate(8deg); opacity: 1; } 100% { transform: scale(1) rotate(0); opacity: 1; } }
    @keyframes qz-slide-in { 0% { transform: translateY(14px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
    @keyframes qz-flame { 0%,100% { transform: scale(1) rotate(-2deg); } 50% { transform: scale(1.12) rotate(2deg); } }
    .qz-card { animation: qz-slide-in 0.28s cubic-bezier(0.16, 1, 0.3, 1); }
    .qz-correct { animation: qz-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .qz-wrong { animation: qz-wobble 0.45s ease-in-out; }
    .qz-score-bump { animation: qz-score-pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .qz-streak { animation: qz-flame 0.9s ease-in-out infinite; display: inline-block; }
  `
  document.head.appendChild(style)
}

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

  // Reading time before answers unlock: base 2s + ~55ms per character of question+options,
  // capped at 7s. Kills the 1-second straight-line sprint without feeling like a punishment.
  static _readGateMs(q) {
    const text = String(q.question || '') + (q.options || []).join('')
    return Math.min(7000, 2000 + Math.round(text.length * 55 / 10))
  }

  get isVisible() { return this._questions.length > 0 }

  show({ questions, onComplete = null, submitUrl = null, leaderboardUrl = null, hasClassroom = false }) {
    injectStyles()
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
    this._display = this._questions.map(q => QuizOverlay._shuffledIndices((q.options || []).length || 4))
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
    if (this._onVisibility) { document.removeEventListener('visibilitychange', this._onVisibility); this._onVisibility = null }
  }

  _showFocusVeil() {
    this._focusDrops++
    if (this.host.querySelector('[data-focus-veil]')) return
    const veil = document.createElement('div')
    veil.dataset.focusVeil = '1'
    veil.style.cssText = `position:absolute; inset:0; z-index:20; display:flex; flex-direction:column;
      align-items:center; justify-content:center; gap:14px; background:rgba(2,6,23,0.94); color:white; cursor:pointer;`
    veil.innerHTML = `
      <div style="font-size:42px;">&#128064;</div>
      <div style="font-size:22px; font-weight:800;">Quiz paused</div>
      <div style="font-size:15px; color:#94a3b8;">Stay with the story — tap to continue.</div>`
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
      this._gateTimer = setTimeout(() => this._render(), Math.min(gateLeft + 30, 500))
    }

    const options = mapping.map(originalIndex => (q.options || [])[originalIndex]).slice(0, 4)

    const optionsHtml = options.map((opt, i) => {
      const isCorrect = mapping[i] === Number(q.correct_index)
      let bg = 'rgba(255,255,255,0.06)', border = 'rgba(255,255,255,0.12)', anim = ''
      if (answered && isCorrect) {
        bg = 'rgba(16,185,129,0.28)'; border = '#10b981'
        if (effects?.kind === 'correct' && i === chosen) anim = 'qz-correct'
        else if (effects?.kind === 'wrong') anim = 'qz-correct'   // reveal-pop on the right one
      } else if (answered && i === chosen && !isCorrect) {
        bg = 'rgba(225,29,72,0.22)'; border = '#e11d48'
        if (effects?.kind === 'wrong') anim = 'qz-wrong'
      }
      return `
        <button data-opt="${i}" ${answered || gated ? 'disabled' : ''} class="${anim}"
                style="position:relative; display:flex; align-items:center; gap:12px; width:100%; text-align:left;
                       padding:12px 16px; border-radius:14px; cursor:${answered ? 'default' : (gated ? 'wait' : 'pointer')};
                       background:${bg}; border:1.5px solid ${border}; color:#f1f5f9; font-size:17px;
                       opacity:${gated ? 0.45 : 1};
                       transition:background 0.15s, border-color 0.15s, transform 0.1s, opacity 0.3s;"
                onpointerdown="if(!this.disabled) this.style.transform='scale(0.985)'"
                onpointerup="this.style.transform=''" onpointerleave="this.style.transform=''">
          <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;
                       border-radius:8px; font-weight:700; font-size:13px; color:white; flex-shrink:0;
                       background:${LETTER_COLORS[i]};">${LETTERS[i]}</span>
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
      const color = wasCorrect ? '#34d399' : '#fbbf24'
      feedback = `
        <div style="margin-top:14px; animation: qz-slide-in 0.25s ease-out;">
          <span style="font-size:16px; font-weight:800; color:${color};">${word}</span>
          ${q.explanation ? `<span style="font-size:14px; color:#94a3b8; line-height:1.5; margin-left:8px;">${this._escape(q.explanation)}</span>` : ''}
        </div>`
    }

    const streakBadge = this._streak >= STREAK_FROM
      ? `<span class="qz-streak" title="${this._streak} in a row" style="margin-right:10px; font-weight:800; color:#fb923c; font-size:14px;">▲ ${this._streak} streak</span>`
      : ''

    const isLast = this._index === total - 1
    const nextLabel = isLast ? 'Finish' : 'Next ›'

    this.host.innerHTML = `
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="position:relative; width:min(680px, calc(100vw - 32px)); background:${BRAND_NAVY};
                    border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:32px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
            <span style="font-size:12px; letter-spacing:0.2em; text-transform:uppercase; color:${AMBER};">Quiz</span>
            <span style="display:flex; align-items:center; font-size:13px; color:#94a3b8;">
              ${streakBadge}
              <span data-score style="display:inline-flex; align-items:center; gap:5px; font-weight:800; color:#fbbf24; font-size:15px; margin-right:14px;">
                <svg viewBox="0 0 24 24" fill="currentColor" style="width:15px;height:15px;"><path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/></svg>
                <span data-score-value>${this._score}</span>
              </span>
              <span data-pager>${this._index + 1} / ${total}</span>
            </span>
          </div>
          ${q.asks_ahead ? `<div style="display:inline-flex; align-items:center; gap:6px; margin-bottom:10px; padding:4px 12px;
                border-radius:999px; background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4);
                color:#fbbf24; font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">
                Sneak peek — this comes later in the story</div>` : ''}
          <div style="font-size:22px; font-weight:600; line-height:1.35; margin-bottom:20px;">${this._escape(q.question)}</div>
          ${gated ? `<div style="display:flex; align-items:center; gap:8px; margin-bottom:10px; color:#94a3b8; font-size:13px;">
              <span class="loading loading-ring loading-xs" style="color:#f59e0b;"></span>
              Read the question&hellip; answers unlock in ${Math.ceil(gateLeft / 1000)}s</div>` : ''}
          <div style="display:flex; flex-direction:column; gap:10px;">${optionsHtml}</div>
          ${feedback}
          <div style="display:flex; align-items:center; justify-content:space-between; margin-top:24px;">
            <button data-prev ${this._index === 0 ? 'disabled' : ''}
                    style="background:none; border:none; color:${this._index === 0 ? '#334155' : '#cbd5e1'};
                           font-size:15px; cursor:${this._index === 0 ? 'default' : 'pointer'}; padding:8px 12px;">‹ Previous</button>
            <div style="display:flex; gap:6px;">
              ${this._questions.map((_, i) => {
                const done = this._answered.has(i)
                const ok = done && this._answered.get(i) === Number(this._questions[i].correct_index)
                const color = i === this._index ? AMBER : (done ? (ok ? '#10b981' : '#64748b') : 'rgba(255,255,255,0.15)')
                return `<span style="width:8px; height:8px; border-radius:99px; background:${color};"></span>`
              }).join('')}
            </div>
            <button data-next
                    style="background:${AMBER}; border:none; color:#0f172a; font-weight:700; font-size:15px;
                           padding:8px 20px; border-radius:12px; cursor:pointer; transition:transform 0.1s;"
                    onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">${nextLabel}</button>
          </div>
        </div>
      </div>`

    this.host.querySelectorAll('[data-opt]').forEach(btn => {
      btn.addEventListener('click', () => this._answer(Number(btn.dataset.opt), btn))
    })
    this.host.querySelector('[data-prev]')?.addEventListener('click', () => {
      if (this._index > 0) { this._index--; this._render() }
    })
    this.host.querySelector('[data-next]')?.addEventListener('click', () => {
      if (this._index < total - 1) { this._index++; this._render() }
      else this._renderScoreScreen()
    })

    if (effects?.kind === 'correct') this._playCorrectEffects(effects)
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
  }

  _playCorrectEffects({ gained, from, at }) {
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

    // Floating "+10" from the clicked answer.
    const float = document.createElement('div')
    float.textContent = `+${gained}`
    float.style.cssText = `position:fixed; left:${at.x}px; top:${at.y}px; z-index:90; pointer-events:none;
      transform:translateX(-50%); font-weight:900; font-size:26px; color:#fbbf24;
      text-shadow:0 2px 10px rgba(0,0,0,0.6); animation: qz-float-up 0.9s ease-out forwards;`
    document.body.appendChild(float)
    setTimeout(() => float.remove(), 950)

    // Particle burst (10 dots, transform-only).
    for (let i = 0; i < 10; i++) {
      const p = document.createElement('div')
      const angle = (Math.PI * 2 * i) / 10 + Math.random() * 0.5
      const dist = 46 + Math.random() * 34
      const colors = ['#fbbf24', '#34d399', '#38bdf8', '#f472b6']
      p.style.cssText = `position:fixed; left:${at.x}px; top:${at.y}px; z-index:89; pointer-events:none;
        width:8px; height:8px; border-radius:99px; background:${colors[i % colors.length]};
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

    // "backwards" fill keeps the 0% frame (hidden) during the stagger delay; once the
    // animation ends the star simply rests at its natural, fully-visible state — no
    // dependence on the keyframe's end opacity.
    const starsHtml = [0, 1, 2].map(i => `
      <svg viewBox="0 0 24 24" style="width:56px; height:56px;
           fill:${i < stars ? '#fbbf24' : 'rgba(255,255,255,0.12)'};
           animation: qz-star-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) ${0.15 + i * 0.22}s backwards;">
        <path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
      </svg>`).join('')

    // Competition block: nickname entry when submission is enabled (student player).
    const savedName = (() => { try { return localStorage.getItem('lp_quiz_nickname') || '' } catch { return '' } })()
    const joinHtml = this._submitUrl ? `
      <div data-join style="margin-bottom:22px; animation: qz-slide-in 0.3s ease-out 0.6s backwards;">
        <div style="font-size:13px; letter-spacing:0.12em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px;">
          Join the leaderboard
        </div>
        ${this._hasClassroom ? `
        <div style="display:flex; gap:8px; justify-content:center; margin-bottom:8px;">
          <input data-class-code type="text" maxlength="8" placeholder="Class code…"
                 style="width:130px; padding:10px 14px; border-radius:12px; border:1.5px solid rgba(255,255,255,0.2);
                        background:rgba(255,255,255,0.06); color:white; font-size:15px; outline:none; text-transform:uppercase;" />
        </div>` : ''}
        <div style="display:flex; gap:8px; justify-content:center;">
          <input data-nickname type="text" maxlength="24" placeholder="Your name…"
                 style="width:200px; padding:10px 14px; border-radius:12px; border:1.5px solid rgba(245,158,11,0.4);
                        background:rgba(255,255,255,0.06); color:white; font-size:15px; outline:none;" />
          <button data-submit
                  style="background:${AMBER}; border:none; color:#0f172a; font-weight:800; font-size:15px;
                         padding:10px 20px; border-radius:12px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            Submit
          </button>
        </div>
        <div data-join-error style="font-size:12px; color:#fda4af; margin-top:6px; min-height:16px;"></div>
      </div>` : ''

    this.host.innerHTML = `
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); background:${BRAND_NAVY};
                    border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:40px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="display:flex; justify-content:center; gap:10px; margin-bottom:18px;">${starsHtml}</div>
          <div style="font-size:15px; letter-spacing:0.15em; text-transform:uppercase; color:#94a3b8; margin-bottom:6px;">
            ${correct} / ${total} correct
          </div>
          <div data-final-score style="font-size:56px; font-weight:900; color:#fbbf24; margin-bottom:22px;">0</div>
          ${joinHtml}
          <button data-done
                  style="background:${this._submitUrl ? 'none' : AMBER}; border:${this._submitUrl ? '1px solid rgba(255,255,255,0.25)' : 'none'};
                         color:${this._submitUrl ? '#cbd5e1' : '#0f172a'}; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            ${this._submitUrl ? 'Skip ›' : 'Continue ›'}
          </button>
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

    this.host.querySelector('[data-done]').addEventListener('click', () => {
      const done = this._onComplete
      this.hide()
      done?.()
    })

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
    const medals = ['#fbbf24', '#cbd5e1', '#d97706']   // gold, silver, bronze
    const rows = top.map((entry, i) => {
      const isOwn = rank !== null && i === rank - 1 && entry.nickname === ownNickname
      const medal = i < 3
        ? `<span style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px;
                        border-radius:99px; background:${medals[i]}; color:#0f172a; font-weight:900; font-size:13px;">${i + 1}</span>`
        : `<span style="width:26px; text-align:center; color:#64748b; font-weight:700; font-size:13px;">${i + 1}</span>`
      return `
        <div style="display:flex; align-items:center; gap:12px; padding:9px 14px; border-radius:12px;
                    background:${isOwn ? 'rgba(245,158,11,0.16)' : (i % 2 ? 'rgba(255,255,255,0.03)' : 'transparent')};
                    border:1px solid ${isOwn ? 'rgba(245,158,11,0.55)' : 'transparent'};
                    animation: qz-slide-in 0.3s ease-out ${0.08 * i}s backwards;">
          ${medal}
          <span style="flex:1; text-align:left; font-weight:${i < 3 || isOwn ? 700 : 500}; font-size:15px;
                       overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            ${this._escape(entry.nickname)}${isOwn ? ' · you' : ''}
          </span>
          <span style="font-weight:800; color:#fbbf24; font-size:15px;">${entry.score}</span>
        </div>`
    }).join('')

    const ownOutsideTop = rank !== null && rank > top.length
      ? `<div style="margin-top:10px; font-size:14px; color:#fbbf24; font-weight:700;">You're #${rank} of ${players} — keep climbing!</div>`
      : ''

    this.host.innerHTML = `
      <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                  background:rgba(2,6,23,0.72); backdrop-filter:blur(6px);">
        <div class="qz-card" style="width:min(520px, calc(100vw - 32px)); max-height:calc(100vh - 60px); overflow-y:auto;
                    background:${BRAND_NAVY}; border:1px solid rgba(245,158,11,0.35); border-radius:24px; padding:32px;
                    box-shadow:0 24px 60px rgba(0,0,0,0.5); color:white; text-align:center;">
          <div style="font-size:13px; letter-spacing:0.2em; text-transform:uppercase; color:${AMBER}; margin-bottom:4px;">Leaderboard</div>
          <div style="font-size:13px; color:#64748b; margin-bottom:18px;">${players} player${players === 1 ? '' : 's'}</div>
          <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">${rows || '<span style="color:#64748b;">No scores yet — you could be first!</span>'}</div>
          ${ownOutsideTop}
          <button data-done
                  style="margin-top:22px; background:${AMBER}; border:none; color:#0f172a; font-weight:800; font-size:17px;
                         padding:12px 40px; border-radius:14px; cursor:pointer; transition:transform 0.1s;"
                  onpointerdown="this.style.transform='scale(0.96)'" onpointerup="this.style.transform=''">
            Continue ›
          </button>
        </div>
      </div>`

    this.host.querySelector('[data-done]').addEventListener('click', () => {
      const done = this._onComplete
      this.hide()
      done?.()
    })
  }

  _escape(text) {
    const div = document.createElement('div')
    div.textContent = String(text ?? '')
    // textContent→innerHTML encodes < > & but NOT " or ' — unsafe for attribute
    // positions (e.g. value="${this._escape(x)}"). Encode those explicitly too.
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;')
  }
}
