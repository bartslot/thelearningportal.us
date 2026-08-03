import { describe, it, expect } from 'vitest'
import { filterMarkup, hasTreatment, applyLayerFilter, layerFilterId } from '../layer-filters.js'

describe('layer colour treatment', () => {
  it('asks for nothing when the layer is untreated', () => {
    expect(hasTreatment({})).toBe(false)
    expect(filterMarkup({})).toBeNull()
  })

  // The whole point of the white key: paper becomes transparent, ink does not. The alpha row
  // subtracts luminance from 1, so white (1,1,1) lands on 0 and black on 1.
  it('turns luminance into alpha so white keys out and dark ink survives', () => {
    const svg = filterMarkup({ white_key: 0.04 })

    expect(svg).toContain('feColorMatrix type="matrix"')
    expect(svg).toContain('-0.2126 -0.7152 -0.0722 0 1')
  })

  // Tolerance is what stops a 4% key also eating light greys: the alpha ramp is stretched by
  // 1/tolerance so anything below the band saturates to fully opaque.
  it('stretches the alpha ramp by the tolerance', () => {
    expect(filterMarkup({ white_key: 0.04 })).toContain('slope="25.000"')
    expect(filterMarkup({ white_key: 0.5 })).toContain('slope="2.000"')
  })

  it('drains colour before anything else when grayscale is on', () => {
    const svg = filterMarkup({ grayscale: true, white_key: 0.04 })

    expect(svg.indexOf('saturate')).toBeLessThan(svg.indexOf('feComponentTransfer'))
  })

  it('floods the tint through the surviving alpha, not over the whole image', () => {
    const svg = filterMarkup({ white_key: 0.04, tint: '#38bdf8' })

    expect(svg).toContain('flood-color="#38bdf8"')
    expect(svg).toContain('operator="in"')
    // With a key in play the flood must ride the KEYED alpha — compositing against SourceGraphic
    // would paint the paper back in.
    expect(svg).not.toContain('in2="SourceGraphic"')
  })

  it('tints an unkeyed layer against the source', () => {
    expect(filterMarkup({ tint: '#ff0000' })).toContain('in2="SourceGraphic"')
  })
})

describe('applyLayerFilter', () => {
  const host = () => document.createElement('div')

  it('returns just the blur when there is no treatment, and adds no svg', () => {
    const el = host()

    expect(applyLayerFilter(el, { asset_id: 1, blur: 2 })).toBe('blur(2px)')
    expect(el.querySelector('svg')).toBeNull()
  })

  it('mounts one filter per layer and composes it with the blur', () => {
    const el = host()
    const css = applyLayerFilter(el, { asset_id: 7, white_key: 0.04, blur: 1 })

    expect(css).toBe(`url(#${layerFilterId(7)}) blur(1px)`)
    expect(el.querySelector(`#${layerFilterId(7)}`)).not.toBeNull()
  })

  // Re-rendering the same layer must not stack a second <svg> on every poll.
  it('reuses the carrier instead of piling them up', () => {
    const el = host()
    applyLayerFilter(el, { asset_id: 7, white_key: 0.04 })
    applyLayerFilter(el, { asset_id: 7, white_key: 0.2 })

    expect(el.querySelectorAll('svg')).toHaveLength(1)
    expect(el.querySelector('filter').innerHTML).toContain('slope="5.000"')
  })

  it('removes the filter when the teacher clears the treatment', () => {
    const el = host()
    applyLayerFilter(el, { asset_id: 7, white_key: 0.04 })
    const css = applyLayerFilter(el, { asset_id: 7, white_key: 0 })

    expect(css).toBe('')
    expect(el.querySelector('svg')).toBeNull()
  })
})
