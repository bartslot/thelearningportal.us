/**
 * TextOverlayLayer — Freeform-style text annotations on a scene.
 *
 * Editable (Configure): the [T] tool adds a text box that is focused immediately;
 * drag to reposition (pointer moves > 4px = drag, otherwise it's a click-to-edit).
 * Focusing a box shows a small toolbar: font style, size, and — on map scenes —
 * whether the label is pinned to the map (moves with pan/zoom) or stuck to the
 * screen. Positions are stored as percentages so they survive any viewport size.
 *
 * Readonly (player/preview): plain labels; a text that IS a URL renders as a link
 * chip that opens the page in an iframe modal (with an "open in new tab" escape
 * hatch for sites that refuse to be framed).
 *
 * texts: [{ id, text, x, y, font, size, anchor, lng, lat }]
 *   x/y    — 0-100 percentages of the host (always kept; the screen position).
 *   font   — 'sans' (Inter) | 'history' (brand serif) | 'cinzel' (classical caps).
 *   size   — 'sm' | 'md' | 'lg' | 'xl'.
 *   anchor — 'screen' (default) or 'map': pinned to a lng/lat, repositioned on
 *            every map move via the host-provided projector.
 *
 * Map pinning: the host calls setProjector({ project, unproject }) when a map is
 * live (and setProjector(null) when it's gone), plus refreshPositions() on map
 * move. Without a projector, map-anchored labels fall back to their stored x/y.
 */
const URL_PATTERN = /^https?:\/\/\S+$/i
const DRAG_THRESHOLD_PX = 4

const FONTS = {
  sans:    { label: 'Clean',   stack: "'Inter', ui-sans-serif, sans-serif", weight: 600 },
  history: { label: 'History', stack: "'History', serif",                   weight: 700 },
  cinzel:  { label: 'Classic', stack: "'Cinzel', serif",                    weight: 700 },
}
const SIZES = {
  sm: { label: 'S',  css: 'clamp(12px, 1.4vw, 18px)' },
  md: { label: 'M',  css: 'clamp(16px, 2.2vw, 28px)' },
  lg: { label: 'L',  css: 'clamp(22px, 3.2vw, 40px)' },
  xl: { label: 'XL', css: 'clamp(30px, 4.6vw, 58px)' },
}
const fontOf = (item) => FONTS[item.font] || FONTS.sans
const sizeOf = (item) => SIZES[item.size] || SIZES.md

export class TextOverlayLayer {
  constructor(hostEl, { editable = false, onChange = null } = {}) {
    this.host = hostEl
    this.editable = editable
    this.onChange = onChange
    this._texts = []
    this._projector = null
    this.host.style.pointerEvents = 'none'
  }

  setTexts(texts) {
    this._texts = (Array.isArray(texts) ? texts : [])
      .filter(t => t && typeof t.text === 'string')
      .map(t => ({ ...t }))
    this._render()
  }

  /**
   * Wire (or clear, with null) the map projector used by map-anchored labels:
   *   { project(lng, lat) → {x, y} host-percentages or null,
   *     unproject(xPct, yPct) → {lng, lat} or null }
   */
  setProjector(projector) {
    this._projector = projector || null
    this._render()
  }

  /** Reposition map-anchored labels — call on every map move/zoom. */
  refreshPositions() {
    if (!this._projector) return
    for (const item of this._texts) {
      if (item.anchor !== 'map' || !Number.isFinite(item.lng) || !Number.isFinite(item.lat)) continue
      const pos = this._projector.project(item.lng, item.lat)
      if (!pos) continue
      item.x = pos.x
      item.y = pos.y
      const node = this.host.querySelector(`[data-text-id="${item.id}"]`)
      if (node) { node.style.left = `${pos.x}%`; node.style.top = `${pos.y}%` }
    }
  }

  addText() {
    if (!this.editable) return
    const item = {
      id: `txt_${Math.random().toString(36).slice(2, 9)}`,
      text: '',
      x: 38 + Math.random() * 6,   // roughly centred, slightly varied so stacks don't overlap
      y: 38 + Math.random() * 6,
      font: 'sans',
      size: 'md',
      anchor: 'screen',
    }
    this._texts.push(item)
    this._render()
    // Focus the fresh box so the teacher can type immediately.
    const el = this.host.querySelector(`[data-text-id="${item.id}"] [contenteditable]`)
    el?.focus()
  }

  clear() { this.setTexts([]) }

  _emitChange() {
    this.onChange?.(this._texts.filter(t => t.text.trim() !== '').map(t => ({ ...t })))
  }

  _render() {
    this.host.innerHTML = ''
    this.host.style.pointerEvents = 'none'
    for (const item of this._texts) {
      this.host.appendChild(this.editable ? this._editableNode(item) : this._readonlyNode(item))
    }
    this.refreshPositions()
    // The iframe modal outlives re-renders; it's appended to <body> on demand.
  }

  _baseNode(item) {
    const node = document.createElement('div')
    node.dataset.textId = item.id
    node.style.cssText = `position:absolute; left:${item.x}%; top:${item.y}%; max-width:46%;
      pointer-events:auto; color:#f8fafc; font-size:${sizeOf(item).css};
      font-family:${fontOf(item).stack}; font-weight:${fontOf(item).weight};
      line-height:1.3; text-shadow:0 2px 12px rgba(0,0,0,0.75);`
    return node
  }

  _applyStyle(item, node) {
    node.style.fontSize = sizeOf(item).css
    node.style.fontFamily = fontOf(item).stack
    node.style.fontWeight = fontOf(item).weight
  }

  // ── Editable (Configure) ─────────────────────────────────────────────────
  _editableNode(item) {
    const node = this._baseNode(item)
    node.style.cursor = 'move'

    const edit = document.createElement('div')
    edit.contentEditable = 'true'
    edit.spellcheck = false
    edit.textContent = item.text
    edit.dataset.placeholder = 'Type…'
    edit.style.cssText = `outline:none; min-width:60px; min-height:1.3em; padding:4px 10px;
      border:1.5px dashed rgba(245,158,11,0.6); border-radius:10px; background:rgba(2,6,23,0.35); cursor:text;`
    edit.addEventListener('focus', () => { edit.style.borderColor = '#f59e0b' })
    edit.addEventListener('blur', () => {
      edit.style.borderColor = 'rgba(245,158,11,0.6)'
      item.text = edit.textContent.trim()
      if (item.text === '') {
        this._texts = this._texts.filter(t => t.id !== item.id)
        this._render()
      }
      this._emitChange()
    })
    edit.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') edit.blur()
    })
    node.appendChild(edit)

    // Options toolbar (font / size / pin) — appears while the box has focus.
    const toolbar = this._toolbarNode(item, node)
    node.appendChild(toolbar)
    node.addEventListener('focusin', () => { toolbar.style.display = 'flex' })
    node.addEventListener('focusout', (e) => {
      if (!node.contains(e.relatedTarget)) toolbar.style.display = 'none'
    })

    // Small × top-right to delete the box (pointerdown so it beats the blur/drag handlers).
    const remove = document.createElement('button')
    remove.type = 'button'
    remove.textContent = '✕'
    remove.title = 'Remove text'
    remove.setAttribute('aria-label', 'Remove text')
    remove.style.cssText = `position:absolute; top:-10px; right:-10px; width:22px; height:22px;
      display:flex; align-items:center; justify-content:center; border-radius:999px;
      background:#0f172a; border:1px solid rgba(245,158,11,0.6); color:#fbbf24;
      font-size:11px; line-height:1; cursor:pointer; opacity:0.55; transition:opacity 0.15s;`
    node.addEventListener('pointerenter', () => { remove.style.opacity = '1' })
    node.addEventListener('pointerleave', () => { remove.style.opacity = '0.55' })
    remove.addEventListener('pointerdown', (e) => {
      e.stopPropagation()
      e.preventDefault()
      this._texts = this._texts.filter(t => t.id !== item.id)
      this._render()
      this._emitChange()
    })
    node.appendChild(remove)

    // Drag anywhere on the box; a press that doesn't move stays a click-to-edit.
    node.addEventListener('pointerdown', (e) => {
      if (toolbar.contains(e.target)) return                              // toolbar clicks aren't drags
      if (e.target === edit && document.activeElement === edit) return  // typing — don't hijack
      const startX = e.clientX, startY = e.clientY
      const rect = this.host.getBoundingClientRect()
      const origin = { x: item.x, y: item.y }
      let dragging = false

      const onMove = (ev) => {
        const dx = ev.clientX - startX, dy = ev.clientY - startY
        if (!dragging && Math.hypot(dx, dy) < DRAG_THRESHOLD_PX) return
        if (!dragging) { dragging = true; edit.blur(); node.setPointerCapture?.(ev.pointerId) }
        item.x = Math.min(96, Math.max(0, origin.x + (dx / rect.width) * 100))
        item.y = Math.min(96, Math.max(0, origin.y + (dy / rect.height) * 100))
        node.style.left = `${item.x}%`
        node.style.top = `${item.y}%`
      }
      const onUp = () => {
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        if (dragging) {
          // A map-pinned label dragged to a new spot is pinned to the NEW place.
          if (item.anchor === 'map' && this._projector) {
            const ll = this._projector.unproject(item.x, item.y)
            if (ll) { item.lng = ll.lng; item.lat = ll.lat }
          }
          this._emitChange()
        } else edit.focus()
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
    })

    return node
  }

  // Font / size / pin controls shown while a text box is focused.
  _toolbarNode(item, node) {
    const bar = document.createElement('div')
    bar.style.cssText = `position:absolute; bottom:calc(100% + 8px); left:0; display:none;
      align-items:center; gap:6px; padding:5px 8px; border-radius:10px;
      background:#0f172a; border:1px solid rgba(245,158,11,0.45); box-shadow:0 6px 20px rgba(0,0,0,0.5);
      font-family:'Inter', sans-serif; font-size:12px; font-weight:500; white-space:nowrap; z-index:5;`
    // Keep the box focused while using the toolbar (pointerdown would otherwise blur + drag).
    bar.addEventListener('pointerdown', (e) => e.stopPropagation())

    const selectCss = `background:#1e293b; color:#e2e8f0; border:1px solid rgba(255,255,255,0.15);
      border-radius:7px; padding:3px 6px; font-size:12px; outline:none; cursor:pointer;`

    const fontSel = document.createElement('select')
    fontSel.title = 'Font style'
    fontSel.style.cssText = selectCss
    for (const [key, f] of Object.entries(FONTS)) {
      const o = document.createElement('option')
      o.value = key
      o.textContent = f.label
      fontSel.appendChild(o)
    }
    fontSel.value = FONTS[item.font] ? item.font : 'sans'
    fontSel.addEventListener('change', () => {
      item.font = fontSel.value
      this._applyStyle(item, node)
      this._emitChange()
    })
    bar.appendChild(fontSel)

    const sizeSel = document.createElement('select')
    sizeSel.title = 'Text size'
    sizeSel.style.cssText = selectCss
    for (const [key, s] of Object.entries(SIZES)) {
      const o = document.createElement('option')
      o.value = key
      o.textContent = s.label
      sizeSel.appendChild(o)
    }
    sizeSel.value = SIZES[item.size] ? item.size : 'md'
    sizeSel.addEventListener('change', () => {
      item.size = sizeSel.value
      this._applyStyle(item, node)
      this._emitChange()
    })
    bar.appendChild(sizeSel)

    // Pin toggle — only meaningful when a map is live under the overlay.
    if (this._projector) {
      const pin = document.createElement('button')
      pin.type = 'button'
      const paint = () => {
        const pinned = item.anchor === 'map'
        pin.textContent = pinned ? '📌 Map' : '🖥 Screen'
        pin.title = pinned
          ? 'Pinned to the map — moves with pan/zoom. Click to stick to the screen instead.'
          : 'Stuck to the screen. Click to pin to the map at this spot.'
        pin.style.cssText = `${selectCss} background:${pinned ? 'rgba(245,158,11,0.2)' : '#1e293b'};
          border-color:${pinned ? 'rgba(245,158,11,0.6)' : 'rgba(255,255,255,0.15)'};`
      }
      paint()
      pin.addEventListener('click', () => {
        if (item.anchor === 'map') {
          item.anchor = 'screen'
          delete item.lng
          delete item.lat
        } else {
          const ll = this._projector.unproject(item.x, item.y)
          if (!ll) return
          item.anchor = 'map'
          item.lng = ll.lng
          item.lat = ll.lat
        }
        paint()
        this._emitChange()
      })
      bar.appendChild(pin)
    }

    return bar
  }

  // ── Readonly (player / preview) ──────────────────────────────────────────
  _readonlyNode(item) {
    const node = this._baseNode(item)

    if (URL_PATTERN.test(item.text.trim())) {
      const url = item.text.trim()
      const chip = document.createElement('button')
      let label
      try { label = new URL(url).hostname.replace(/^www\./, '') } catch { label = url }
      chip.textContent = `🔗 ${label}`
      chip.style.cssText = `display:inline-flex; align-items:center; gap:8px; padding:8px 16px;
        border-radius:999px; border:1px solid rgba(245,158,11,0.5); background:rgba(2,6,23,0.7);
        color:#fbbf24; font-size:16px; font-weight:600; cursor:pointer;`
      chip.addEventListener('click', () => this._openLinkModal(url))
      node.appendChild(chip)
    } else {
      node.textContent = item.text
    }

    return node
  }

  _openLinkModal(url) {
    const overlay = document.createElement('div')
    overlay.style.cssText = `position:fixed; inset:0; z-index:80; display:flex; align-items:center;
      justify-content:center; background:rgba(2,6,23,0.8); backdrop-filter:blur(4px);`
    overlay.innerHTML = `
      <div style="width:min(1000px, calc(100vw - 40px)); height:min(700px, calc(100vh - 80px));
                  background:#0f172a; border:1px solid rgba(245,158,11,0.35); border-radius:20px;
                  overflow:hidden; display:flex; flex-direction:column;">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 16px;
                    border-bottom:1px solid rgba(255,255,255,0.08); color:#94a3b8; font-size:13px;">
          <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${url.replace(/</g, '&lt;')}</span>
          <span style="display:flex; gap:14px; flex-shrink:0; margin-left:16px;">
            <a href="${url.replace(/"/g, '&quot;')}" target="_blank" rel="noopener noreferrer"
               style="color:#fbbf24; text-decoration:none;">Open in new tab ↗</a>
            <button data-close style="background:none; border:none; color:#e2e8f0; font-size:18px; cursor:pointer;">✕</button>
          </span>
        </div>
        <iframe src="${url.replace(/"/g, '&quot;')}" style="flex:1; border:none; background:white;"
                sandbox="allow-scripts allow-same-origin allow-popups allow-forms" referrerpolicy="no-referrer"></iframe>
      </div>`
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove() })
    overlay.querySelector('[data-close]').addEventListener('click', () => overlay.remove())
    document.body.appendChild(overlay)
  }
}
