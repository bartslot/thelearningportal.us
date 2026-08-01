import { describe, it, expect } from 'vitest'
import { ArtworkOverlay } from '../ArtworkOverlay.js'

const host = () => document.createElement('div')
const layer = (extra = {}) => ({ asset_id: 1, url: '/a.png', x: 50, y: 58, scale: 1, height: 40, ...extra })

describe('ArtworkOverlay — resize handles', () => {
  it('gives a layer four corner handles, hidden until it is selected', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    const handles = el.querySelectorAll('[data-scale-handle]')
    expect(handles).toHaveLength(4)
    for (const h of handles) expect(h.style.display).toBe('none')
  })

  it('shows the handles on select and hides them again on deselect', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer()])

    overlay.select('art_1')
    for (const h of el.querySelectorAll('[data-scale-handle]')) expect(h.style.display).toBe('block')

    overlay.select(null)
    for (const h of el.querySelectorAll('[data-scale-handle]')) expect(h.style.display).toBe('none')
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
    const handle = node.querySelector('[data-scale-handle]')
    const before = { left: node.style.left, top: node.style.top }

    handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: 10, clientY: 10 }))
    window.dispatchEvent(new PointerEvent('pointermove', { clientX: 400, clientY: 400 }))
    window.dispatchEvent(new PointerEvent('pointerup', {}))

    expect(node.style.left).toBe(before.left)
    expect(node.style.top).toBe(before.top)
  })
})

describe('ArtworkOverlay — blend mode', () => {
  it('applies the blend mode to the image so the layer mixes with the scene', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer({ blend: 'multiply' })])

    expect(el.querySelector('img').style.mixBlendMode).toBe('multiply')
  })

  it('leaves an unblended layer alone', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el)
    overlay.setLayers([layer(), layer({ asset_id: 2, blend: 'normal' })])

    for (const img of el.querySelectorAll('img')) expect(img.style.mixBlendMode).toBe('')
  })

  it('blends in playback too, or the editor would be lying about the result', () => {
    const el = host()
    const overlay = new ArtworkOverlay(el, { readonly: true })
    overlay.setLayers([layer({ blend: 'screen' })])

    expect(el.querySelector('img').style.mixBlendMode).toBe('screen')
  })
})
