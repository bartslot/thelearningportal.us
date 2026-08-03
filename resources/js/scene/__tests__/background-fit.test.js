import { describe, it, expect } from 'vitest'
import { isPortraitShape, isTopAnchored, normalizeFit, PORTRAIT_ASPECT } from '../background-fit.js'

describe('normalizeFit', () => {
  it('only ever returns one of the two supported fits', () => {
    expect(normalizeFit('contain')).toBe('contain')
    expect(normalizeFit('cover')).toBe('cover')
    expect(normalizeFit('stretch')).toBe('cover')
    expect(normalizeFit(undefined)).toBe('cover')
    expect(normalizeFit(null)).toBe('cover')
  })
})

describe('isPortraitShape', () => {
  it('catches an image meaningfully taller than it is wide', () => {
    // The real case: Antonis Mor's full-length Philip II, which the corpus tags as a battle scene.
    expect(isPortraitShape(736, 1475)).toBe(true)
    expect(isPortraitShape(2000, 3064)).toBe(true)
  })

  it('leaves landscape and square-ish images alone', () => {
    expect(isPortraitShape(3840, 2408)).toBe(false)
    expect(isPortraitShape(1000, 1000)).toBe(false)
    expect(isPortraitShape(1000, 1040)).toBe(false)   // inside the square-ish band
  })

  it('is safe on missing or zero dimensions', () => {
    expect(isPortraitShape(0, 1400)).toBe(false)
    expect(isPortraitShape(undefined, undefined)).toBe(false)
    expect(isPortraitShape(900, 0)).toBe(false)
  })

  it('uses the same threshold the PHP side does', () => {
    expect(PORTRAIT_ASPECT).toBe(1.05)
  })
})

describe('isTopAnchored', () => {
  it('leaves a TALL image centred when nothing says there is a face in it', () => {
    // The regression this replaces: a tall engraving of a ship anchored top, so the stage showed
    // mast and flag and cropped the ship away. Tall is not evidence of a face.
    expect(isTopAnchored('cover', 'center', 736, 1475)).toBe(false)
  })

  it('honours a stored top hint for a WIDE image, which shape can never detect', () => {
    // A landscape canvas titled "Portrait of…" — only the hint knows the subject sits high.
    expect(isTopAnchored('cover', 'top', 1600, 900)).toBe(true)
  })

  it('leaves a landscape image centred', () => {
    expect(isTopAnchored('cover', 'center', 3840, 2408)).toBe(false)
  })

  it('never anchors a contained image, because nothing is cropped', () => {
    expect(isTopAnchored('contain', 'top', 736, 1475)).toBe(false)
    expect(isTopAnchored('contain', 'center', 736, 1475)).toBe(false)
  })

  it('needs no dimensions at all — the hint is the whole decision', () => {
    expect(isTopAnchored('cover', 'top', 0, 0)).toBe(true)
    expect(isTopAnchored('cover', 'center', 0, 0)).toBe(false)
  })
})
