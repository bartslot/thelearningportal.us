export const AmplitudeWaveform = {
  computeBars(alignment, bucketCount) {
    if (!Array.isArray(alignment) || alignment.length === 0) {
      return Array(bucketCount).fill(0)
    }
    const total = alignment[alignment.length - 1].end || 1
    const buckets = Array(bucketCount).fill(0)

    for (const c of alignment) {
      const mid = (c.start + c.end) / 2
      const idx = Math.min(bucketCount - 1, Math.floor((mid / total) * bucketCount))
      buckets[idx] += 1
    }

    const max = Math.max(...buckets) || 1
    return buckets.map(v => v / max)
  },

  render(hostEl, alignment, bucketCount = 24) {
    const bars = this.computeBars(alignment, bucketCount)
    hostEl.innerHTML = ''
    for (const h of bars) {
      const span = document.createElement('span')
      span.style.display    = 'inline-block'
      span.style.width      = `${100 / bucketCount}%`
      span.style.height     = `${Math.max(2, Math.round(h * 100))}%`
      span.style.background = 'currentColor'
      hostEl.appendChild(span)
    }
  },
}
