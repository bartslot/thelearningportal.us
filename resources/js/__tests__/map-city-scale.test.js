import { describe, it, expect } from 'vitest'
import { cityPriority, cityTextSize, cityDotRadius, MAJOR_RANK, REGIONAL_RANK } from '../map-city-scale.js'

/**
 * The bug these guard: a lesson about Java showed Surakarta (rank 4, 555k people) and Bandar Lampung
 * while Jakarta (rank 0, 9.1M) was missing. Both were in the tileset; the map simply never asked
 * which mattered — no sort key, so a collision was won by whichever label MapLibre reached first,
 * and one flat size ramp, so nothing on screen said which was the capital.
 */
describe('city importance', () => {
  /** Walk a MapLibre ['case', cond, a, cond, b, fallback] with a known scalerank. */
  const evaluate = (expr, scalerank) => {
    const [, , ...rest] = expr           // skip 'interpolate', ['linear']
    const atZoom = (z) => {
      const i = rest.indexOf(z)
      return rest[i + 1]
    }
    const pick = (caseExpr) => {
      const [, majorCond, major, regionalCond, regional, local] = caseExpr
      if (scalerank <= majorCond[2]) return major
      if (scalerank <= regionalCond[2]) return regional
      return local
    }
    return { out: pick(atZoom(2)), in: pick(atZoom(6)) }
  }

  it('places the most important city first, so it survives a collision', () => {
    // MapLibre places LOW sort keys first, and a placed symbol cannot be pushed out.
    expect(cityPriority()).toEqual(['to-number', ['coalesce', ['get', 'scalerank'], 99]])
  })

  it('sorts a city with no rank last rather than first', () => {
    const [, coalesce] = cityPriority()

    expect(coalesce[2]).toBe(99)
  })

  it('writes a world city larger than a market town, at every zoom', () => {
    const jakarta = evaluate(cityTextSize(), 0)
    const surakarta = evaluate(cityTextSize(), 4)

    expect(jakarta.out).toBeGreaterThan(surakarta.out)
    expect(jakarta.in).toBeGreaterThan(surakarta.in)
  })

  it('keeps three clear tiers rather than eight near-identical sizes', () => {
    const major = evaluate(cityTextSize(), MAJOR_RANK)
    const regional = evaluate(cityTextSize(), REGIONAL_RANK)
    const local = evaluate(cityTextSize(), 7)

    expect(major.in).toBeGreaterThan(regional.in)
    expect(regional.in).toBeGreaterThan(local.in)
    // A step a projector can actually show.
    expect(major.in - local.in).toBeGreaterThanOrEqual(4)
  })

  it('gives the dot the same hierarchy as the name beside it', () => {
    const jakarta = evaluate(cityDotRadius(), 0)
    const local = evaluate(cityDotRadius(), 7)

    expect(jakarta.in).toBeGreaterThan(local.in)
  })
})
