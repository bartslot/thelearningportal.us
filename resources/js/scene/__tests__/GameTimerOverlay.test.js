import { describe, it, expect, vi } from 'vitest'
import { GameTimerOverlay } from '../GameTimerOverlay.js'

describe('GameTimerOverlay', () => {
  it('renders an 8:40 readout for 520s duration', () => {
    const host = document.createElement('div')
    const o = new GameTimerOverlay(host)
    o.show({ durationSeconds: 520 })
    expect(host.textContent).toContain('8:40')
    expect(host.textContent).toContain('TIME TO COMPLETE THE CHALLENGE')
  })

  it('counts down at 1Hz and emits gameend at 0', () => {
    vi.useFakeTimers()
    const host = document.createElement('div')
    const o = new GameTimerOverlay(host)
    const ended = vi.fn()
    o.on('gameend', ended)
    o.show({ durationSeconds: 2 })
    o.start()
    vi.advanceTimersByTime(1100); expect(host.textContent).toContain('0:01')
    vi.advanceTimersByTime(1100); expect(host.textContent).toContain('0:00')
    expect(ended).toHaveBeenCalledOnce()
    vi.useRealTimers()
  })

  it('hide() removes the overlay markup', () => {
    const host = document.createElement('div')
    const o = new GameTimerOverlay(host)
    o.show({ durationSeconds: 60 })
    o.hide()
    expect(host.innerHTML).toBe('')
  })
})
