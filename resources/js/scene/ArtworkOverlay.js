/**
 * ArtworkOverlay — editor-only layer for clipart/artwork placed ON TOP of a scene.
 *
 * The wizard renders each artwork layer here as a free-positioned, draggable, scalable
 * element (centre-anchored, positions stored as % of the stage so they survive resize).
 * This is the EDIT surface; playback renders the same layers through ParallaxScene with
 * the parallax motion added. Interaction mirrors TextOverlayLayer:
 *   • drag anywhere on the layer to move it (x/y %)
 *   • drag the bottom-right handle to scale it
 *   • a press selects it (sky ring), broadcast via `scene-object-selected`
 *   • magnetic snap to ruler guides via window.__guides.snap
 *
 * Layers: [{ asset_id, url, x, y, scale, height, kind }]. Positions/scale persist through
 * the onChange(assetId, { x, y, scale }) callback (a batched Livewire save).
 */
const DRAG_THRESHOLD_PX = 4
const MIN_SCALE = 0.2
const MAX_SCALE = 3

// Object-list / selection id namespace so artwork ids never collide with text-box ids.
export const artObjId = (assetId) => `art_${assetId}`

export class ArtworkOverlay {
  constructor(hostEl, { onChange = null } = {}) {
    this.host = hostEl
    this.onChange = onChange
    this._layers = []
    this._selectedId = null
    this.host.style.pointerEvents = 'none'   // the host is transparent; only layer nodes catch events
    // Deselect when something that isn't one of MY layers is selected (mutually-exclusive
    // selection across text, artwork and background — no re-dispatch, so no loop).
    window.addEventListener('scene-object-selected', (e) => {
      const id = e.detail?.id
      if (id && !this._layers.some(l => artObjId(l.asset_id) === id) && this._selectedId !== null) {
        this._selectedId = null
        this._applySelection()
      }
    })
  }

  setLayers(layers) {
    this._layers = (Array.isArray(layers) ? layers : [])
      .filter(l => l && l.url && (l.asset_id != null))
      .map(l => ({
        asset_id: l.asset_id,
        url: l.url,
        x: Number.isFinite(l.x) ? l.x : 50,
        y: Number.isFinite(l.y) ? l.y : 58,
        scale: Number.isFinite(l.scale) ? l.scale : 1,
        height: Number.isFinite(l.height) ? l.height : 40,
        depth: Number.isFinite(l.depth) ? l.depth : 1,   // parallax: higher = follows the camera more
        blur: Number.isFinite(l.blur) ? l.blur : 0,
        opacity: Number.isFinite(l.opacity) ? l.opacity : 1,
        kind: l.kind || 'figure',
        title: l.title || 'Clipart',
      }))
    this._render()
  }

  // Base node transform (centre-anchored) plus an optional parallax offset in px.
  _transform(item, dx = 0, dy = 0) {
    return `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) scale(${item.scale})`
  }

  /**
   * Parallax drift for the editor preview: offset each layer by its depth × the camera pan,
   * synced to the same progress the background uses. progress 0 = rest (layer at its x/y).
   * The layer being dragged is skipped so editing isn't fought. progress≈0 resets to base.
   */
  setParallax(progress, motion) {
    const m = motion || {}
    const rect = this.host.getBoundingClientRect()
    const p = Number.isFinite(progress) ? progress : 0
    for (const item of this._layers) {
      const el = this.host.querySelector(`[data-layer-id="${artObjId(item.asset_id)}"]`)
      if (!el || el === this._dragNode) continue   // never fight the layer being dragged
      const dx = (m.panX || 0) / 100 * rect.width * p * item.depth
      const dy = (m.panY || 0) / 100 * rect.height * p * item.depth
      el.style.transform = this._transform(item, dx, dy)
    }
  }

  clear() { this.setLayers([]) }

  /** Whether the whole clipart group sits ABOVE the text overlay (drives the host z-index).
   *  Stored here so the object list can read it back when it rebuilds its rows. */
  setOnTop(onTop) { this.onTop = !!onTop }

  /**
   * Reorder the clipart layers from the object list. `idsTopFirst` is the asset ids in panel
   * order — front-most (visually on top) first. The overlay paints `_layers` in array order,
   * so the last element is drawn on top; `_layers` is therefore the reverse of the panel order.
   * Returns the new paint order (bottom-first) for the server to persist.
   */
  reorder(idsTopFirst) {
    const byId = new Map(this._layers.map(l => [String(l.asset_id), l]))
    const topFirst = idsTopFirst.map(id => byId.get(String(id))).filter(Boolean)
    // Any layer the panel didn't mention stays at the visual bottom.
    for (const l of this._layers) if (!topFirst.includes(l)) topFirst.unshift(l)
    this._layers = topFirst.reverse()
    this._render()
    return this._layers.map(l => l.asset_id)
  }

  // ── Selection (kept in sync with the object list, same event as TextOverlayLayer) ──
  select(id) {
    this._selectedId = id
    this._applySelection()
    window.dispatchEvent(new CustomEvent('scene-object-selected', { detail: { id } }))
  }

  _applySelection() {
    for (const item of this._layers) {
      const el = this.host.querySelector(`[data-layer-id="${artObjId(item.asset_id)}"]`)
      if (!el) continue
      const on = artObjId(item.asset_id) === this._selectedId
      el.style.boxShadow = on ? '0 0 0 2px #38bdf8' : ''
      for (const handle of el.querySelectorAll('[data-scale-handle]')) handle.style.display = on ? 'block' : 'none'
    }
  }

  _render() {
    this.host.innerHTML = ''
    for (const item of this._layers) this.host.appendChild(this._node(item))
    this._applySelection()
  }

  _node(item) {
    const node = document.createElement('div')
    node.dataset.layerId = artObjId(item.asset_id)
    node.style.cssText = `position:absolute; left:${item.x}%; top:${item.y}%; height:${item.height}%;
      transform:${this._transform(item)}; transform-origin:center; --art-scale:${item.scale};
      pointer-events:auto; cursor:grab; touch-action:none; user-select:none;`

    const img = document.createElement('img')
    img.src = item.url
    img.alt = item.title
    img.draggable = false
    img.style.cssText = 'height:100%; width:auto; display:block; pointer-events:none; user-select:none;'
    // Depth-of-field blur + opacity preview (the Format sliders — previously ignored here).
    // Applied to the IMG, not the node, so the selection ring + scale handle stay crisp.
    if (item.blur > 0) img.style.filter = `blur(${Math.min(2.5, item.blur)}px)`
    if (item.opacity < 1) img.style.opacity = String(Math.max(0.05, item.opacity))
    node.appendChild(img)

    // Round resize handles on ALL FOUR corners — shown only while selected, each with its own
    // cursor. Dragging a corner keeps the OPPOSITE corner anchored (not centre-scaling).
    const CORNERS = [
      { name: 'tl', pos: 'top:-5px; left:-5px;',     cursor: 'nwse-resize' },
      { name: 'tr', pos: 'top:-5px; right:-5px;',    cursor: 'nesw-resize' },
      { name: 'bl', pos: 'bottom:-5px; left:-5px;',  cursor: 'nesw-resize' },
      { name: 'br', pos: 'bottom:-5px; right:-5px;', cursor: 'nwse-resize' },
    ]
    for (const c of CORNERS) {
      const handle = document.createElement('div')
      handle.dataset.scaleHandle = '1'
      handle.title = 'Drag to resize'
      // Counter the node's scale(item.scale) so the handle chrome stays a constant 10px on
      // screen no matter how large/small the layer is scaled.
      handle.style.cssText = `position:absolute; ${c.pos} width:10px; height:10px; display:none;
        border-radius:50%; background:#fff; border:1px solid rgba(15,23,42,0.55); cursor:${c.cursor};
        box-shadow:0 1px 3px rgba(0,0,0,0.4); touch-action:none; z-index:6;
        transform:scale(calc(1 / var(--art-scale, 1))); transform-origin:center;`
      node.appendChild(handle)
      this._wireScale(item, node, handle, c.name)
    }

    this._wireDrag(item, node)
    return node
  }

  // Drag anywhere on the layer to move it; a press that doesn't move just selects.
  _wireDrag(item, node) {
    node.addEventListener('pointerdown', (e) => {
      if (e.target.dataset && e.target.dataset.scaleHandle) return   // corner handles resize, not drag
      this.select(artObjId(item.asset_id))
      const startX = e.clientX, startY = e.clientY
      const rect = this.host.getBoundingClientRect()
      const origin = { x: item.x, y: item.y }
      let dragging = false

      // Loose bounds: the centre may go well past the stage edges so clipart can sit partly
      // (or mostly) off-canvas — still recoverable via the object list / Layers panel.
      const clamp = () => {
        item.x = Math.min(150, Math.max(-50, item.x))
        item.y = Math.min(150, Math.max(-50, item.y))
      }
      const paint = () => { node.style.left = `${item.x}%`; node.style.top = `${item.y}%` }

      const onMove = (ev) => {
        const dx = ev.clientX - startX, dy = ev.clientY - startY
        if (!dragging && Math.hypot(dx, dy) < DRAG_THRESHOLD_PX) return
        if (!dragging) {
          dragging = true; node.style.cursor = 'grabbing'
          this._dragNode = node   // pause parallax drift on this layer while editing
          node.style.transform = this._transform(item)   // drop any parallax offset so drag is 1:1
          try { node.setPointerCapture?.(ev.pointerId) } catch (_) { /* synthetic/edge pointers throw */ }
        }
        item.x = origin.x + (dx / rect.width) * 100
        item.y = origin.y + (dy / rect.height) * 100
        clamp(); paint()
        // Magnetic snap to ruler guides (edges/centre of the layer).
        const guides = window.__guides
        if (guides && typeof guides.snap === 'function') {
          const { dx: sdx, dy: sdy } = guides.snap(node.getBoundingClientRect())
          if (sdx || sdy) {
            item.x += (sdx / rect.width) * 100
            item.y += (sdy / rect.height) * 100
            clamp(); paint()
          }
        }
      }
      const onUp = () => {
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        node.style.cursor = 'grab'
        if (dragging) { this._dragNode = null; this._emit(item) }
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
    })
  }

  // Corner handle → resize with the OPPOSITE corner anchored (grows toward the dragged corner,
  // like other editors), moving x/y so that corner stays put. `corner` = tl|tr|bl|br.
  _wireScale(item, node, handle, corner) {
    handle.addEventListener('pointerdown', (e) => {
      e.stopPropagation()
      e.preventDefault()
      this.select(artObjId(item.asset_id))
      this._dragNode = node   // pause parallax drift while resizing
      node.style.transform = this._transform(item)   // drop any parallax offset first
      const r = node.getBoundingClientRect()
      const startScale = item.scale
      const startDiag = Math.hypot(r.width, r.height) || 1
      // Anchor = opposite corner (client px); sx/sy point from the anchor toward the centre.
      const A = {
        tl: { ax: r.right, ay: r.bottom, sx: -1, sy: -1 },
        tr: { ax: r.left,  ay: r.bottom, sx: 1,  sy: -1 },
        bl: { ax: r.right, ay: r.top,    sx: -1, sy: 1 },
        br: { ax: r.left,  ay: r.top,    sx: 1,  sy: 1 },
      }[corner] || { ax: r.left, ay: r.top, sx: 1, sy: 1 }

      const onMove = (ev) => {
        const dist = Math.hypot(ev.clientX - A.ax, ev.clientY - A.ay)
        const s = Math.min(MAX_SCALE, Math.max(MIN_SCALE, startScale * (dist / startDiag)))
        const f = s / startScale
        const cxClient = A.ax + A.sx * (r.width * f) / 2      // new centre keeps the anchor fixed
        const cyClient = A.ay + A.sy * (r.height * f) / 2
        const host = this.host.getBoundingClientRect()
        item.scale = s
        item.x = (cxClient - host.left) / host.width * 100
        item.y = (cyClient - host.top) / host.height * 100
        node.style.left = `${item.x}%`
        node.style.top = `${item.y}%`
        node.style.transform = this._transform(item)
        node.style.setProperty('--art-scale', String(s))   // keep handle counter-scale current
      }
      const onUp = () => {
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        this._dragNode = null
        this._emit(item)
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      try { handle.setPointerCapture(e.pointerId) } catch (_) { /* synthetic/edge pointers */ }
    })
  }

  _emit(item) {
    this.onChange?.(item.asset_id, {
      x: Math.round(item.x * 100) / 100,
      y: Math.round(item.y * 100) / 100,
      scale: Math.round(item.scale * 1000) / 1000,
    })
  }
}
