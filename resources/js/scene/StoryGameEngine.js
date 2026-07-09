/**
 * StoryGameEngine — branch mechanics + class meters for game_type='story_game'.
 *
 * Owns ALL game state and DOM (meter HUD, choice overlay, consequence caption,
 * game-over interstitial, run summary). lesson-player.js only forwards three
 * hooks: beforePlay / handleSceneEnd / showSummaryInto, plus two callbacks to
 * drive scene playback (onAdvanceTo / onOverrideContinue).
 *
 * Flow per branch group (question? + option_a + option_b):
 *   question audio ends  → choice overlay (two buttons)         [handleSceneEnd]
 *   no question scene    → overlay when the first option would
 *                          start playing ('hold' from beforePlay)
 *   pick                 → onAdvanceTo(chosen option index)
 *   chosen option ends   → apply branch_effects.deltas (clamp 0-100, animated
 *                          bars + floating ±N chips) → consequence_line caption
 *                          → game over (meter ≤ failThreshold) or reconverge   [handleSceneEnd]
 *   sibling option       → skipped via beforePlay (returns first index after group)
 *
 * Security: every meter label, choice label, consequence line and historical
 * note is LLM/teacher-supplied = untrusted. All of them are rendered with
 * textContent (via _el) — never interpolated into innerHTML.
 *
 * Persistence is v2 — the run summary is display-only; nothing here talks to
 * the network. (Progress POST with answers.game comes in a later segment.)
 */

const CONSEQUENCE_MS = 4000        // game-master caption auto-dismiss
const CHIP_MS = 1400               // floating ±N chip lifetime
const OPTION_ROLES = ['option_a', 'option_b']
const FALLBACK_LABELS = { option_a: 'Optie A', option_b: 'Optie B' }

let stylesInjected = false
function injectStyles () {
  if (stylesInjected) return
  stylesInjected = true
  const style = document.createElement('style')
  style.textContent = `
    @keyframes sg-slide-in { 0% { transform: translateY(14px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
    @keyframes sg-fade-in { 0% { opacity: 0; } 100% { opacity: 1; } }
    @keyframes sg-chip-up { 0% { transform: translateY(0) scale(0.8); opacity: 0; } 20% { opacity: 1; transform: translateY(-6px) scale(1.1); } 100% { transform: translateY(-34px) scale(1); opacity: 0; } }
    .sg-card { animation: sg-slide-in 0.28s cubic-bezier(0.16, 1, 0.3, 1); }
    .sg-fade { animation: sg-fade-in 0.3s ease-out; }
  `
  document.head.appendChild(style)
}

/**
 * DOM helper: element with class + optional TEXT content (never markup).
 * All untrusted strings flow through the `text` argument → textContent.
 */
function el (tag, className = '', text = null) {
  const node = document.createElement(tag)
  if (className) node.className = className
  if (text != null) node.textContent = String(text)
  return node
}

export class StoryGameEngine {
  /**
   * @param {HTMLElement} host  overlay host (absolute inset-0, pointer-events managed here)
   * @param {object} opts
   *   meters: [{key,label,icon,start}]   failThreshold: number (default 0)
   *   scenes: the player's scene queue   onAdvanceTo(i)   onOverrideContinue()
   */
  constructor (host, { meters, failThreshold = 0, scenes, onAdvanceTo, onOverrideContinue }) {
    injectStyles()
    this.host = host
    this._meterDefs = (Array.isArray(meters) ? meters : []).filter(m => m && m.key)
    this._failThreshold = Number.isFinite(Number(failThreshold)) ? Number(failThreshold) : 0
    this._scenes = Array.isArray(scenes) ? scenes : []
    this._onAdvanceTo = onAdvanceTo
    this._onOverrideContinue = onOverrideContinue

    this._groups = StoryGameEngine.resolveGroups(this._scenes)
    this._meters = Object.fromEntries(this._meterDefs.map(m => [m.key, StoryGameEngine._clamp(Number(m.start) || 0)]))
    this._choices = {}       // groupId -> { role, label, optionIndex }
    this._snapshots = {}     // groupId -> meters snapshot when the choice was shown
    this._restarts = 0
    this._survived = true    // false after a game over; a restart is a fresh attempt → true again
    this._picked = false     // double-click guard for the active choice overlay
    this._captionTimer = null

    this._buildHud()
    // Debug / E2E handle (single active lesson player per page).
    window.__storyGame = this
  }

  // ── Pure logic (static → vitest-testable without an engine/DOM) ──────────

  static _clamp (v) { return Math.min(100, Math.max(0, v)) }

  /**
   * Group the scene queue's branch scenes: id -> { id, question: index|null,
   * options: [{index, role, label, effects}], afterIndex }.
   * afterIndex = first queue index after the group's last member (reconvergence).
   * Groups with fewer than 2 options are dropped — nothing to choose.
   */
  static resolveGroups (scenes) {
    const groups = new Map()
    ;(scenes || []).forEach((s, i) => {
      const id = s?.branch_group
      if (id == null) return
      const entry = groups.get(id) || { id, question: null, options: [], _last: -1 }
      if (s.branch_role === 'question') {
        entry.question = i
      } else if (OPTION_ROLES.includes(s.branch_role)) {
        entry.options.push({
          index: i,
          role: s.branch_role,
          label: s.branch_choice_label || FALLBACK_LABELS[s.branch_role],
          effects: s.config?.branch_effects || null,
        })
      } else {
        return // unknown role — not part of the game
      }
      entry._last = Math.max(entry._last, i)
      groups.set(id, entry)
    })

    const resolved = new Map()
    for (const entry of groups.values()) {
      if (entry.options.length < 2) continue // incomplete group → plays as plain scenes
      entry.options.sort((a, b) => (a.role < b.role ? -1 : 1)) // option_a first
      entry.afterIndex = entry._last + 1
      delete entry._last
      resolved.set(entry.id, entry)
    }
    return resolved
  }

  /**
   * Immutably apply deltas to a meters map: unknown keys and non-numeric
   * values are ignored; results clamp to 0-100.
   */
  static applyDeltas (meters, deltas) {
    const next = { ...meters }
    for (const [key, value] of Object.entries(deltas || {})) {
      if (!(key in next)) continue
      const n = Number(value)
      if (!Number.isFinite(n)) continue
      next[key] = StoryGameEngine._clamp(next[key] + n)
    }
    return next
  }

  /** Any meter at or below the fail threshold = game over. */
  static isGameOver (meters, failThreshold = 0) {
    return Object.values(meters || {}).some(v => v <= failThreshold)
  }

  /**
   * Pure skip computation for beforePlay:
   *   'hold'  → engine must show the choice overlay first (unchosen group hit at an option)
   *   number  → redirect (skip a non-chosen sibling to the reconvergence index)
   *   null    → play the scene normally
   */
  static computeBeforePlay (groups, choices, index, scene) {
    const group = scene?.branch_group != null ? groups.get(scene.branch_group) : null
    if (!group || !OPTION_ROLES.includes(scene.branch_role)) return null
    const chosen = choices[group.id]
    if (!chosen) return 'hold'                              // no question scene (or restart) → choose now
    if (chosen.optionIndex === index) return null           // the chosen option plays
    return group.afterIndex                                 // sibling → skip past the group
  }

  // ── Player hooks ──────────────────────────────────────────────────────────

  /**
   * Called at the top of _playScene. Returns:
   *   'hold' — engine showed the choice overlay; playback resumes via onAdvanceTo
   *   number — replacement index (skip a non-chosen option)
   *   null   — play normally
   */
  beforePlay (index, scene) {
    const action = StoryGameEngine.computeBeforePlay(this._groups, this._choices, index, scene)
    if (action === 'hold') this._showChoiceOverlay(this._groups.get(scene.branch_group))
    return action
  }

  /**
   * Called when a scene's audio ends, BEFORE the player's own advancing.
   * Returns true when the engine takes over (choice overlay / consequence flow).
   */
  handleSceneEnd (index, scene) {
    const group = scene?.branch_group != null ? this._groups.get(scene.branch_group) : null
    if (!group) return false

    if (scene.branch_role === 'question') {
      if (this._choices[group.id]) return false // already chosen (defensive) → normal advance
      this._showChoiceOverlay(group)
      return true
    }

    if (OPTION_ROLES.includes(scene.branch_role)) {
      // Only the CHOSEN option's end drives consequences. A sibling ending here
      // means the beforePlay skip was bypassed somehow — let the player advance.
      if (this._choices[group.id]?.optionIndex !== index) return false
      this._resolveOptionEnd(group, index, scene)
      return true
    }

    return false
  }

  /** { choices: {group: role}, meters: {key: value}, survived, restarts } */
  get state () {
    return {
      choices: Object.fromEntries(Object.entries(this._choices).map(([g, c]) => [g, c.role])),
      meters: { ...this._meters },
      survived: this._survived,
      restarts: this._restarts,
    }
  }

  // ── Meter HUD ─────────────────────────────────────────────────────────────

  _buildHud () {
    if (!this._meterDefs.length) return
    const hud = el('div', 'fixed top-3 left-1/2 -translate-x-1/2 z-50 flex items-center gap-4 px-4 py-2 ' +
      'rounded-2xl bg-slate-900/80 backdrop-blur border border-amber-500/25 shadow-lg pointer-events-none')
    hud.dataset.sgHud = '1'

    this._hudFills = {}
    this._hudSegments = {}
    for (const m of this._meterDefs) {
      const seg = el('div', 'relative flex flex-col gap-0.5 min-w-[96px]')
      const top = el('div', 'flex items-center gap-1.5')
      // Icon: tabler class name from config — validated to a safe token, set as a
      // class (never markup). Missing/invalid icons just render the label alone.
      const icon = String(m.icon || '')
      if (/^[a-z0-9-]+$/i.test(icon)) top.appendChild(el('i', `ti ${icon} text-amber-400 text-xs`))
      top.appendChild(el('span', 'text-[11px] font-semibold text-slate-200 truncate max-w-[110px]', m.label || m.key))
      seg.appendChild(top)

      const track = el('div', 'h-1.5 w-full rounded-full bg-white/10 overflow-hidden')
      const fill = el('div', 'h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-300')
      fill.style.width = `${this._meters[m.key]}%`
      fill.style.transition = 'width 0.6s cubic-bezier(0.22, 1, 0.36, 1)'
      track.appendChild(fill)
      seg.appendChild(track)

      hud.appendChild(seg)
      this._hudFills[m.key] = fill
      this._hudSegments[m.key] = seg
    }

    document.body.appendChild(hud)
    this._hud = hud
  }

  _removeHud () {
    this._hud?.remove()
    this._hud = null
  }

  /** Animate the bars to the current meter values + float ±N chips for changed keys. */
  _animateMeters (deltas) {
    for (const m of this._meterDefs) {
      const fill = this._hudFills?.[m.key]
      if (fill) fill.style.width = `${this._meters[m.key]}%`

      const d = Number(deltas?.[m.key])
      if (!Number.isFinite(d) || d === 0) continue
      const seg = this._hudSegments?.[m.key]
      if (!seg) continue
      const chip = el('span',
        `absolute -bottom-5 left-1/2 -translate-x-1/2 text-xs font-black pointer-events-none ${d > 0 ? 'text-emerald-400' : 'text-rose-400'}`,
        `${d > 0 ? '+' : '−'}${Math.abs(d)}`)
      chip.style.animation = `sg-chip-up ${CHIP_MS}ms ease-out forwards`
      seg.appendChild(chip)
      setTimeout(() => chip.remove(), CHIP_MS + 100)
    }
  }

  // ── Choice overlay ────────────────────────────────────────────────────────

  _showChoiceOverlay (group) {
    if (!group) return
    // Snapshot the meters at choice time — a game over on this branch restarts here.
    this._snapshots[group.id] = { ...this._meters }
    this._picked = false

    const wrap = el('div', 'sg-fade absolute inset-0 z-40 flex items-end sm:items-center justify-center p-6 bg-slate-950/55')
    wrap.dataset.sgChoice = String(group.id)

    const card = el('div', 'sg-card w-full max-w-2xl')
    card.appendChild(el('p', 'text-center text-xs font-bold uppercase tracking-[0.2em] text-amber-400 mb-4', 'Wat doe je?'))

    const row = el('div', 'flex flex-col sm:flex-row gap-4')
    for (const option of group.options) {
      const btn = el('button',
        'flex-1 px-6 py-5 rounded-2xl bg-slate-900/90 backdrop-blur border-2 border-amber-500/40 ' +
        'text-slate-100 text-lg font-semibold leading-snug shadow-xl transition-transform ' +
        'hover:border-amber-400 hover:scale-[1.02] active:scale-[0.98] cursor-pointer text-left',
        option.label) // untrusted label → textContent
      btn.dataset.sgOption = option.role
      btn.addEventListener('click', () => this._pick(group, option, wrap))
      row.appendChild(btn)
    }
    card.appendChild(row)
    wrap.appendChild(card)

    this.host.style.pointerEvents = 'auto'
    this.host.appendChild(wrap)
  }

  _pick (group, option, wrap) {
    if (this._picked) return // rapid double-click guard
    this._picked = true
    this._choices[group.id] = { role: option.role, label: option.label, optionIndex: option.index }
    wrap.remove()
    this.host.style.pointerEvents = 'none'
    this._onAdvanceTo?.(option.index)
  }

  // ── Consequence flow (after the chosen option's audio) ───────────────────

  _resolveOptionEnd (group, index, scene) {
    // branch_effects may be absent (teacher never filled them) → no deltas, no caption.
    const effects = scene.config?.branch_effects || {}
    const deltas = effects.deltas || {}

    this._meters = StoryGameEngine.applyDeltas(this._meters, deltas)
    this._animateMeters(deltas)

    const next = () => {
      if (StoryGameEngine.isGameOver(this._meters, this._failThreshold)) {
        this._survived = false
        this._showGameOver(group, effects.historical_note)
      } else {
        this._onAdvanceTo?.(group.afterIndex)
      }
    }

    if (effects.consequence_line) this._showConsequence(effects.consequence_line, next)
    else next()
  }

  /** Game-master caption: parchment card, auto-dismiss or tap. */
  _showConsequence (line, onDone) {
    const wrap = el('div', 'sg-fade absolute inset-x-0 bottom-10 z-40 flex justify-center px-6 cursor-pointer')
    wrap.dataset.sgConsequence = '1'

    const card = el('div', 'sg-card max-w-xl w-full rounded-xl border-2 border-amber-700/60 shadow-2xl px-6 py-4')
    card.style.background = 'linear-gradient(160deg, #fdf6e3 0%, #f5e9c9 100%)' // parchment
    card.appendChild(el('p', 'text-[11px] font-bold uppercase tracking-[0.18em] text-amber-800 mb-1', 'Spelleider'))
    card.appendChild(el('p', 'font-serif italic text-slate-900 text-base leading-relaxed', line)) // untrusted → textContent
    wrap.appendChild(card)

    let done = false
    const finish = () => {
      if (done) return
      done = true
      clearTimeout(this._captionTimer)
      wrap.remove()
      this.host.style.pointerEvents = 'none'
      onDone()
    }
    wrap.addEventListener('click', finish)
    this._captionTimer = setTimeout(finish, CONSEQUENCE_MS)

    this.host.style.pointerEvents = 'auto'
    this.host.appendChild(wrap)
  }

  // ── Game over ─────────────────────────────────────────────────────────────

  _showGameOver (group, historicalNote) {
    const wrap = el('div', 'sg-fade absolute inset-0 z-50 flex items-center justify-center p-6 bg-slate-950/90')
    wrap.dataset.sgGameover = '1'

    const card = el('div', 'sg-card max-w-xl w-full rounded-2xl bg-slate-900 border border-rose-500/40 shadow-2xl p-8 text-center')
    card.appendChild(el('h2', 'font-history text-2xl sm:text-3xl font-bold text-rose-300 mb-4', 'Zo liep de geschiedenis bijna anders…'))
    if (historicalNote) {
      const note = el('div', 'rounded-xl border border-amber-500/30 bg-amber-500/10 px-5 py-4 mb-6')
      note.appendChild(el('p', 'text-[11px] font-bold uppercase tracking-[0.18em] text-amber-400 mb-1', 'Wat er echt gebeurde'))
      note.appendChild(el('p', 'text-slate-200 text-sm leading-relaxed', historicalNote)) // untrusted → textContent
      card.appendChild(note)
    }

    const restartBtn = el('button',
      'w-full px-6 py-3.5 rounded-xl bg-amber-500 text-slate-950 font-bold text-base cursor-pointer ' +
      'transition-transform hover:scale-[1.02] active:scale-[0.98]',
      'Opnieuw vanaf dit keuzemoment')
    restartBtn.dataset.sgRestart = '1'
    restartBtn.addEventListener('click', () => this._restart(group, wrap))
    card.appendChild(restartBtn)

    const override = el('button', 'mt-4 text-xs text-slate-500 underline underline-offset-2 cursor-pointer hover:text-slate-300',
      'Toch doorgaan (docent)')
    override.dataset.sgOverride = '1'
    override.addEventListener('click', () => {
      wrap.remove()
      this.host.style.pointerEvents = 'none'
      this._onOverrideContinue?.() // teacher call: survived stays false
    })
    card.appendChild(override)

    wrap.appendChild(card)
    this.host.style.pointerEvents = 'auto'
    this.host.appendChild(wrap)
  }

  /** Restore the choice-time snapshot and replay from the group's choice moment. */
  _restart (group, wrap) {
    this._meters = { ...(this._snapshots[group.id] || this._meters) }
    delete this._choices[group.id] // so the overlay shows again
    this._restarts++
    this._survived = true // fresh attempt from the snapshot
    this._animateMeters({})
    wrap?.remove()
    this.host.style.pointerEvents = 'none'
    // Replay the question (its handleSceneEnd re-opens the overlay); with no
    // question scene, the first option's beforePlay 'hold' re-opens it instead.
    const restartIndex = group.question ?? group.options[0].index
    this._onAdvanceTo?.(restartIndex)
  }

  // ── Run summary (finale screen) ───────────────────────────────────────────

  /**
   * Render the run summary into `container` (display-only; persistence of
   * answers.game to the progress endpoint is v2). Also removes the meter HUD.
   */
  showSummaryInto (container) {
    if (!container) return
    this._removeHud()

    container.innerHTML = '' // clearing our own host — no untrusted content involved
    container.style.pointerEvents = 'auto'

    const wrap = el('div', 'sg-fade absolute inset-0 z-40 flex items-center justify-center p-6 bg-slate-950/85 overflow-y-auto')
    const card = el('div', 'sg-card max-w-xl w-full rounded-2xl bg-slate-900 border border-amber-500/30 shadow-2xl p-8')
    card.dataset.sgSummary = '1'

    card.appendChild(el('h2', 'font-history text-2xl font-bold text-amber-300 mb-1 text-center', 'Jullie verhaal'))
    const outcome = this._survived ? 'Overleefd!' : 'Niet overleefd'
    const sub = this._restarts > 0
      ? `${outcome} · ${this._restarts}× opnieuw geprobeerd`
      : outcome
    card.appendChild(el('p', `text-center text-sm font-semibold mb-6 ${this._survived ? 'text-emerald-400' : 'text-rose-400'}`, sub))

    // Choices per group, in group order.
    const groupIds = [...this._groups.keys()].sort((a, b) => Number(a) - Number(b))
    if (groupIds.length) {
      const list = el('div', 'flex flex-col gap-2 mb-6')
      groupIds.forEach((id, i) => {
        const choice = this._choices[id]
        const row = el('div', 'flex items-center gap-3 rounded-xl bg-white/5 px-4 py-2.5')
        row.appendChild(el('span', 'flex-shrink-0 w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 text-xs font-bold flex items-center justify-center', i + 1))
        row.appendChild(el('span', 'text-sm text-slate-200 leading-snug', choice ? choice.label : '—')) // untrusted label → textContent
        list.appendChild(row)
      })
      card.appendChild(list)
    }

    // Final meters.
    const metersWrap = el('div', 'flex flex-col gap-2.5')
    for (const m of this._meterDefs) {
      const row = el('div', '')
      const top = el('div', 'flex items-center justify-between mb-1')
      top.appendChild(el('span', 'text-xs font-semibold text-slate-300', m.label || m.key)) // untrusted → textContent
      top.appendChild(el('span', 'text-xs font-bold text-amber-400', this._meters[m.key]))
      row.appendChild(top)
      const track = el('div', 'h-2 w-full rounded-full bg-white/10 overflow-hidden')
      const fill = el('div', 'h-full rounded-full bg-gradient-to-r from-amber-500 to-amber-300')
      fill.style.width = `${this._meters[m.key]}%`
      track.appendChild(fill)
      row.appendChild(track)
      metersWrap.appendChild(row)
    }
    card.appendChild(metersWrap)

    wrap.appendChild(card)
    container.appendChild(wrap)
  }
}
