import { describe, it, expect } from 'vitest'
import { AmplitudeWaveform } from '../AmplitudeWaveform.js'

describe('AmplitudeWaveform', () => {
  it('returns N normalized bar heights from an alignment array', () => {
    const alignment = Array.from({ length: 100 }, (_, i) => ({
      character: 'a', start: i * 0.05, end: i * 0.05 + 0.05,
    }))

    const bars = AmplitudeWaveform.computeBars(alignment, 10)

    expect(bars).toHaveLength(10)
    bars.forEach(h => {
      expect(h).toBeGreaterThanOrEqual(0)
      expect(h).toBeLessThanOrEqual(1)
    })
  })

  it('returns an array of zeros when alignment is empty', () => {
    const bars = AmplitudeWaveform.computeBars([], 8)
    expect(bars).toEqual([0, 0, 0, 0, 0, 0, 0, 0])
  })

  it('renders 8 spans into a host element', () => {
    const host = document.createElement('div')
    AmplitudeWaveform.render(host, [{ character: 'a', start: 0, end: 1 }], 8)
    expect(host.querySelectorAll('span')).toHaveLength(8)
  })
})
