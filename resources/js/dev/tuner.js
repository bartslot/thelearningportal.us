/**
 * tuner.js — sliders for the numbers that are currently hard-coded.
 *
 * Every constant in this codebase was once someone guessing, then rebuilding, then squinting. The
 * shore falloff took four rebuilds tonight and the sun's roughness took three. This turns that loop
 * into one drag: whatever is on screen registers the knobs it owns, you slide until it looks right,
 * and the panel tells you the number to write down.
 *
 * IT IS A DEV TOOL AND CHANGES NOTHING PERMANENTLY. Nothing is persisted and nothing is written back
 * to source — the value you land on is a number for a human to put in the code, deliberately, with
 * a comment saying why. A slider that silently became the default would be a constant nobody chose.
 *
 * CONTEXT, not a global list. A group registers when its thing mounts and unregisters when it goes,
 * so the panel shows the map's knobs on the map and a lesson's knobs in a lesson. Anything can join:
 *
 *     const off = window.__tune?.register('Ocean', [
 *       { key: 'roughness', label: 'Roughness', min: 0, max: 1, step: 0.01, value: 0.9,
 *         apply: (v) => layer.setOptions({ roughness: v }) },
 *     ])
 *     // later, when the thing goes away:
 *     off?.()
 */

/** group name → { controls, order } */
const groups = new Map()
let seq = 0
let root = null
let listEl = null

const isOpen = () => !!root && root.style.display !== 'none'

/**
 * Register a group of knobs. Returns an unregister function.
 *
 * @param {string} group     what owns them, e.g. 'Ocean' — re-registering the same name replaces it
 * @param {Array<{key:string,label:string,min:number,max:number,step:number,value:number,apply:Function}>} controls
 */
export const register = (group, controls) => {
  if (!group || !Array.isArray(controls) || !controls.length) return () => {}
  groups.set(group, { order: seq++, controls: controls.map((c) => ({ ...c, current: c.value })) })
  redraw()
  return () => {
    groups.delete(group)
    redraw()
  }
}

/**
 * Repaint if the panel has been built, open or not.
 *
 * This used to be gated on isOpen(), which meant a group registering while the panel was closed
 * never made it into the DOM — and since the panel is opened by hand and the map registers on
 * load, closed-at-registration-time is the normal case, not the edge one.
 */
const redraw = () => { if (listEl) draw() }

/** Everything currently set, as plain data — what the Copy button writes out. */
export const values = () => {
  const out = {}
  for (const [name, { controls }] of groups) {
    out[name] = Object.fromEntries(controls.map((c) => [c.key, c.current]))
  }
  return out
}

const el = (tag, props = {}, children = []) => {
  const node = Object.assign(document.createElement(tag), props)
  for (const child of children) node.append(child)
  return node
}

const build = () => {
  listEl = el('div', { className: 'lp-tune-list' })

  const copy = el('button', { className: 'lp-tune-copy', textContent: 'Copy values', type: 'button' })
  copy.onclick = async () => {
    const text = JSON.stringify(values(), null, 2)
    try {
      await navigator.clipboard.writeText(text)
      copy.textContent = 'Copied'
    } catch {
      // Clipboard is blocked without a secure context or permission; the console always works.
      console.log('[tune]\n' + text)
      copy.textContent = 'Logged to console'
    }
    setTimeout(() => { copy.textContent = 'Copy values' }, 1600)
  }

  const close = el('button', { className: 'lp-tune-x', textContent: '✕', type: 'button' })
  close.onclick = () => toggle(false)

  root = el('div', { className: 'lp-tune' }, [
    el('div', { className: 'lp-tune-head' }, [
      el('span', { className: 'lp-tune-title', textContent: 'Settings' }),
      el('span', { className: 'lp-tune-hint', textContent: 'live · not saved' }),
      close,
    ]),
    listEl,
    el('div', { className: 'lp-tune-foot' }, [copy]),
  ])

  // Explicitly closed. The stylesheet says display:none, but isOpen() reads the INLINE style, which
  // is empty until something sets it — so the first toggle read "open" and closed an unbuilt panel.
  root.style.display = 'none'
  document.body.append(root)
  document.head.append(el('style', { textContent: CSS }))
}

const draw = () => {
  listEl.replaceChildren()

  if (!groups.size) {
    listEl.append(el('p', {
      className: 'lp-tune-empty',
      textContent: 'Nothing on this screen has registered any settings yet.',
    }))
    return
  }

  for (const [name, { controls }] of [...groups].sort((a, b) => a[1].order - b[1].order)) {
    listEl.append(el('div', { className: 'lp-tune-group', textContent: name }))

    for (const control of controls) {
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
        control.current = Number(slider.value)
        readout.textContent = fmt(control.current)
        // Applying is the caller's business — this file knows nothing about maps or shaders.
        try { control.apply?.(control.current) } catch (e) { console.error('[tune] apply failed', name, control.key, e) }
      }
      // Double-click a row's label to put it back where the code has it, so a session of dragging
      // is always one click from the value that is actually committed.
      const label = el('span', { className: 'lp-tune-label', textContent: control.label ?? control.key })
      label.title = `Default ${fmt(control.value)} — double-click to reset`
      label.ondblclick = () => {
        control.current = control.value
        slider.value = String(control.value)
        readout.textContent = fmt(control.value)
        try { control.apply?.(control.value) } catch { /* same as above */ }
      }

      listEl.append(el('label', { className: 'lp-tune-row' }, [label, slider, readout]))
    }
  }
}

const fmt = (v) => (Math.abs(v) >= 100 || Number.isInteger(v) ? String(v) : v.toFixed(3).replace(/0+$/, '').replace(/\.$/, ''))

export const toggle = (next) => {
  if (!root) build()
  const show = next ?? !isOpen()
  root.style.display = show ? 'flex' : 'none'
  if (show) draw()
}

const CSS = `
.lp-tune{position:fixed;right:1rem;bottom:8.5rem;z-index:101;display:none;flex-direction:column;
  width:19rem;max-height:60vh;border:1px solid rgba(192,38,211,.5);border-radius:.75rem;
  background:#0f172a;box-shadow:0 20px 50px rgba(0,0,0,.6);font:12px system-ui,sans-serif;color:#cbd5e1}
.lp-tune-head{display:flex;align-items:center;gap:.5rem;padding:.6rem .75rem;border-bottom:1px solid #1e293b}
.lp-tune-title{font-weight:600;color:#e9d5ff}
.lp-tune-hint{margin-left:auto;font-size:10px;color:#64748b}
.lp-tune-x{background:none;border:0;color:#64748b;cursor:pointer;font-size:12px;padding:0 .25rem}
.lp-tune-x:hover{color:#e2e8f0}
.lp-tune-list{overflow-y:auto;padding:.5rem .75rem}
.lp-tune-group{margin:.6rem 0 .3rem;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#a855f7}
.lp-tune-row{display:grid;grid-template-columns:5.5rem 1fr 2.75rem;align-items:center;gap:.5rem;padding:.15rem 0}
.lp-tune-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.lp-tune-range{width:100%;accent-color:#c026d3}
.lp-tune-value{text-align:right;font-family:ui-monospace,monospace;color:#f0abfc}
.lp-tune-empty{color:#64748b;padding:.75rem 0}
.lp-tune-foot{padding:.6rem .75rem;border-top:1px solid #1e293b}
.lp-tune-copy{width:100%;padding:.4rem;border:1px solid #6b21a8;border-radius:.5rem;background:#3b0764;
  color:#f5d0fe;cursor:pointer;font:inherit}
.lp-tune-copy:hover{background:#581c87}
`

// One path in, like the icon work insisted on: the dev panel's button dispatches this and nothing
// else pokes at the internals.
//
// SINGLETON, on purpose. If this module is ever evaluated twice — two entry graphs, a stale chunk
// alongside a fresh one — each copy gets its own `groups` and its own panel, and then registrations
// land in one instance while the panel you can see belongs to the other. That is invisible: the
// registry reports the group, the screen shows nothing, and there is no error anywhere. First one
// to load owns the window.
if (!window.__tune) {
  window.addEventListener('dev:tune', () => toggle())
  window.__tune = { register, values, toggle }
}
