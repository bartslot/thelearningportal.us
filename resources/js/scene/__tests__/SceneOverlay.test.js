import { describe, it, expect } from 'vitest'
import { SceneOverlay } from '../SceneOverlay.js'

describe('SceneOverlay', () => {
  it('mounts year and location text into the host element', () => {
    const host = document.createElement('div')
    const overlay = new SceneOverlay(host)
    overlay.mount()
    overlay.update({ year: '1810', location: 'PARIS, FRANCE' })

    expect(host.querySelector('[data-year]').textContent).toBe('1810')
    expect(host.querySelector('[data-location]').textContent).toBe('PARIS, FRANCE')
    expect(host.querySelector('svg')).not.toBeNull()
  })

  it('uppercases the location', () => {
    const host = document.createElement('div')
    const overlay = new SceneOverlay(host)
    overlay.mount()
    overlay.update({ year: '1810', location: 'paris, france' })
    expect(host.querySelector('[data-location]').textContent).toBe('PARIS, FRANCE')
  })

  it('hides year badge when year is null', () => {
    const host = document.createElement('div')
    const overlay = new SceneOverlay(host)
    overlay.mount()
    overlay.update({ year: null, location: 'X' })

    const badge = host.querySelector('.scene-overlay__year')
    expect(badge.style.opacity).toBe('0')
  })
})
