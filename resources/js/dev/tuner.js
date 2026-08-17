/**
 * tuner.js — live controls for the numbers that are still guesses.
 *
 * Every constant in this codebase was once someone guessing, then rebuilding, then squinting. The
 * shore falloff took four rebuilds in one evening and the sea's roughness took three. This turns
 * that loop into one drag: whatever is on screen registers the knobs it owns, you move them until
 * it looks right, and the panel tells you the numbers.
 *
 * NOTHING IS SAVED BY MOVING A SLIDER. Values become permanent only when you name a preset and
 * save it, and then they are written to resources/js/dev/tuning.json — in the source tree, in
 * `git diff`, revertable. That distinction is the point: a slider that silently became the default
 * would be a constant nobody chose, which is the situation this panel exists to get out of. Naming
 * one is a decision, and it leaves a record of who made it.
 *
 * CONTEXT, not a global list. A group registers when its thing mounts and unregisters when it goes,
 * so the map's knobs appear on a map and a lesson's in a lesson. The tuner knows nothing about maps
 * or shaders — only a value and a callback:
 *
 *     const off = window.__tune?.register('Ocean', [
 *       { key: 'roughness', label: 'Roughness', min: 0, max: 1, step: 0.01, value: 0.9,
 *         apply: (v) => layer.setOptions({ roughness: v }) },
 *       { key: 'tint', type: 'color', value: '#1b3a5c', apply: (v) => layer.setOptions({ tint: v }) },
 *       { key: 'replay', type: 'button', label: 'Play entrance', apply: () => intro() },
 *     ], { tab: 'Map' })
 *     off()   // when the thing goes away
 *
 * Types: `range` (default), `color`, `boolean`, `number`, `text`, `textarea`, `button`.
 */

/** group name → { tab, order, controls } */
const groups = new Map()
let seq = 0
let root = null
let bodyEl = null
let tabsEl = null
let activeTab = null

const DEFAULT_TAB = 'General'
const isOpen = () => !!root && root.style.display !== 'none'
const tabsInUse = () => [...new Set([...groups.values()].map((g) => g.tab))]


// ── Named presets ────────────────────────────────────────────────────────────────────────────
//
// A tuned look is a change to the app, so it is saved where changes live: a JSON file in the source
// tree, written by a local-only endpoint, arriving as a reviewable diff. Not localStorage — a value
// that exists on one machine and dies with a cleared cache is not a saved value.
//
// Applied AT REGISTRATION rather than in one pass at load. Groups register whenever their screen is
// ready — the ocean and the clouds only exist once the map's load handler runs, the ships later
// still — so a single sweep would miss whatever had not arrived yet, and miss it silently.
let presetStore = { active: null, presets: {} }
let presetsLoaded = false

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? ''

const presetFetch = (method, body) => fetch('/dev/tuning', {
  method,
  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
  ...(body ? { body: JSON.stringify(body) } : {}),
}).then((r) => (r.ok ? r.json() : Promise.reject(new Error(`${method} /dev/tuning → ${r.status}`))))

/**
 * The panel's own controls are not settings. Saving them stores WHICH PRESET WAS ACTIVE inside the
 * preset, and replaying that on load flips the active preset out from under the values it has just
 * applied — so a saved look came back as whatever had been selected when it was saved.
 */
const META_GROUP = 'Presets'

/**
 * Push the active preset into a group's controls, running each control's apply.
 *
 * A CONTROL THE PRESET DOES NOT MENTION MEANS ITS DEFAULT — not "whatever the last preset left
 * there". Absent keys used to be skipped, so values leaked across every switch: a WW2 preset with
 * saturation 0 turned the map black and white, and every preset saved BEFORE the colour grade
 * existed had no saturation of its own to say otherwise, so switching away left it black and white
 * for good. The panel showed 0 and the preset that was supposedly loaded had never heard of it.
 *
 * This is the same failure as the missing group in mergedValues, seen from the other end: one
 * writes settings it cannot see, this one fails to clear settings the preset never had.
 */
const applyPresetTo = (group, entry) => {
  if (group === META_GROUP) return
  // Nothing selected at all — leave the panel exactly as the user left it rather than snapping
  // every control to a default they never asked for.
  const preset = presetStore.presets?.[presetStore.active]
  if (!preset) return
  const saved = preset[group] ?? {}
  for (const control of entry.controls) {
    if (control.type === 'button') continue
    const next = control.key in saved ? saved[control.key] : control.value
    if (control.current === next) continue
    control.current = next
    try { control.apply?.(next) } catch (e) { console.warn(`[tune] ${group}.${control.key} threw`, e) }
  }
}

const loadPresets = () => presetFetch('GET')
  .then((data) => {
    presetStore = { active: data.active ?? null, presets: data.presets ?? {} }
    presetsLoaded = true
    // Anything registered before the file arrived still needs its values.
    applyActivePreset()
    redraw()
  })
  .catch(() => { presetsLoaded = true }) // no endpoint (not local) — the panel still works

/**
 * Make the active preset the live one, across every registered group.
 *
 * The same loop was written out at both call sites — selecting a preset, and the file arriving —
 * which is also why the leak below was only ever fixed in the abstract: there was no single place
 * that meant "switch preset". There is now, and it is what the test drives.
 */
export const applyActivePreset = () => {
  for (const [group, entry] of groups) applyPresetTo(group, entry)
}

/**
 * Seed the preset store directly. TEST SEAM — the store is otherwise filled by a fetch on load,
 * and the behaviour worth pinning (what happens to a control the target preset never mentions)
 * only exists across a SWITCH between two presets, which a test cannot stage through the network.
 * Not called by the panel.
 */
export const __setPresetsForTest = ({ active = null, presets = {} } = {}) => {
  presetStore = { active, presets }
}

/**
 * Register a group of controls. Returns an unregister function.
 *
 * @param {string} group                  what owns them, e.g. 'Ocean' — same name replaces
 * @param {Array<object>} controls        see the types above
 * @param {{tab?: string}} [opts]         which tab it belongs under
 */
export const register = (group, controls, opts = {}) => {
  if (!group || !Array.isArray(controls) || !controls.length) return () => {}
  groups.set(group, {
    tab: opts.tab || DEFAULT_TAB,
    order: seq++,
    controls: controls.map((c) => ({ ...c, current: c.value })),
  })
  applyPresetTo(group, groups.get(group))
  redraw()
  return () => {
    groups.delete(group)
    redraw()
  }
}

/**
 * Set one control from code, exactly as dragging it would: runs the same apply and leaves the panel
 * showing the new value. Returns false if there is no such control, so a caller can tell the
 * difference between "set it" and "that control does not exist" — a silent no-op here would be the
 * same failure this panel was built to end.
 *
 * This is how a test drives a slider, and how a preset can be replayed.
 */
export const set = (group, key, value) => {
  const entry = groups.get(group)
  const control = entry && entry.controls.find((c) => c.key === key)
  if (!control) return false
  control.current = value
  try { control.apply?.(value) } catch (e) { console.warn(`[tune] ${group}.${key} threw`, e) }
  redraw()
  return true
}

/**
 * Everything currently set, as plain data — what Copy writes out and what a preset stores.
 *
 * `meta: false` drops the panel's own controls, which are state rather than settings.
 */
export const values = ({ meta = true } = {}) => {
  const out = {}
  for (const [name, { controls }] of groups) {
    if (!meta && name === META_GROUP) continue
    const vals = Object.fromEntries(
      controls.filter((c) => c.type !== 'button').map((c) => [c.key, c.current]),
    )
    if (Object.keys(vals).length) out[name] = vals
  }
  return out
}

const el = (tag, props = {}, children = []) => {
  const node = Object.assign(document.createElement(tag), props)
  for (const child of children) node.append(child)
  return node
}

const fmt = (v) => {
  if (typeof v !== 'number') return String(v)
  return Math.abs(v) >= 100 || Number.isInteger(v) ? String(v) : v.toFixed(3).replace(/0+$/, '').replace(/\.$/, '')
}

/**
 * Any CSS colour this panel can produce, as components. `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`
 * and `rgba()`. Returns `fallback` for anything else, so a half-typed hex does not snap to black.
 */
export const parseColour = (value, fallback = { rgb: [0, 0, 0], a: 1 }) => {
  const s = String(value ?? '').trim()
  const fn = s.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)$/i)
  if (fn) {
    return { rgb: [+fn[1], +fn[2], +fn[3]].map((n) => Math.max(0, Math.min(255, Math.round(n)))), a: fn[4] === undefined ? 1 : Math.max(0, Math.min(1, +fn[4])) }
  }
  const hex = s.match(/^#([0-9a-f]{3,8})$/i)?.[1]
  if (!hex) return fallback
  const wide = hex.length <= 4 ? [...hex].map((c) => c + c).join('') : hex
  if (wide.length !== 6 && wide.length !== 8) return fallback
  const n = parseInt(wide.slice(0, 6), 16)
  return {
    rgb: [n >> 16 & 255, n >> 8 & 255, n & 255],
    a: wide.length === 8 ? parseInt(wide.slice(6, 8), 16) / 255 : 1,
  }
}

const rgbToHex = ([r, g, b]) => `#${[r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')}`

/** Run a control's apply, loudly. A knob that silently does nothing is worse than no knob. */
const applyValue = (group, control, value) => {
  control.current = value
  try {
    control.apply?.(value)
  } catch (e) {
    console.error('[tune] apply failed', group, control.key, e)
  }
}

// ── Control rendering ────────────────────────────────────────────────────────────────────────

const rowFor = (group, control) => {
  const label = el('span', { className: 'lp-tune-label', textContent: control.label ?? control.key })

  if (control.type === 'button') {
    const button = el('button', { className: 'lp-tune-btn', type: 'button', textContent: control.label ?? control.key })
    button.onclick = () => applyValue(group, control, control.current)
    return el('div', { className: 'lp-tune-row lp-tune-row-wide' }, [button])
  }

  if (control.type === 'textarea') {
    const area = el('textarea', { className: 'lp-tune-area', value: String(control.current ?? ''), rows: 5, spellcheck: false })
    area.oninput = () => applyValue(group, control, area.value)
    return el('div', { className: 'lp-tune-stack' }, [label, area])
  }

  // A fixed set of choices — a font stack, a projection, a preset. `options` is either plain
  // strings or [value, label] pairs, so the value written to the map can differ from what is read.
  if (control.type === 'select') {
    const pick = el('select', { className: 'lp-tune-input' })
    for (const opt of control.options ?? []) {
      const [value, text] = Array.isArray(opt) ? opt : [opt, opt]
      pick.appendChild(el('option', { value: String(value), textContent: String(text), selected: value === control.current }))
    }
    pick.onchange = () => applyValue(group, control, pick.value)
    return el('label', { className: 'lp-tune-row' }, [label, pick, el('span')])
  }

  if (control.type === 'boolean') {
    const box = el('input', { className: 'lp-tune-check', type: 'checkbox', checked: !!control.current })
    box.onchange = () => applyValue(group, control, box.checked)
    return el('label', { className: 'lp-tune-row' }, [label, box, el('span')])
  }

  if (control.type === 'text' || control.type === 'number') {
    const input = el('input', {
      className: 'lp-tune-input',
      type: control.type,
      value: String(control.current ?? ''),
      ...(control.type === 'number' ? { min: control.min ?? '', max: control.max ?? '', step: control.step ?? 1 } : {}),
    })
    input.oninput = () => applyValue(group, control, control.type === 'number' ? Number(input.value) : input.value)
    // Same gesture as the sliders: double-click the field to put the committed default back.
    input.title = `Default ${control.value} — double-click to reset`
    input.ondblclick = () => { input.value = String(control.value ?? ''); applyValue(group, control, control.value) }
    return el('label', { className: 'lp-tune-row' }, [label, input, el('span')])
  }

  if (control.type === 'color') {
    /**
     * Swatch, typed hex, and alpha — because a native colour input can do none of those three.
     *
     * `<input type="color">` is RGB only and cannot be typed into, so a value you already have on
     * paper had to be matched by eye in a gradient square, and anything needing transparency had to
     * borrow a separate opacity slider from somewhere else on the panel. Both came up the first
     * evening this panel was used.
     *
     * The value handed to `apply` is a CSS colour string: `#rrggbb` at full alpha, `rgba(r,g,b,a)`
     * below it. Both are valid everywhere this is used — MapLibre paint properties, CSS variables
     * — and consumers that need components parse it with cssToRgb.
     */
    const { rgb, a } = parseColour(control.current)
    let alpha = a
    let hex = rgbToHex(rgb)

    const swatch = el('input', { className: 'lp-tune-color', type: 'color', value: hex })
    const text = el('input', { className: 'lp-tune-hex', type: 'text', value: control.current, spellcheck: false })
    const alphaSlider = el('input', {
      className: 'lp-tune-range lp-tune-alpha', type: 'range', min: '0', max: '1', step: '0.01', value: String(alpha),
    })
    alphaSlider.title = 'Opacity'

    const emit = () => {
      const value = alpha >= 1 ? hex : `rgba(${rgb.join(', ')}, ${Number(alpha.toFixed(3))})`
      if (document.activeElement !== text) text.value = value
      applyValue(group, control, value)
    }
    swatch.oninput = () => {
      hex = swatch.value
      rgb.splice(0, 3, ...parseColour(hex).rgb)
      emit()
    }
    alphaSlider.oninput = () => { alpha = Number(alphaSlider.value); emit() }
    // Typed hex, so a value from a brand doc or another tool can just be pasted in. Anything
    // unparseable is left alone rather than snapping the colour to black mid-keystroke.
    text.oninput = () => {
      const parsed = parseColour(text.value, null)
      if (!parsed) return
      rgb.splice(0, 3, ...parsed.rgb)
      alpha = parsed.a
      hex = rgbToHex(rgb)
      swatch.value = hex
      alphaSlider.value = String(alpha)
      applyValue(group, control, text.value.trim())
    }

    return el('div', { className: 'lp-tune-colourrow' }, [
      el('label', { className: 'lp-tune-row' }, [label, swatch, alphaSlider]),
      text,
    ])
  }

  // range (default)
  const readout = el('span', { className: 'lp-tune-value', textContent: fmt(control.current) })
  const slider = el('input', {
    type: 'range',
    className: 'lp-tune-range',
    min: String(control.min ?? 0),
    max: String(control.max ?? 1),
    step: String(control.step ?? 0.01),
    value: String(control.current),
  })
  slider.oninput = () => {
    readout.textContent = fmt(Number(slider.value))
    applyValue(group, control, Number(slider.value))
  }
  /**
   * Double-click ANY of the three to put it back to what the code actually has.
   *
   * This was on the label only, which is the one part of the row nobody aims at — you are dragging
   * the slider or reading the number, so that is where the gesture has to be. A session of pulling
   * a value around is always one double-click from the committed default.
   */
  const reset = () => {
    slider.value = String(control.value)
    readout.textContent = fmt(control.value)
    applyValue(group, control, control.value)
  }
  const hint = `Default ${fmt(control.value)} — double-click to reset`
  label.title = hint
  slider.title = hint
  readout.title = hint
  label.ondblclick = reset
  slider.ondblclick = reset
  readout.ondblclick = reset
  return el('label', { className: 'lp-tune-row' }, [label, slider, readout])
}

const draw = () => {
  const tabs = tabsInUse()
  if (!tabs.includes(activeTab)) activeTab = tabs[0] ?? null

  // Tabs only exist once there is more than one — a single tab is a label pretending to be a choice.
  tabsEl.replaceChildren()
  if (tabs.length > 1) {
    for (const name of tabs) {
      const tab = el('button', {
        className: `lp-tune-tab${name === activeTab ? ' is-on' : ''}`,
        type: 'button',
        textContent: name,
      })
      tab.onclick = () => { activeTab = name; draw() }
      tabsEl.append(tab)
    }
  }

  bodyEl.replaceChildren()
  const shown = [...groups].filter(([, g]) => g.tab === activeTab).sort((a, b) => a[1].order - b[1].order)

  if (!shown.length) {
    bodyEl.append(el('p', {
      className: 'lp-tune-empty',
      textContent: 'Nothing on this screen has registered any settings yet.',
    }))
    return
  }

  for (const [name, { controls }] of shown) {
    bodyEl.append(el('div', { className: 'lp-tune-group', textContent: name }))
    for (const control of controls) bodyEl.append(rowFor(name, control))
  }
}

/** Repaint if the panel has been built, open or not — things register long before it is opened. */
const redraw = () => { if (bodyEl) draw() }

const build = () => {
  tabsEl = el('div', { className: 'lp-tune-tabs' })
  bodyEl = el('div', { className: 'lp-tune-list' })

  const copy = el('button', { className: 'lp-tune-copy', type: 'button', textContent: 'Copy values' })
  copy.onclick = async () => {
    const text = JSON.stringify(values(), null, 2)
    try {
      await navigator.clipboard.writeText(text)
      copy.textContent = 'Copied'
    } catch {
      console.log('[tune]\n' + text)   // clipboard needs a secure context; the console always works
      copy.textContent = 'Logged to console'
    }
    setTimeout(() => { copy.textContent = 'Copy values' }, 1600)
  }

  const close = el('button', { className: 'lp-tune-x', type: 'button', textContent: '✕' })
  close.onclick = () => toggle(false)

  root = el('div', { className: 'lp-tune' }, [
    el('div', { className: 'lp-tune-head' }, [
      el('span', { className: 'lp-tune-title', textContent: 'Settings' }),
      el('span', { className: 'lp-tune-hint', textContent: 'live · not saved' }),
      close,
    ]),
    tabsEl,
    bodyEl,
    el('div', { className: 'lp-tune-foot' }, [copy]),
  ])

  // Explicitly closed. The stylesheet says display:none, but isOpen() reads the INLINE style, which
  // is empty until something sets it — so the first toggle read "open" and closed an unbuilt panel.
  root.style.display = 'none'
  document.body.append(root)
  document.head.append(el('style', { textContent: CSS }))
}

export const toggle = (next) => {
  if (!root) build()
  const show = next ?? !isOpen()
  root.style.display = show ? 'flex' : 'none'
  if (show) draw()
}

const CSS = `
.lp-tune{position:fixed;right:1rem;bottom:8.5rem;z-index:101;display:none;flex-direction:column;
  width:21rem;max-height:70vh;border:1px solid rgba(192,38,211,.5);border-radius:.75rem;
  background:#0f172a;box-shadow:0 20px 50px rgba(0,0,0,.6);font:12px system-ui,sans-serif;color:#cbd5e1}
.lp-tune-head{display:flex;align-items:center;gap:.5rem;padding:.6rem .75rem;border-bottom:1px solid #1e293b}
.lp-tune-title{font-weight:600;color:#e9d5ff}
.lp-tune-hint{margin-left:auto;font-size:10px;color:#64748b}
.lp-tune-x{background:none;border:0;color:#64748b;cursor:pointer;padding:0 .25rem}
.lp-tune-x:hover{color:#e2e8f0}
.lp-tune-tabs{display:flex;flex-wrap:wrap;gap:.25rem;padding:.4rem .6rem 0}
.lp-tune-tab{border:1px solid #334155;border-radius:.4rem;background:none;color:#94a3b8;
  cursor:pointer;font:inherit;padding:.15rem .5rem}
.lp-tune-tab.is-on{border-color:#a855f7;background:#3b0764;color:#f5d0fe}
.lp-tune-list{overflow-y:auto;padding:.5rem .75rem}
.lp-tune-group{margin:.6rem 0 .3rem;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#a855f7}
.lp-tune-row{display:grid;grid-template-columns:6rem 1fr 3.25rem;align-items:center;gap:.5rem;padding:.15rem 0}
.lp-tune-row-wide{grid-template-columns:1fr}
.lp-tune-stack{display:flex;flex-direction:column;gap:.25rem;padding:.25rem 0}
.lp-tune-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;
  -webkit-user-select:none;user-select:none}
.lp-tune-range{width:100%;accent-color:#c026d3}
.lp-tune-color{width:100%;height:1.4rem;padding:0;border:1px solid #334155;border-radius:.25rem;background:none}
.lp-tune-check{width:1rem;height:1rem;accent-color:#c026d3;justify-self:start}
.lp-tune-colourrow{display:flex;flex-direction:column;gap:.15rem;padding:.15rem 0}
.lp-tune-colourrow .lp-tune-row{padding:0}
.lp-tune-alpha{accent-color:#94a3b8}
.lp-tune-hex{margin-left:6rem;border:1px solid #334155;border-radius:.3rem;background:#020617;
  color:#f0abfc;font:11px ui-monospace,monospace;padding:.1rem .35rem}
.lp-tune-input{width:100%;border:1px solid #334155;border-radius:.3rem;background:#020617;
  color:#e2e8f0;font:inherit;padding:.15rem .35rem}
.lp-tune-area{width:100%;border:1px solid #334155;border-radius:.3rem;background:#020617;
  color:#e2e8f0;font:11px ui-monospace,monospace;padding:.35rem;resize:vertical}
.lp-tune-btn{width:100%;border:1px solid #6b21a8;border-radius:.4rem;background:#3b0764;
  color:#f5d0fe;cursor:pointer;font:inherit;padding:.3rem}
.lp-tune-btn:hover{background:#581c87}
.lp-tune-value{text-align:right;font-family:ui-monospace,monospace;color:#f0abfc;font-size:11px}
.lp-tune-empty{color:#64748b;padding:.75rem 0}
.lp-tune-foot{padding:.6rem .75rem;border-top:1px solid #1e293b}
.lp-tune-copy{width:100%;padding:.4rem;border:1px solid #6b21a8;border-radius:.5rem;background:#3b0764;
  color:#f5d0fe;cursor:pointer;font:inherit}
.lp-tune-copy:hover{background:#581c87}
`

// SINGLETON, on purpose. If this module is ever evaluated twice — two entry graphs, a stale chunk
// beside a fresh one — each copy gets its own registry and its own panel, and registrations land in
// one while the panel you can see belongs to the other. That failure is silent: the registry reports
// the group, the screen shows nothing, and nothing errors. First to load owns the window.
if (!window.__tune) {
  window.addEventListener('dev:tune', () => toggle())
  window.__tune = { register, values, toggle, set, presets: () => ({ ...presetStore }) }

  /**
   * Presets — save the whole panel under a name, and flip between them.
   *
   * A whole-panel snapshot rather than per-tab, because a look is not confined to one tab: the sea,
   * the cloud deck, the twilight and the territory colours are one judgement, and half-applying a
   * look is how you end up comparing two things that were never the same thing.
   */
  let presetName = ''
  /**
   * EVERYTHING THE PRESET ALREADY HAD, PLUS WHAT IS ON SCREEN NOW.
   *
   * values() can only report groups that are REGISTERED AT THIS MOMENT, and most of them are not
   * permanent: the ocean, clouds, sky, glare and colour grade are registered by the globe layers,
   * which exist only while the Earth style is mounted. Writing values() straight into the file —
   * which is what saving used to do — therefore DELETED every one of those settings the moment you
   * saved from any other style. The preset came back looking like a different map and nothing said
   * why, because from the panel's point of view it had faithfully saved everything it could see.
   *
   * So a write is a merge, per group, over whatever is already stored.
   */
  const mergedValues = (name) => {
    const stored = presetStore.presets?.[name] ?? {}
    const merged = { ...stored }
    for (const [group, vals] of Object.entries(values({ meta: false }))) {
      merged[group] = { ...(merged[group] ?? {}), ...vals }
    }
    return merged
  }

  const writePreset = (name, { announce }) => presetFetch('POST', { name, values: mergedValues(name) })
    .then((res) => {
      presetStore.presets[name] = mergedValues(name)
      presetStore.active = name
      console.info(`[tune] ${announce} "${name}" → resources/js/dev/tuning.json (${res.presets.length} presets)`)
      refreshPresetControls()
    })
    .catch((e) => console.warn('[tune] could not save —', e.message))

  const refreshPresetControls = () => {
    const names = Object.keys(presetStore.presets ?? {})
    register('Presets', [
      { key: 'active', label: 'Preset', type: 'select', options: names.length ? names : ['(none saved)'],
        value: presetStore.active ?? (names[0] ?? '(none saved)'),
        apply: (name) => {
          if (!presetStore.presets?.[name]) return
          presetStore.active = name
          applyActivePreset()
          redraw()
        } },
      { key: 'name', label: 'Name', type: 'text', value: '',
        apply: (v) => { presetName = String(v).trim() } },
      { key: 'save', label: 'Save as new preset', type: 'button',
        apply: () => {
          const name = presetName || presetStore.active
          if (!name) { console.warn('[tune] give the preset a name first'); return }
          writePreset(name, { announce: 'saved' })
        } },
      /**
       * Update the preset you are LOOKING AT, whatever is typed in Name.
       *
       * Saving already overwrote the active preset when Name happened to be empty, which is not
       * something anyone would guess and not something you could rely on — type a name once and
       * every later save silently forked a new preset instead of updating the one on screen.
       */
      { key: 'update', label: `Update "${presetStore.active ?? '—'}"`, type: 'button',
        apply: () => {
          const name = presetStore.active
          if (!name || !presetStore.presets?.[name]) { console.warn('[tune] no preset selected to update'); return }
          writePreset(name, { announce: 'updated' })
        } },
      /**
       * THE ONE THE MAP OPENS WITH.
       *
       * `active` in tuning.json is what loadPresets() replays into every group on page load, so it
       * already meant "the standard theme" — but it only ever moved when you SAVED. Picking a
       * preset from the dropdown to look at it left the startup one untouched, so the map kept
       * opening with something you had moved on from and the only way to change that was to
       * re-save. This writes the selection itself, values untouched.
       */
      { key: 'startup', label: 'Load this on startup', type: 'button',
        apply: () => {
          const name = presetStore.active
          if (!name || !presetStore.presets?.[name]) { console.warn('[tune] pick a preset first'); return }
          presetFetch('POST', { name, values: presetStore.presets[name] })
            .then(() => console.info(`[tune] "${name}" is now the theme the map opens with`))
            .catch((e) => console.warn('[tune] could not set the startup theme —', e.message))
        } },
      { key: 'delete', label: 'Delete preset', type: 'button',
        apply: () => {
          const name = presetStore.active
          if (!name || !presetStore.presets?.[name]) { console.warn('[tune] nothing to delete'); return }
          presetFetch('DELETE', { name })
            .then(() => {
              delete presetStore.presets[name]
              presetStore.active = Object.keys(presetStore.presets)[0] ?? null
              refreshPresetControls()
            })
            .catch((e) => console.warn('[tune] could not delete —', e.message))
        } },
    ], { tab: 'UI' })
  }

  loadPresets().then(refreshPresetControls)

  /**
   * A scratch stylesheet, always available.
   *
   * Registered here rather than by a screen because it belongs to no screen — it is for trying a
   * change to the UI chrome without a rebuild.
   *
   * WORTH KNOWING: the map is a WebGL canvas, so CSS cannot reach anything drawn ON it — borders,
   * fills, labels and the sea are pixels, not elements. Those live under Map and Earth. This styles
   * the HTML around the map: panels, the time deck, buttons, the header.
   */
  const sheet = document.createElement('style')
  sheet.dataset.tuneScratch = ''
  document.head.append(sheet)
  /**
   * Film grain, on the two variables .lp-grain reads.
   *
   * Global rather than per-screen because the overlay is on the player, the wizard canvas, the
   * about page and the argument map, and it should look the same in all of them.
   *
   * Size is the one that matters: the noise is an SVG with a viewBox and no intrinsic dimensions,
   * so anything other than a 1:1 tile stretches it into mottling rather than grain. 256 is one
   * texel per CSS pixel; drag it up to see exactly what was wrong before.
   */
  /**
   * Complain if this page has no grain to tune.
   *
   * These are global controls on two CSS variables, and a page without an .lp-grain element reads
   * neither — so the sliders moved and nothing happened, silently, which is the exact failure this
   * whole panel exists to stop. It happened on the Time-Map, where the overlay had simply never
   * been added.
   */
  const grainOnPage = () => {
    if (document.querySelector('.lp-grain, .lp-grain-poster, .lp-grain-heavy')) return true
    console.warn('[tune] nothing on this page uses .lp-grain — these sliders have nothing to act on')
    return false
  }
  register('Film grain', [
    { key: 'opacity', label: 'Strength', min: 0, max: 0.3, step: 0.005, value: 0.04,
      apply: (v) => { grainOnPage(); document.documentElement.style.setProperty('--lp-grain-opacity', String(v)) } },
    { key: 'size', label: 'Tile size (px)', min: 64, max: 1024, step: 16, value: 256,
      apply: (v) => { grainOnPage(); document.documentElement.style.setProperty('--lp-grain-size', `${v}px`) } },
  ], { tab: 'UI' })

  register('Scratch CSS', [
    {
      key: 'css',
      type: 'textarea',
      label: 'Applies to HTML only — the map is a canvas',
      value: '',
      apply: (v) => { sheet.textContent = v },
    },
  ], { tab: 'UI' })
}
