/**
 * TextOverlayLayer — Freeform-style text annotations on a scene.
 *
 * Editable (Configure): the [T] tool adds a canvas-editable text box and selects its Format
 * controls; drag to reposition (pointer moves > 4px = drag, otherwise it's a text edit).
 * Positions are stored as percentages so they survive any viewport size.
 *
 * Readonly (player/preview): plain labels; a text that IS a URL renders as a link
 * chip that opens the page in an iframe modal (with an "open in new tab" escape
 * hatch for sites that refuse to be framed).
 *
 * texts: [{ id, text, x, y, w, font, size, list, anchor, lng, lat }]
 *   x/y    — 0-100 percentages of the host (always kept; the screen position).
 *   w      — optional width as a % of the host (from the resize handle); text wraps to it.
 *   font   — 'sans' (Inter) | 'history' (brand serif) | 'cinzel' (classical caps).
 *   size   — 'sm' | 'md' | 'lg' | 'xl'.
 *   list   — 'none' (default) | 'bullet' | 'number': each \n line is one list item.
 *   align  — 'left' (default) | 'center' | 'right' text alignment inside the box.
 *   bg     — 'glass' adds a frosted-glass card behind the text (blurs the scene image).
 *   bgColor/bgOpacity — colour + opacity of that glass card (defaults #0f172a / 0.3).
 *   anchor — 'screen' (default) or 'map': pinned to a lng/lat, repositioned on
 *            every map move via the host-provided projector.
 *
 * Map pinning: the host calls setProjector({ project, unproject }) when a map is
 * live (and setProjector(null) when it's gone), plus refreshPositions() on map
 * move. Without a projector, map-anchored labels fall back to their stored x/y.
 */
const URL_PATTERN = /^https?:\/\/\S+$/i
const DRAG_THRESHOLD_PX = 4
const EDIT_DRAG_THRESHOLD_PX = 14
// Safe margin (px) kept clear on every edge: text is placed 40px in and can never be dragged
// past it, so teachers can't push a label off-canvas or into the letterbox and lose it.
const SAFE_PAD_PX = 40

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

const alignOf = (item) => (item.align === 'center' || item.align === 'right') ? item.align : 'left'

// Bullet / numbered lists: each line of item.text is one list item. Storage stays
// plain text (no HTML), so there's nothing to sanitize on the way back in.
const listOf = (item) => (item.list === 'bullet' || item.list === 'number') ? item.list : 'none'
const listTag = (list) => (list === 'number' ? 'ol' : 'ul')

// Backing panels use an rgba fill (not element opacity) so the backdrop-blur behind them
// still shows through the translucency — a frosted-glass scrim over the moving scene.
const hexToRgba = (hex, alpha) => {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex))
  if (!m) return `rgba(15,23,42,${alpha})`
  const n = parseInt(m[1], 16)
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`
}

// The rgba fill of a text box's glass card.
const glassFill = (item) =>
  hexToRgba(item.bgColor || '#0f172a', Number.isFinite(item.bgOpacity) ? item.bgOpacity : 0.3)

export class TextOverlayLayer {
  constructor(hostEl, { editable = false, onChange = null } = {}) {
    this.host = hostEl
    this.editable = editable
    this.onChange = onChange
    this._texts = []
    this._projector = null
    this._selectedId = null
    this.host.style.pointerEvents = 'none'
    // Deselect when an object that isn't one of MY text boxes is selected (mutually-exclusive
    // selection across text, artwork and background). No re-dispatch, so no loop.
    if (editable) {
      window.addEventListener('scene-object-selected', (e) => {
        const id = e.detail?.id
        if (id && !this._texts.some(t => t.id === id) && this._selectedId !== null) {
          this._selectedId = null
          this._applySelection()
        }
      })
    }
  }

  // ── Selection ────────────────────────────────────────────────────────────
  // Which object is "active" — kept in sync with the object list. Selecting on the
  // canvas broadcasts `scene-object-selected` so the list highlights the same row.
  select(id) {
    this._selectedId = id
    this._applySelection()
    window.dispatchEvent(new CustomEvent('scene-object-selected', { detail: { id } }))
  }

  // Paint the selection ring on the matching node; clear it from the rest. Rects fill half
  // the stage, so their ring is inset; text boxes get an outer ring hugging the glyphs.
  _applySelection() {
    for (const item of this._texts) {
      const el = this.host.querySelector(`[data-text-id="${item.id}"]`)
      if (!el) continue
      const on = item.id === this._selectedId
      el.style.boxShadow = on
        ? (item.kind === 'rect' ? 'inset 0 0 0 2px #38bdf8' : '0 0 0 2px #38bdf8')
        : ''
    }
  }

  setTexts(texts) {
    this._texts = (Array.isArray(texts) ? texts : [])
      .filter(t => t && (t.kind === 'rect' || typeof t.text === 'string'))
      .map(t => ({ ...t }))
    this._render()
  }

  // Apply a server-originated Format-panel edit without sending it back again.
  patch(id, patch) {
    const item = this._texts.find(text => text.id === id)
    if (!item || !patch || typeof patch !== 'object') return
    Object.assign(item, patch)
    this._render()
  }

  remove(id) {
    const count = this._texts.length
    this._texts = this._texts.filter(text => text.id !== id)
    if (this._texts.length === count) return
    if (this._selectedId === id) this._selectedId = null
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

    // Existing screen-anchored text boxes (ignore rect panels + map-pinned labels), top→bottom.
    const stack = this._texts
      .filter(t => t.kind !== 'rect' && typeof t.text === 'string' && t.anchor !== 'map')
      .sort((a, b) => (a.y ?? 0) - (b.y ?? 0))

    // The first text box on a scene is its Title (large). The rest are body text, each dropped
    // BELOW the lowest existing box with a clear gap so they never land on top of each other.
    const isFirst = stack.length === 0
    // Approx. rendered height per size, as a % of the stage — used to compute the stacking gap.
    const heightPct = { sm: 5, md: 6, lg: 8, xl: 11 }
    const GAP = 4

    // Standard placement: SAFE_PAD (40px) from the left + top edges — the same inset the scene
    // caption uses. Stored as % of the stage so it survives resizes.
    const rect = this.host.getBoundingClientRect()
    const padXPct = rect.width ? (SAFE_PAD_PX / rect.width) * 100 : 4
    const padYPct = rect.height ? (SAFE_PAD_PX / rect.height) * 100 : 6

    let x, y, size
    if (isFirst) {
      // Left, 40px in — unless a vertical ruler sits on the left half of the stage, in which
      // case align the title's left edge to that ruler line instead.
      x = padXPct
      const guides = window.__guides
      if (guides && typeof guides.verticals === 'function') {
        const { rect: gr, xs } = guides.verticals()
        const mid = gr.left + gr.width / 2
        const leftGuides = xs.filter(gx => gx < mid).sort((a, b) => a - b)
        if (leftGuides.length && gr.width) {
          x = Math.max(0, ((leftGuides[0] - gr.left) / gr.width) * 100)
        }
      }
      y = padYPct
      size = 'xl'
    } else {
      const lowest = stack[stack.length - 1]
      x = lowest.x ?? padXPct
      y = Math.min(100 - padYPct, (lowest.y ?? padYPct) + (heightPct[lowest.size] ?? 6) + GAP)
      size = 'md'
    }

    const item = {
      id: `txt_${Math.random().toString(36).slice(2, 9)}`,
      text: isFirst ? 'Title' : 'Text',   // placeholder, pre-selected so typing replaces it
      x,
      y,
      font: 'sans',
      size,
      anchor: 'screen',
    }
    this._texts.push(item)
    this._render()
    this._emitChange()
    this.select(item.id)
    const edit = this.host.querySelector(`[data-text-id="${item.id}"] [contenteditable]`)
    if (edit) {
      edit.focus()
      const range = document.createRange()
      range.selectNodeContents(edit)
      const selection = window.getSelection()
      selection.removeAllRanges()
      selection.addRange(range)
    }
  }

  /**
   * Add a half-screen backing panel (left or right) — a legibility scrim so text
   * reads over busy paintings. A preset, not a freeform shape: side + colour + opacity.
   */
  addRect(side = 'left') {
    if (!this.editable) return
    const item = {
      id: `rect_${Math.random().toString(36).slice(2, 9)}`,
      kind: 'rect',
      side: side === 'right' ? 'right' : 'left',
      color: '#0f172a',
      opacity: 0.5,
    }
    this._texts.push(item)
    this._render()
    this._emitChange()
    this.select(item.id)
  }

  clear() { this.setTexts([]) }

  _emitChange() {
    this.onChange?.(
      this._texts
        .filter(t => t.kind === 'rect' || (typeof t.text === 'string' && t.text.trim() !== ''))
        .map(t => ({ ...t })),
      this._selectedId
    )
  }

  // Effective stacking order. An explicit z (set by object-list drag-reorder) wins; without
  // one, panels default behind text — the legacy behaviour, so old scenes render unchanged.
  _effZ(item) {
    if (Number.isFinite(item.z)) return item.z
    return item.kind === 'rect' ? 0 : 1
  }

  _render() {
    this.host.innerHTML = ''
    this.host.style.pointerEvents = 'none'
    // Lowest z first (appended first = drawn behind); insertion order breaks ties so items
    // that share a z keep their original front-to-back relationship.
    const ordered = this._texts
      .map((item, idx) => ({ item, idx }))
      .sort((a, b) => (this._effZ(a.item) - this._effZ(b.item)) || (a.idx - b.idx))
    for (const { item } of ordered) {
      const node = item.kind === 'rect'
        ? (this.editable ? this._editableRect(item) : this._readonlyRect(item))
        : (this.editable ? this._editableNode(item) : this._readonlyNode(item))
      this.host.appendChild(node)
    }
    this.refreshPositions()
    if (this.editable) this._applySelection()   // selection ring survives re-renders
    // The iframe modal outlives re-renders; it's appended to <body> on demand.
  }

  /**
   * Restack objects from a top→bottom id list (top of the object list = frontmost). Assigns a
   * dense z to each so the new order survives re-renders and is persisted via onChange.
   */
  reorder(idsTopToBottom) {
    if (!this.editable || !Array.isArray(idsTopToBottom)) return
    const n = idsTopToBottom.length
    idsTopToBottom.forEach((id, i) => {
      const item = this._texts.find(t => t.id === id)
      if (item) item.z = n - i   // top → highest z → drawn last → in front
    })
    this._render()
    this._emitChange()
  }

  // ── Rectangle panels (backing scrim) ─────────────────────────────────────
  _rectStyle(item) {
    const edge = item.side === 'right' ? 'right:0;' : 'left:0;'
    const op = Number.isFinite(item.opacity) ? item.opacity : 0.5
    return `position:absolute; top:0; bottom:0; ${edge} width:50%;
      background:${hexToRgba(item.color || '#0f172a', op)};
      backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);`
  }

  _readonlyRect(item) {
    const node = document.createElement('div')
    node.dataset.textId = item.id
    node.style.cssText = this._rectStyle(item) + 'pointer-events:none;'
    return node
  }

  _editableRect(item) {
    const node = document.createElement('div')
    node.dataset.textId = item.id
    node.style.cssText = this._rectStyle(item) +
      'pointer-events:auto; cursor:pointer;'
    node.addEventListener('pointerdown', () => this.select(item.id))
    return node
  }

  _baseNode(item) {
    const node = document.createElement('div')
    node.dataset.textId = item.id
    node.style.cssText = `position:absolute; left:${item.x}%; top:${item.y}%; max-width:46%;
      pointer-events:auto; color:#f8fafc; font-size:${sizeOf(item).css};
      font-family:${fontOf(item).stack}; font-weight:${fontOf(item).weight};
      line-height:1.3; text-shadow:0 2px 12px rgba(0,0,0,0.75);`
    // Frosted-glass card: blurs the moving scene image behind the text.
    if (item.bg === 'glass') {
      node.style.background = glassFill(item)
      node.style.backdropFilter = 'blur(12px)'
      node.style.webkitBackdropFilter = 'blur(12px)'
      node.style.border = '1px solid rgba(255,255,255,0.14)'
      node.style.borderRadius = '18px'
      node.style.padding = '16px 22px'
      node.style.boxShadow = '0 10px 34px rgba(0,0,0,0.4)'
    }
    // A resized box uses an explicit width (so text wraps to it); otherwise the default
    // max-width lets it grow to fit the content.
    if (Number.isFinite(item.w)) {
      node.style.width = `${item.w}%`
      node.style.maxWidth = 'none'
    }
    node.style.textAlign = alignOf(item)
    return node
  }

  _applyStyle(item, node) {
    node.style.fontSize = sizeOf(item).css
    node.style.fontFamily = fontOf(item).stack
    node.style.fontWeight = fontOf(item).weight
  }

  // Build the contenteditable element for a box: a plain div, or a <ul>/<ol> with one
  // <li> per line when the box is in list mode (browsers give native Enter=new-item).
  _makeEditEl(item) {
    const list = listOf(item)
    const glass = item.bg === 'glass'
    // Standard text has NO background bubble — it sits directly on the scene (like the caption),
    // legible via its text-shadow. Only a faint dashed outline marks the edit target, and the
    // padding is tight so the visible text edge hugs the box edge (and thus any ruler it snaps to).
    // A background is opt-in via the ❖ Glass toggle, which supplies its own backdrop + padding.
    const base = `outline:none; min-width:60px; min-height:1.3em; padding:${glass ? '0' : '1px 3px'};
      border:1.5px dashed ${glass ? 'rgba(245,158,11,0.35)' : 'rgba(245,158,11,0.55)'}; border-radius:8px;
      background:transparent; cursor:text;`
    let edit
    if (list === 'none') {
      edit = document.createElement('div')
      edit.style.cssText = base + ' white-space:pre-wrap;'
      edit.textContent = item.text
    } else {
      edit = document.createElement(listTag(list))
      // Explicit list-style-type — Tailwind Preflight resets ul/ol to none. Centered/right
      // lists put the marker inside so it travels with the (non-left-aligned) text.
      const inside = alignOf(item) !== 'left'
      edit.style.cssText = base + ` margin:0; padding-left:${inside ? '0' : '1.4em'};
        list-style-position:${inside ? 'inside' : 'outside'}; list-style-type:${list === 'number' ? 'decimal' : 'disc'};`
      const lines = String(item.text).split('\n')
      for (const line of (lines.length ? lines : [''])) {
        const li = document.createElement('li')
        li.textContent = line
        edit.appendChild(li)
      }
      if (!edit.children.length) edit.appendChild(document.createElement('li'))
    }
    edit.contentEditable = 'true'
    edit.spellcheck = false
    edit.dataset.placeholder = 'Type…'
    return edit
  }

  // Read a box's text back as plain lines (\n-joined), from either shape.
  _readEditText(edit) {
    if (edit.tagName === 'UL' || edit.tagName === 'OL') {
      return [...edit.querySelectorAll('li')]
        .map(li => li.innerText.replace(/\s+/g, ' ').trim())
        .join('\n')
        .replace(/\n{2,}/g, '\n')
        .trim()
    }
    return edit.innerText.trim()
  }

  // ── Editable (Configure) ─────────────────────────────────────────────────
  _editableNode(item) {
    const node = this._baseNode(item)
    node.style.cursor = 'move'
    node.style.touchAction = 'none'

    const edit = this._makeEditEl(item)
    edit.addEventListener('focus', () => { edit.style.borderColor = '#f59e0b' })
    edit.addEventListener('blur', () => {
      edit.style.borderColor = 'rgba(245,158,11,0.6)'
      item.text = this._readEditText(edit)
      if (item.text === '') {
        this._texts = this._texts.filter(text => text.id !== item.id)
        this._render()
      }
      this._emitChange()
    })
    edit.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') edit.blur()
    })
    node.appendChild(edit)

    // Native drag-and-drop of selected text swallows the pointer stream BEFORE our threshold
    // logic ever runs (the fresh-add placeholder is selected, so this hit every new box).
    node.addEventListener('dragstart', (e) => e.preventDefault())

    // Drag anywhere on the box; a press that doesn't move stays a click-to-edit.
    node.addEventListener('pointerdown', (e) => {
      this.select(item.id)
      const editingPress = edit.contains(e.target) && edit.contains(document.activeElement)
      const startX = e.clientX, startY = e.clientY
      const rect = this.host.getBoundingClientRect()
      const origin = { x: item.x, y: item.y }
      let dragging = false

      // Keep the box inside the 40px safe margin on all four edges. The right/bottom bounds
      // depend on the box's own size, so recompute the clamp from its live rect each move.
      const clamp = () => {
        const nrect = node.getBoundingClientRect()
        const padXPct = rect.width ? (SAFE_PAD_PX / rect.width) * 100 : 0
        const padYPct = rect.height ? (SAFE_PAD_PX / rect.height) * 100 : 0
        const nwPct = rect.width ? (nrect.width / rect.width) * 100 : 0
        const nhPct = rect.height ? (nrect.height / rect.height) * 100 : 0
        const maxX = Math.max(padXPct, 100 - padXPct - nwPct)
        const maxY = Math.max(padYPct, 100 - padYPct - nhPct)
        item.x = Math.min(maxX, Math.max(padXPct, item.x))
        item.y = Math.min(maxY, Math.max(padYPct, item.y))
      }

      const onMove = (ev) => {
        const dx = ev.clientX - startX, dy = ev.clientY - startY
        if (!dragging && Math.hypot(dx, dy) < (editingPress ? EDIT_DRAG_THRESHOLD_PX : DRAG_THRESHOLD_PX)) return
        if (!dragging && editingPress) {
          const selection = document.getSelection()
          const selectedText = selection && !selection.isCollapsed ? String(selection).trim() : ''
          if (selectedText !== '' && selectedText !== (edit.innerText || '').trim()) {
            window.removeEventListener('pointermove', onMove)
            window.removeEventListener('pointerup', onUp)
            return
          }
          selection?.removeAllRanges()
        }
        if (!dragging) {
          dragging = true
          edit.blur()
          try { node.setPointerCapture?.(ev.pointerId) } catch (_) { /* synthetic/edge pointers throw */ }
        }
        item.x = origin.x + (dx / rect.width) * 100
        item.y = origin.y + (dy / rect.height) * 100
        clamp()
        node.style.left = `${item.x}%`
        node.style.top = `${item.y}%`
        // Magnetic guide snap — pull the object's edges/centre to any ruler guide within 10px,
        // then re-clamp so a snap can never nudge the label past the safe margin.
        const guides = window.__guides
        if (guides && typeof guides.snap === 'function') {
          const { dx: sdx, dy: sdy } = guides.snap(node.getBoundingClientRect())
          if (sdx || sdy) {
            item.x = item.x + (sdx / rect.width) * 100
            item.y = item.y + (sdy / rect.height) * 100
            clamp()
            node.style.left = `${item.x}%`
            node.style.top = `${item.y}%`
          }
        }
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

    // Text alignment — cycles left → center → right.
    const alignBtn = document.createElement('button')
    alignBtn.type = 'button'
    const paintAlign = () => {
      const a = alignOf(item)
      alignBtn.textContent = ALIGN_ICON[a]
      alignBtn.title = `Align ${a}`
      alignBtn.style.cssText = selectCss
    }
    paintAlign()
    alignBtn.addEventListener('click', () => {
      const editEl = node.querySelector('[contenteditable]')
      if (editEl) item.text = this._readEditText(editEl)
      item.align = ALIGN_CYCLE[alignOf(item)]
      this._render()
      this._emitChange()
      this.host.querySelector(`[data-text-id="${item.id}"] [contenteditable]`)?.focus()
    })
    bar.appendChild(alignBtn)

    // List toggle — cycles none → bullets → numbers. Rebuilds the box so the
    // contenteditable switches between a plain div and a native <ul>/<ol>.
    const listBtn = document.createElement('button')
    listBtn.type = 'button'
    const paintList = () => {
      const l = listOf(item)
      listBtn.textContent = l === 'bullet' ? '• List' : l === 'number' ? '1. List' : '≡ List'
      listBtn.title = 'Bullet / numbered list'
      listBtn.style.cssText = `${selectCss} background:${l !== 'none' ? 'rgba(245,158,11,0.2)' : '#1e293b'};
        border-color:${l !== 'none' ? 'rgba(245,158,11,0.6)' : 'rgba(255,255,255,0.15)'};`
    }
    paintList()
    listBtn.addEventListener('click', () => {
      const editEl = node.querySelector('[contenteditable]')
      if (editEl) item.text = this._readEditText(editEl)   // capture edits before rebuilding
      item.list = LIST_CYCLE[listOf(item)]
      this._render()
      this._emitChange()
      const fresh = this.host.querySelector(`[data-text-id="${item.id}"] [contenteditable]`)
      fresh?.focus()
    })
    bar.appendChild(listBtn)

    // Frosted-glass background toggle.
    const bgBtn = document.createElement('button')
    bgBtn.type = 'button'
    const paintBg = () => {
      const on = item.bg === 'glass'
      bgBtn.textContent = '❖ Glass'
      bgBtn.title = 'Frosted-glass background'
      bgBtn.style.cssText = `${selectCss} background:${on ? 'rgba(245,158,11,0.2)' : '#1e293b'};
        border-color:${on ? 'rgba(245,158,11,0.6)' : 'rgba(255,255,255,0.15)'};`
    }
    paintBg()
    bgBtn.addEventListener('click', () => {
      const editEl = node.querySelector('[contenteditable]')
      if (editEl) item.text = this._readEditText(editEl)
      item.bg = item.bg === 'glass' ? 'none' : 'glass'
      this._render()
      this._emitChange()
      this.host.querySelector(`[data-text-id="${item.id}"] [contenteditable]`)?.focus()
    })
    bar.appendChild(bgBtn)

    // When the glass card is on, expose its colour + opacity.
    if (item.bg === 'glass') {
      const swatch = makeSwatch(item.bgColor || '#0f172a', (c) => {
        item.bgColor = c
        node.style.background = glassFill(item)
        this._emitChange()
      })
      bar.appendChild(swatch)
      const op = makeOpacity(
        Number.isFinite(item.bgOpacity) ? item.bgOpacity : 0.3,
        (v) => { item.bgOpacity = v; node.style.background = glassFill(item) },
        () => this._emitChange()
      )
      bar.appendChild(op)
    }

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
    const list = listOf(item)
    const t = item.text.trim()

    if (list !== 'none') {
      const el = document.createElement(listTag(list))
      const inside = alignOf(item) !== 'left'
      el.style.cssText = `margin:0; padding-left:${inside ? '0' : '1.4em'};
        list-style-position:${inside ? 'inside' : 'outside'}; list-style-type:${list === 'number' ? 'decimal' : 'disc'};`
      for (const line of t.split('\n')) {
        if (line.trim() === '') continue
        const li = document.createElement('li')
        li.textContent = line
        el.appendChild(li)
      }
      node.appendChild(el)
    } else if (URL_PATTERN.test(t)) {
      const url = t
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
      node.style.whiteSpace = 'pre-wrap'
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
