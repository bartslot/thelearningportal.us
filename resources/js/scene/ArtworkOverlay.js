/**
 * ArtworkOverlay — editor-only layer for clipart/artwork placed ON TOP of a scene.
 *
 * The wizard renders each artwork layer here as a free-positioned, draggable
 * element (centre-anchored, positions stored as % of the stage so they survive resize).
 * This is the EDIT surface; playback renders the same layers through ParallaxScene with
 * the parallax motion added. Interaction mirrors TextOverlayLayer:
 *   • drag anywhere on the layer to move it (x/y %)
 *   • Format panel controls set scale and visual treatment
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
  constructor(hostEl, { onChange = null, readonly = false } = {}) {
    this.host = hostEl
    this.onChange = onChange
    this.readonly = readonly   // playback: render the layers but never let the viewer drag them
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
      // A layer is renderable if it has an image url OR an iframe embed (3D / video).
      .filter(l => l && (l.url || l.embed) && (l.asset_id != null))
      .map(l => ({
        asset_id: l.asset_id,
        url: l.url || null,
        embed: l.embed || null,   // { type:'sketchfab'|'video', src, title, ... } for iframe layers
        x: Number.isFinite(l.x) ? l.x : 50,
        y: Number.isFinite(l.y) ? l.y : 58,
        scale: Number.isFinite(l.scale) ? l.scale : 1,
        height: Number.isFinite(l.height) ? l.height : 40,
        depth: Number.isFinite(l.depth) ? l.depth : 1,   // parallax: higher = follows the camera more
        blur: Number.isFinite(l.blur) ? l.blur : 0,
        opacity: Number.isFinite(l.opacity) ? l.opacity : 1,
        kind: l.kind || 'figure',
        title: l.title || (l.embed && l.embed.title) || 'Clipart',
      }))
    this._render()
  }

  // Base node transform: centre-anchored translate only (+ optional parallax offset).
  // The layer's *scale* is applied to the node HEIGHT, not here — so the node's border-box
  // equals the on-screen image box and the selection ring never scales with it.
  _transform(item, dx = 0, dy = 0) {
    return `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px))`
  }

  // On-screen height of the node = base height × user scale, as a % of the stage.
  _heightPct(item) {
    return item.height * item.scale
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
    // width:max-content so the node sizes to the image's natural aspect at the given height.
    // (A plain shrink-to-fit box collapses to a wrong tall/narrow shape when the child img
    // uses percentage height — that distorted the selection box.)
    // Playback nodes are inert (pointer-events:none) so a student can't drag the decoration and it
    // never steals a map pan; editor nodes catch pointers to move/select.
    const interact = this.readonly
      ? 'pointer-events:none; cursor:default;'
      : 'pointer-events:auto; cursor:grab;'

    if (item.embed) {
      // 3D / video layer — an <iframe> instead of an <img>. It has no intrinsic aspect, so give the
      // node an explicit ratio (video 16:9, model viewer 4:3) and derive width from the % height.
      const ratio = item.embed.type === 'video' ? '16 / 9' : '4 / 3'
      node.style.cssText = `position:absolute; left:${item.x}%; top:${item.y}%; height:${this._heightPct(item)}%;
        aspect-ratio:${ratio}; width:auto; transform:${this._transform(item)}; transform-origin:center;
        ${interact} touch-action:none; user-select:none;`

      const frame = document.createElement('iframe')
      frame.src = item.embed.src
      frame.title = item.title || item.embed.title || '3D / video'
      frame.setAttribute('frameborder', '0')
      frame.setAttribute('allow', 'autoplay; fullscreen; xr-spatial-tracking; encrypted-media; picture-in-picture')
      frame.setAttribute('allowfullscreen', 'true')
      // Editor: the iframe must NOT eat pointer events or the layer can't be dragged — a transparent
      // shield over it is the drag surface. Playback: a Sketchfab model stays interactive (rotate),
      // a video stays inert (it just plays); both never block a map pan because the host is pe:none.
      // A 3D model's own settings: can it be grabbed, and what sits behind it. `interact` off
      // makes it a moving picture — the right thing behind narration — and matters here as well as
      // in the viewer URL, because pointer-events is what actually stops a grab.
      const opts = item.embed.opts || {}
      const canGrab = opts.interact !== false
      const live = this.readonly && item.embed.type === 'sketchfab' && canGrab
      // 'none' means the model was cut out of its studio backdrop, so anything but transparent here
      // would put the black box straight back. Glass frosts what is behind it; a hex is painted flat.
      const bg = item.embed.type === 'sketchfab' ? (opts.bg ?? 'none') : '#000'
      const solid = typeof bg === 'string' && /^#[0-9a-f]{6}$/i.test(bg)
      frame.style.cssText = `position:absolute; inset:0; width:100%; height:100%; display:block; border:0;
        pointer-events:${live ? 'auto' : 'none'}; border-radius:6px; overflow:hidden;
        background:${solid ? bg : 'transparent'};`
      if (bg === 'glass') {
        // The frosting has to sit BEHIND the iframe, which is transparent, so it goes on the node.
        node.style.background = 'rgba(148,163,184,0.14)'
        node.style.backdropFilter = 'blur(10px) saturate(120%)'
        node.style.borderRadius = '10px'
        node.style.boxShadow = 'inset 0 0 0 1px rgba(255,255,255,0.16)'
      }
      if (item.opacity < 1) frame.style.opacity = String(Math.max(0.05, item.opacity))
      node.appendChild(frame)

      if (!this.readonly) {
        const shield = document.createElement('div')
        shield.style.cssText = 'position:absolute; inset:0; pointer-events:auto; cursor:grab; background:transparent;'
        node.appendChild(shield)
        this._wireDrag(item, node)
      }
      return node
    }

    // width:max-content so the node sizes to the image's natural aspect at the given height.
    // (A plain shrink-to-fit box collapses to a wrong tall/narrow shape when the child img
    // uses percentage height — that distorted the selection box.)
    // Playback nodes are inert (pointer-events:none) so a student can't drag the decoration and it
    // never steals a map pan; editor nodes catch pointers to move/select.
    node.style.cssText = `position:absolute; left:${item.x}%; top:${item.y}%; height:${this._heightPct(item)}%;
      width:max-content; transform:${this._transform(item)}; transform-origin:center;
      ${interact} touch-action:none; user-select:none;`

    const img = document.createElement('img')
    img.src = item.url
    img.alt = item.title
    img.draggable = false
    img.style.cssText = 'height:100%; width:auto; display:block; pointer-events:none; user-select:none;'
    // Depth-of-field blur + opacity preview from the Format panel.
    // Applied to the IMG, not the node, so the selection ring stays crisp.
    if (item.blur > 0) img.style.filter = `blur(${Math.min(2.5, item.blur)}px)`
    if (item.opacity < 1) img.style.opacity = String(Math.max(0.05, item.opacity))
    node.appendChild(img)

    this._wireDrag(item, node)
    return node
  }

  // Drag anywhere on the layer to move it; a press that doesn't move just selects.
  _wireDrag(item, node) {
    node.addEventListener('pointerdown', (e) => {
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
        node.style.height = `${this._heightPct(item)}%`   // scale lives in height, not transform
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
