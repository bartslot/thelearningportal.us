import { describe, it, expect } from 'vitest'
import { ArtworkOverlay } from '../ArtworkOverlay.js'

const host = () => document.createElement('div')
const layer = (extra = {}) => ({ asset_id: 1, url: '/a.png', x: 50, y: 58, scale: 1, height: 40, ...extra })

describe('ArtworkOverlay — resize handles', () => {
  const chrome = (el, id = 'art_1') => el.querySelector(`[data-layer-chrome="${id}"]`)

  it('gives a layer four corner handles, hidden until it is selected', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    expect(el.querySelectorAll('[data-scale-handle]')).toHaveLength(4)
    expect(chrome(el).style.display).toBe('none')
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
    expect(ch.querySelectorAll('[data-scale-handle]')).toHaveLength(4)
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

// Black ink disappears on a night scene, so an icon can be repainted in a colour of its own.
describe('ArtworkOverlay — tint', () => {
  const node = (el, id = 'art_1') => el.querySelector(`[data-layer-id="${id}"]`)

  it('leaves the artwork alone by default', () => {
    const el = host()
    new ArtworkOverlay(el).setLayers([layer()])

    expect(node(el).querySelector('img').style.visibility).toBe('')
    expect(node(el).querySelector('div[style*="mask"]')).toBeNull()
  })

  it('paints the colour through the artwork’s own shape, keeping its box', () => {
    const el = host()
    new ArtworkOverlay(el).setLayers([layer({ tint: '#e11d48' })])

    const img = node(el).querySelector('img')
    const paint = node(el).querySelector('div[style*="mask"]')

    expect(img.style.visibility).toBe('hidden')   // still sizes the node, no longer seen
    expect(paint.style.background).toBe('rgb(225, 29, 72)')
    expect(paint.style.getPropertyValue('mask')).toContain('/a.png')
  })
})
