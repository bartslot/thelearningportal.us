import { describe, it, expect, vi } from 'vitest'
import { TextOverlayLayer } from '../TextOverlayLayer.js'

const host = () => document.createElement('div')

describe('TextOverlayLayer (readonly / player)', () => {
  it('renders label text as plain text, never as markup', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([{ id: 'a', text: '<img src=x onerror=alert(1)>', x: 10, y: 10 }])

    const node = el.querySelector('[data-text-id="a"]')
    expect(node.textContent).toBe('<img src=x onerror=alert(1)>')
    expect(node.querySelector('img')).toBeNull()
  })

  it('renders a URL text as a link chip labelled with the hostname', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([{ id: 'a', text: 'https://www.wikipedia.org/wiki/Napoleon', x: 10, y: 10 }])

    const chip = el.querySelector('[data-text-id="a"] button')
    expect(chip).not.toBeNull()
    expect(chip.textContent).toContain('wikipedia.org')
    expect(chip.textContent).not.toContain('https://')
  })

  // NOTE: jsdom drops clamp() font-size declarations, so size is asserted indirectly
  // through the font-weight that travels with each font entry.
  it('falls back to the default font for unknown keys', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([{ id: 'a', text: 'Rome', x: 10, y: 10, font: 'comic', size: 'gigantic' }])

    const node = el.querySelector('[data-text-id="a"]')
    expect(node.style.fontFamily).toContain('Inter')
    expect(node.style.fontWeight).toBe('600') // sans default
  })

  it('applies the requested font when valid', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([{ id: 'a', text: 'Rome', x: 10, y: 10, font: 'cinzel', size: 'xl' }])

    const node = el.querySelector('[data-text-id="a"]')
    expect(node.style.fontFamily).toContain('Cinzel')
    expect(node.style.fontWeight).toBe('700')
  })

  it('drops malformed rows instead of crashing', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([null, 42, { id: 'x' }, { id: 'a', text: 'kept', x: 1, y: 1 }])

    expect(el.children.length).toBe(1)
    expect(el.textContent).toBe('kept')
  })
})

describe('TextOverlayLayer map pinning', () => {
  const projector = (result) => ({ project: vi.fn(() => result), unproject: vi.fn() })

  it('repositions map-anchored labels through the projector on refresh', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([
      { id: 'pinned', text: 'Rome', x: 10, y: 10, anchor: 'map', lng: 12.5, lat: 41.9 },
      { id: 'stuck', text: 'Title', x: 20, y: 20, anchor: 'screen' },
    ])
    layer.setProjector(projector({ x: 55, y: 66 }))

    const pinned = el.querySelector('[data-text-id="pinned"]')
    const stuck = el.querySelector('[data-text-id="stuck"]')
    expect(pinned.style.left).toBe('55%')
    expect(pinned.style.top).toBe('66%')
    expect(stuck.style.left).toBe('20%')
    expect(stuck.style.top).toBe('20%')
  })

  it('keeps the stored screen position when the projector returns null (off-view)', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([{ id: 'a', text: 'Rome', x: 10, y: 10, anchor: 'map', lng: 12.5, lat: 41.9 }])
    layer.setProjector(projector(null))

    const node = el.querySelector('[data-text-id="a"]')
    expect(node.style.left).toBe('10%')
    expect(node.style.top).toBe('10%')
  })

  it('ignores map-anchored labels with non-finite coordinates', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    const proj = projector({ x: 99, y: 99 })
    layer.setTexts([{ id: 'a', text: 'Rome', x: 10, y: 10, anchor: 'map', lng: 'east', lat: null }])
    layer.setProjector(proj)

    expect(proj.project).not.toHaveBeenCalled()
    expect(el.querySelector('[data-text-id="a"]').style.left).toBe('10%')
  })

  it('does nothing on refresh without a projector (labels fall back to x/y)', () => {
    const el = host()
    const layer = new TextOverlayLayer(el)
    layer.setTexts([{ id: 'a', text: 'Rome', x: 30, y: 40, anchor: 'map', lng: 12.5, lat: 41.9 }])
    layer.refreshPositions()

    const node = el.querySelector('[data-text-id="a"]')
    expect(node.style.left).toBe('30%')
    expect(node.style.top).toBe('40%')
  })
})
