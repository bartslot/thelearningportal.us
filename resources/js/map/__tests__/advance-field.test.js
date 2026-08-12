import { describe, it, expect } from 'vitest'
import { smin, frontRadius, buildArrivalField, paintFrame } from '../advance-field.js'

describe('frontRadius', () => {
  it('follows √t, so the front decelerates the way spreading liquid does', () => {
    // The absolute that matters: at a QUARTER of the time a √t front is HALF way, not a quarter.
    // This is the difference between liquid and a radar sweep, and it is checkable by hand.
    expect(frontRadius(2.5, 10, 800)).toBeCloseTo(400, 6)
    expect(frontRadius(10, 10, 800)).toBeCloseTo(800, 6)
    expect(frontRadius(0, 10, 800)).toBe(0)
  })

  it('is linear at curve 1, which is what it should NOT look like', () => {
    expect(frontRadius(2.5, 10, 800, 1)).toBeCloseTo(200, 6)
  })

  it('never overshoots once the duration has passed', () => {
    expect(frontRadius(999, 10, 800)).toBeCloseTo(800, 6)
  })
})

describe('smin', () => {
  it('fuses two equal fronts BELOW either of them — the neck that reads as one substance', () => {
    // Where two fronts meet head-on, plain min leaves a crease at exactly their shared value.
    // smin dips below it, which is the rounded join.
    expect(smin(100, 100, 60)).toBeLessThan(100)
    expect(Math.min(100, 100)).toBe(100)
  })

  it('degrades to a plain minimum when the blend width is zero', () => {
    expect(smin(100, 250, 0)).toBe(100)
  })

  it('leaves distant fronts alone, so a far seed does not drag the near one in', () => {
    expect(smin(10, 5000, 60)).toBeCloseTo(10, 3)
  })
})

describe('buildArrivalField', () => {
  const bbox = [14, 49, 24, 55] // roughly Poland
  const full = () => true

  it('reaches a seed first and the far corner last', () => {
    const width = 40
    const height = 24
    const { field } = buildArrivalField({
      width, height, bbox, inside: full, seeds: [[14.2, 54.8]], lobeAmount: 0,
    })
    const at = (px, py) => field[py * width + px]
    expect(at(0, 0)).toBeLessThan(at(width - 1, height - 1))
  })

  it('never lets the advance leave the target — outside the mask is unreachable', () => {
    const width = 30
    const height = 20
    // Only the left half is "in the country".
    const { field, maxKm } = buildArrivalField({
      width, height, bbox, inside: (px) => px < width / 2, seeds: [[14.2, 54.8]],
    })
    for (let py = 0; py < height; py++) {
      for (let px = Math.floor(width / 2); px < width; px++) {
        expect(field[py * width + px]).toBe(Infinity)
      }
    }
    expect(Number.isFinite(maxKm)).toBe(true)
  })

  it('measures real kilometres — checked against a distance worked out by hand', () => {
    // The absolute. A degree of longitude at 52°N is 111.32 × cos(52°) ≈ 68.5 km, against 111.32 km
    // for a degree of latitude. Get this wrong and every blob comes out stretched east-west, which
    // is invisible on a small country and obvious on a wide one — the kind of uniform error a
    // relative test can never see, because it satisfies every comparison equally.
    const width = 100
    const height = 100
    const square = [10, 47, 20, 57] // centred on 52°N
    const { field } = buildArrivalField({
      width, height, bbox: square, inside: full, seeds: [[15, 52]], lobeAmount: 0, mergeKm: 0,
    })
    const lngAt = (px) => 10 + (10 * (px + 0.5)) / width
    const latAt = (py) => 57 - (10 * (py + 0.5)) / height
    const kmPerLng = 111.32 * Math.cos((52 * Math.PI) / 180)

    const east = Math.hypot((lngAt(60) - 15) * kmPerLng, (52 - latAt(50)) * 111.32)
    expect(field[50 * width + 60]).toBeCloseTo(east, 3)
    expect(east).toBeCloseTo(72.2, 1) // ≈ 1.05° of longitude up here, in km

    // And the same offset north is genuinely farther away than east.
    expect(field[50 * width + 60]).toBeLessThan(field[40 * width + 50])
  })

  it('warps the front both ways, so it bulges as well as lags', () => {
    const width = 60
    const height = 60
    const box = [10, 47, 20, 57]
    const seeds = [[15, 52]]
    const straight = buildArrivalField({ width, height, bbox: box, inside: full, seeds, lobeAmount: 0 })
    const lobed = buildArrivalField({ width, height, bbox: box, inside: full, seeds, lobeAmount: 60 })

    let ahead = 0
    let behind = 0
    let biggest = 0
    for (let i = 0; i < straight.field.length; i++) {
      const delta = lobed.field[i] - straight.field[i]
      if (delta < -1) ahead++
      if (delta > 1) behind++
      biggest = Math.max(biggest, Math.abs(delta))
    }
    // A one-sided warp only ever delays the front, which reads as erosion rather than as lobes.
    expect(ahead, 'the front never runs ahead of the circle').toBeGreaterThan(0)
    expect(behind, 'the front never lags the circle').toBeGreaterThan(0)
    expect(biggest).toBeGreaterThan(20)
  })
})

describe('paintFrame', () => {
  const field = Float32Array.from([0, 50, 100, Infinity])
  const rgba = new Uint8ClampedArray(field.length * 4)
  const opts = { body: [150, 20, 20], rim: [90, 0, 0], rimKm: 40, opacity: 1, featherKm: 0 }

  it('leaves everything the front has not reached fully transparent', () => {
    paintFrame(rgba, field, { ...opts, radiusKm: 60 })
    expect(rgba[2 * 4 + 3]).toBe(0)   // 100 km away, front is at 60
    expect(rgba[3 * 4 + 3]).toBe(0)   // outside the country entirely
    expect(rgba[0 * 4 + 3]).toBeGreaterThan(0)
  })

  it('darkens the leading edge and leaves the covered ground the body colour', () => {
    paintFrame(rgba, field, { ...opts, radiusKm: 50 })
    // Pixel 1 is exactly at the front → rim colour. Pixel 0 is 50 km behind it → body colour.
    expect(rgba[1 * 4]).toBe(90)
    expect(rgba[0 * 4]).toBe(150)
  })

  it('makes the rim denser as well as darker, or it reads as a colour change', () => {
    paintFrame(rgba, field, { ...opts, radiusKm: 50, opacity: 0.5 })
    expect(rgba[1 * 4 + 3]).toBeGreaterThan(rgba[0 * 4 + 3])
  })
})

describe('spanKm', () => {
  it('ignores a far outlier, which is what stops the advance finishing a quarter of the way in', () => {
    // A country of near pixels plus one distant sliver — an enclave, or a scrap of a coastal tile.
    const width = 40
    const height = 40
    const bbox = [0, 0, 10, 10]
    const { maxKm, spanKm } = buildArrivalField({
      width, height, bbox, lobeAmount: 0, seeds: [[0.2, 9.8]],
      // The bulk of the country in one corner, plus a single pixel in the far one. It has to be a
      // genuine outlier to be discarded — 1 in 900 here, well under the 0.5% the percentile drops.
      inside: (px, py) => (px < 30 && py < 30) || (px === width - 1 && py === height - 1),
    })
    // It settles on the bulk's own extent rather than the stray pixel's, so the animation is still
    // moving when the duration runs out instead of finishing early and waiting.
    expect(spanKm).toBeLessThan(maxKm * 0.8)
    // But it does not undershoot the bulk either — that would cut the advance short of the country.
    expect(spanKm).toBeGreaterThan(maxKm * 0.5)
  })

  it('equals the maximum when the ground is evenly spread', () => {
    const { maxKm, spanKm } = buildArrivalField({
      width: 40, height: 40, bbox: [0, 0, 10, 10], inside: () => true, seeds: [[0.2, 9.8]], lobeAmount: 0,
    })
    expect(spanKm).toBeGreaterThan(maxKm * 0.9)
  })
})
