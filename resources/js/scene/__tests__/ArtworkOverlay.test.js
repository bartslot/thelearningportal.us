import { describe, it, expect } from 'vitest'
import { ArtworkOverlay } from '../ArtworkOverlay.js'

const host = () => document.createElement('div')
const layer = (extra = {}) => ({ asset_id: 1, url: '/a.png', x: 50, y: 58, scale: 1, height: 40, ...extra })

describe('ArtworkOverlay — resize handles', () => {
  const chrome = (el, id = 'art_1') => el.querySelector(`[data-layer-chrome="${id}"]`)

  it('gives a layer eight handles — four corners and four edges — hidden until selected', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    expect(el.querySelectorAll('[data-scale-handle]')).toHaveLength(8)
    expect(chrome(el).style.display).toBe('none')
  })

  // An edge is the obvious thing to grab, and grabbing one used to do nothing at all.
  it('offers an axis cursor on the edges and a diagonal one on the corners', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    const cursors = [...chrome(el).querySelectorAll('[data-scale-handle]')].map(h => h.style.cursor)
    expect(cursors.filter(c => c === 'ns-resize')).toHaveLength(2)
    expect(cursors.filter(c => c === 'ew-resize')).toHaveLength(2)
    expect(cursors.filter(c => c.endsWith('wse-resize') || c.endsWith('esw-resize'))).toHaveLength(4)
  })

  it('shows the handles on select and hides them again on deselect', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    overlay.select('art_1')
    expect(chrome(el).style.display).toBe('block')

    overlay.select(null)
    expect(chrome(el).style.display).toBe('none')
  })

  // A student has nothing to resize, and a stray handle over a map would be a target to grab.
  it('never renders handles in playback', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el, { readonly: true })
    overlay.setLayers([layer()])

    expect(el.querySelectorAll('[data-scale-handle]')).toHaveLength(0)
  })

  // The handles sit inside the layer node, which is also the drag surface — without the guard a
  // press on a corner would move the layer instead of resizing it.
  it('keeps a press on a handle out of the move gesture', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    const node = el.querySelector('[data-layer-id="art_1"]')
    const handle = chrome(el).querySelector('[data-scale-handle]')
    const before = { left: node.style.left, top: node.style.top }

    handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: 10, clientY: 10 }))
    window.dispatchEvent(new PointerEvent('pointermove', { clientX: 400, clientY: 400 }))
    window.dispatchEvent(new PointerEvent('pointerup', {}))

    expect(node.style.left).toBe(before.left)
    expect(node.style.top).toBe(before.top)
  })
})

describe('ArtworkOverlay — blend mode', () => {
  const node = (el, id = 'art_1') => el.querySelector(`[data-layer-id="${id}"]`)

  // The blend must sit on the NODE. On the <img> it did nothing visible: the node carries a
  // transform, which makes it a stacking context, so the img could only ever blend with the
  // node's own empty backdrop. This is the regression that shipped once already.
  it('puts the blend on the layer node, not the image', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer({ blend: 'multiply' })])

    expect(node(el).style.mixBlendMode).toBe('multiply')
    expect(el.querySelector('img').style.mixBlendMode).toBe('')
  })

  it('leaves an unblended layer alone', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer(), layer({ asset_id: 2, blend: 'normal' })])

    expect(node(el).style.mixBlendMode).toBe('')
    expect(node(el, 'art_2').style.mixBlendMode).toBe('')
  })

  // The blend applies to a node's whole rendered subtree, so chrome inside it would be multiplied
  // into the map and become unusable — white handles vanish over dark satellite imagery.
  it('never puts the ring or handles inside the blended node', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer({ blend: 'multiply' })])

    const n = node(el)
    expect(n.style.mixBlendMode).toBe('multiply')
    expect(n.querySelectorAll('[data-scale-handle]')).toHaveLength(0)

    const ch = el.querySelector('[data-layer-chrome="art_1"]')
    expect(ch.parentElement).toBe(el)                 // sibling of the node, not a child
    expect(ch.style.mixBlendMode).toBe('')
    expect(ch.querySelectorAll('[data-scale-handle]')).toHaveLength(8)
  })

  it('blends in playback too, or the editor would be lying about the result', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el, { readonly: true })
    overlay.setLayers([layer({ blend: 'screen' })])

    expect(node(el).style.mixBlendMode).toBe('screen')
  })

  // A z-index on the host makes it a stacking context, which is exactly what stopped the blend
  // reaching the map. The level belongs on the nodes so the host stays transparent to stacking.
  it('stacks the nodes and never the host, so a blend can reach what is behind it', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setStackLevels(6, 8)
    overlay.setLayers([layer({ blend: 'multiply' })])

    expect(el.style.zIndex).toBe('')
    expect(node(el).style.zIndex).toBe('6')

    overlay.setOnTop(true)
    expect(node(el).style.zIndex).toBe('8')
    expect(el.style.zIndex).toBe('')
  })
})

// A layer over a map belongs to a PLACE, not to a pixel. Positions come from the projector on
// every map move, and the place — not the position — is what gets saved.
describe('ArtworkOverlay — pinned to the map', () => {
  const node = (el, id = 'art_1') => el.querySelector(`[data-layer-id="${id}"]`)

  // A stand-in for mapTextProjector: one degree of longitude = one percent of the host.
  const projector = (originLng = 0, originLat = 0) => ({
    project: (lng, lat) => ({ x: (lng - originLng) + 50, y: (lat - originLat) + 50 }),
    unproject: (x, y) => ({ lng: (x - 50) + originLng, lat: (y - 50) + originLat }),
  })

  const pinned = (extra = {}) => layer({ anchor: 'map', lng: 10, lat: -20, x: 0, y: 0, ...extra })

  it('places a pinned layer from its lng/lat, ignoring the stored x/y', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setProjector(projector())
    overlay.setLayers([pinned()])

    expect(node(el).style.left).toBe('60%')
    expect(node(el).style.top).toBe('30%')
  })

  it('falls back to the stored x/y when no map is under it', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([pinned({ x: 12, y: 34 })])

    expect(node(el).style.left).toBe('12%')
    expect(node(el).style.top).toBe('34%')
  })

  it('repositions pinned layers on a map move and leaves screen layers alone', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setProjector(projector())
    overlay.setLayers([pinned(), layer({ asset_id: 2, x: 25, y: 75 })])

    overlay.setProjector(projector(5, 5))   // the camera panned
    overlay.refreshPositions()

    expect(el.querySelector('[data-layer-id="art_1"]').style.left).toBe('55%')
    expect(el.querySelector('[data-layer-id="art_2"]').style.left).toBe('25%')
  })

  it('saves the PLACE a pinned layer was dragged to, not just the position', () => {
    const el = host()
    const saved = []
    const overlay = new ArtworkOverlay(el, { onChange: (id, t) => saved.push({ id, ...t }) })
    overlay.setProjector(projector())
    overlay.setLayers([pinned()])

    const item = overlay._layers[0]
    item.x = 70; item.y = 20               // as a drag would leave it
    overlay._emit(item)

    expect(saved[0]).toMatchObject({ id: 1, anchor: 'map', lng: 20, lat: -30 })
  })

  it('pins a screen layer to what it is sitting over, and releases it again', () => {
    const el = host()
    const saved = []
    const overlay = new ArtworkOverlay(el, { onChange: (id, t) => saved.push(t) })
    overlay.setProjector(projector())
    overlay.setLayers([layer({ x: 65, y: 40 })])

    expect(overlay.togglePin(1)).toBe(true)
    expect(saved.at(-1)).toMatchObject({ anchor: 'map', lng: 15, lat: -10 })

    expect(overlay.togglePin(1)).toBe(false)
    expect(saved.at(-1)).toMatchObject({ anchor: 'screen', lng: null, lat: null })
  })

  it('refuses to pin when there is no map to pin to', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    expect(overlay.canPin()).toBe(false)
    expect(overlay.togglePin(1)).toBe(false)
  })
})


// The Format panel saves on `change` — which for a colour picker means "when the picker closes"
// and for a slider means "on release". These keep the canvas following the drag itself.
describe('ArtworkOverlay — live preview while dragging a control', () => {
  const node = (el, id = 'art_1') => el.querySelector(`[data-layer-id="${id}"]`)

  it('repaints the tint without waiting for the picker to close', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer({ white_key: 0.04, tint: '#ff0000' })])

    overlay.setLayerProp(1, 'tint', '#00ff00')

    // A duotone carries the colour as matrix coefficients, not as a hex literal: pure green is
    // red 0, green 1, blue 0.
    expect(el.querySelector('filter').innerHTML).toContain('0 0 0 0 0 0 1 0 0 0 0 0 0 0 0')
  })

  it('applies a blend change immediately', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    overlay.setLayerProp(1, 'blend', 'multiply')
    expect(node(el).style.mixBlendMode).toBe('multiply')

    overlay.setLayerProp(1, 'blend', 'normal')
    expect(node(el).style.mixBlendMode).toBe('')
  })

  it('moves and resizes live, keeping the chrome on the layer', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    overlay.setLayerProp(1, 'x', 20)
    overlay.setLayerProp(1, 'scale', 2)

    expect(node(el).style.left).toBe('20%')
    expect(node(el).style.height).toBe('80%')   // height 40 × scale 2
  })

  // It previews only. Persisting is the panel's `change` handler, so letting go of a control
  // without committing must not have written anything.
  it('never saves — that is the change handler is job', () => {
    const el = host()
    const saved = []
    const overlay = new ArtworkOverlay(el, { onChange: (id, t) => saved.push([id, t]) })
    overlay.setLayers([layer()])

    overlay.setLayerProp(1, 'x', 20)

    expect(saved).toHaveLength(0)
  })

  it('ignores a layer that is not on the scene', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    expect(() => overlay.setLayerProp(999, 'tint', '#000000')).not.toThrow()
  })
})
