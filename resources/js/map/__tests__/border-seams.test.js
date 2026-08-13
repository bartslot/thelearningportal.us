import { describe, it, expect } from 'vitest'
import { withoutSeams } from '../border-sweep.js'

/**
 * The hover glow outlines geometry from queryRenderedFeatures, which returns each polity CLIPPED TO
 * ITS TILE. The ring therefore includes the tile's own edge, and drawing it puts a bright straight
 * line across the country — reported on England, France and Spain in turn.
 *
 * The fixture below is not invented. These are the three offending segments measured off the Iberian
 * Union's live geometry at z4, which is why the numbers are ugly: the tile buffer means the clip sits
 * at longitude 0.439 rather than 0, and latitude 40.647 rather than the 40.979 the boundary is at.
 */
describe('withoutSeams', () => {
  /** Iberia, with the real clip edges in place. */
  const iberia = [
    [-9.2, 43.0], [-8.5, 43.3], [-7.174, 43.537],
    [-5.647, 43.537],                                  // 1.53° along a parallel — a seam
    [-3.0, 43.4], [0.439, 42.739],
    [0.439, 40.647],                                   // 2.09° along a meridian — a seam
    [-8.74, 40.647],                                   // 9.18° along a parallel — a seam
    [-9.0, 41.5], [-9.2, 43.0],
  ]

  it('removes all three tile edges and keeps the coastline between them', () => {
    const runs = withoutSeams(iberia)
    const kept = runs.flatMap((r) => r.slice(1).map((p, i) => [r[i], p]))

    const stillThere = kept.filter(([a, b]) => {
      const dx = Math.abs(b[0] - a[0])
      const dy = Math.abs(b[1] - a[1])
      return (dx < 1e-4 || dy < 1e-4) && Math.hypot(dx, dy) > 0.5
    })
    expect(stillThere, 'a tile edge survived').toEqual([])

    // And it has not simply thrown the ring away: the real coast is still drawn.
    expect(runs.flat().length).toBeGreaterThan(3)
  })

  it('keeps a normal wiggly border whole', () => {
    const coast = [[0, 0], [0.1, 0.12], [0.22, 0.19], [0.3, 0.33], [0.45, 0.4]]
    expect(withoutSeams(coast)).toEqual([coast])
  })

  it('keeps a SHORT axis-aligned run, which is ordinary border detail', () => {
    // Simplified coastline is full of little flat steps. Only a sustained one is a clip.
    const stepped = [[0, 0], [0.2, 0], [0.2, 0.2], [0.4, 0.2]]
    expect(withoutSeams(stepped)).toEqual([stepped])
  })

  it('splits a long parallel-following border too, and that is the accepted cost', () => {
    // The 49th between the US and Canada is genuinely straight. It loses its glow along that
    // stretch. A gap in a hover effect is a smaller lie than a bright line through a country.
    const fortyNinth = [[-120, 49], [-110, 49], [-100, 49]]
    expect(withoutSeams(fortyNinth)).toEqual([])
  })

  it('does not read the antimeridian as a jump', () => {
    // 179.9 to -179.9 is 0.2 degrees apart on the globe and 359.8 apart in the numbers.
    const dateline = [[179.8, 10], [179.9, 10.1], [-179.9, 10.2], [-179.8, 10.3]]
    expect(withoutSeams(dateline)).toEqual([dateline])
  })

  it('returns nothing for a ring too short to draw', () => {
    expect(withoutSeams([[0, 0]])).toEqual([])
    expect(withoutSeams([])).toEqual([])
  })
})
