/**
 * One tooltip for the whole app.
 *
 * Icon-only controls need a name, and the native `title` attribute is the wrong tool: it waits a
 * second before appearing, it cannot show a keyboard shortcut as a key cap, and it looks like the
 * operating system rather than like us. Any element can opt in instead:
 *
 *     <button data-tooltip="Subtitles" data-tooltip-key="C">…</button>
 *
 * Set `data-tooltip-placement="bottom"` to force it under the element (the default is above, and it
 * flips on its own when there is no room). Never set `title` as well — that is what produces two
 * tooltips at once.
 *
 * One shared element and a handful of delegated listeners, so a page with fifty icons costs the
 * same as a page with one, and markup rendered later (Livewire, Alpine, a modal) works with no
 * re-binding.
 */

const GAP_PX = 10          // space between the control and the tooltip
const EDGE_PAD_PX = 8      // keep the tooltip this far off the viewport edges

let tip = null
let label = null
let key = null
let current = null
let watcher = null   // watches ONLY the hovered control's parent — see watchRemoval()

function build () {
  if (tip) return

  tip = document.createElement('div')
  tip.className = 'lp-tip'
  tip.setAttribute('role', 'tooltip')
  // The control carries its own accessible name (aria-label / text), so announcing this too
  // would just say everything twice.
  tip.setAttribute('aria-hidden', 'true')

  label = document.createElement('span')
  key = document.createElement('kbd')
  key.className = 'lp-tip__key'

  tip.append(label, key)
  document.body.appendChild(tip)
}

function place (el) {
  const r = el.getBoundingClientRect()
  const t = tip.getBoundingClientRect()

  const wantsBottom = el.dataset.tooltipPlacement === 'bottom'
  const fitsAbove = r.top - t.height - GAP_PX >= EDGE_PAD_PX
  const below = wantsBottom || !fitsAbove

  const top = below ? r.bottom + GAP_PX : r.top - t.height - GAP_PX
  const rawLeft = r.left + (r.width - t.width) / 2
  const maxLeft = window.innerWidth - t.width - EDGE_PAD_PX
  const left = Math.min(Math.max(EDGE_PAD_PX, rawLeft), Math.max(EDGE_PAD_PX, maxLeft))

  tip.style.top = `${Math.round(top)}px`
  tip.style.left = `${Math.round(left)}px`
}

/**
 * A control can disappear while its tooltip is up (a scene change, a Livewire morph) and nothing
 * else would ever hide it — it would hang over the page pointing at nothing. Watching the one
 * parent it lives in is enough; watching the whole document would fire on every map frame.
 */
function watchRemoval (el) {
  unwatchRemoval()
  const parent = el.parentElement
  if (!parent) return
  watcher = new MutationObserver(() => { if (current && !current.isConnected) hide() })
  watcher.observe(parent, { childList: true })
}

function unwatchRemoval () {
  if (!watcher) return
  watcher.disconnect()
  watcher = null
}

function show (el) {
  const text = el.dataset.tooltip
  if (!text) return

  build()
  current = el
  watchRemoval(el)

  label.textContent = text
  const shortcut = el.dataset.tooltipKey || ''
  key.textContent = shortcut
  key.hidden = !shortcut

  // Measure with the real content in place, then position, then reveal — otherwise the first
  // frame lands at the previous tooltip's size and visibly jumps.
  tip.classList.add('is-measuring')
  place(el)
  tip.classList.remove('is-measuring')
  tip.classList.add('is-visible')
}

function hide () {
  current = null
  unwatchRemoval()
  if (tip) tip.classList.remove('is-visible')
}

function targetFrom (event) {
  const el = event.target
  return el instanceof Element ? el.closest('[data-tooltip]') : null
}

export function initTooltips () {
  // Pointer, not mouse: `pointerover` bubbles (so one listener covers the document) and reports
  // its device, which is how touch is kept out — a tap would otherwise leave a tooltip stranded
  // on screen with no pointer to move away.
  document.addEventListener('pointerover', (e) => {
    if (e.pointerType && e.pointerType !== 'mouse') return
    const el = targetFrom(e)
    if (!el || el === current) return
    show(el)
  })

  document.addEventListener('pointerout', (e) => {
    const el = targetFrom(e)
    if (el && el === current) hide()
  })

  // Keyboard users get the same hint when they tab to a control.
  document.addEventListener('focusin', (e) => {
    const el = targetFrom(e)
    if (el) show(el)
  })
  document.addEventListener('focusout', hide)

  // Anything that means "I am done reading this": pressing the control, scrolling it away,
  // resizing, or Escape.
  document.addEventListener('pointerdown', hide)
  document.addEventListener('scroll', hide, true)
  window.addEventListener('resize', hide)
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide() })
}
