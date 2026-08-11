import { describe, it, expect } from 'vitest'
import { parseColour } from '../tuner.js'

/**
 * The settings panel's colour field accepts typed text, so it parses whatever a human pastes in.
 *
 * The rule that matters is the LAST one: a half-typed hex must not resolve. The field applies on
 * every keystroke, so if `#1` parsed as black, typing `#193d67` would flash the map through black
 * on the way — and someone comparing two shades would be comparing them against a flicker.
 */
describe('parseColour', () => {
  const NONE = null

  it('reads the three-digit shorthand', () => {
    expect(parseColour('#fff')).toEqual({ rgb: [255, 255, 255], a: 1 })
    expect(parseColour('#08f')).toEqual({ rgb: [0, 136, 255], a: 1 })
  })

  it('reads six-digit hex', () => {
    expect(parseColour('#193d67')).toEqual({ rgb: [25, 61, 103], a: 1 })
  })

  it('reads eight-digit hex as colour plus alpha', () => {
    const { rgb, a } = parseColour('#193d67cc')
    expect(rgb).toEqual([25, 61, 103])
    expect(a).toBeCloseTo(0.8, 2)
  })

  it('reads rgb() and rgba(), which is what it emits below full alpha', () => {
    expect(parseColour('rgb(25, 61, 103)')).toEqual({ rgb: [25, 61, 103], a: 1 })
    expect(parseColour('rgba(25, 61, 103, 0.35)')).toEqual({ rgb: [25, 61, 103], a: 0.35 })
  })

  it('round-trips its own output, so a copied value can be pasted back', () => {
    const emitted = 'rgba(25, 61, 103, 0.35)'
    expect(parseColour(emitted)).toEqual({ rgb: [25, 61, 103], a: 0.35 })
  })

  it('is case-insensitive and tolerates surrounding space', () => {
    expect(parseColour('  #19D3F7  ')).toEqual({ rgb: [25, 211, 247], a: 1 })
  })

  it('clamps components that are numbers, and refuses ones that are not colours', () => {
    expect(parseColour('rgba(300, 5, 61, 4)')).toEqual({ rgb: [255, 5, 61], a: 1 })
    // Negative components are not valid CSS, so this is refused outright rather than clamped.
    expect(parseColour('rgba(25, -5, 61, 1)', NONE)).toBe(NONE)
  })

  it('refuses a half-typed hex instead of resolving it to black', () => {
    // The field applies per keystroke, so every one of these is a state `#193d67` passes through.
    for (const partial of ['#', '#1', '#19', '#193d6']) {
      expect(parseColour(partial, NONE)).toBe(NONE)
    }
  })

  it('accepts the lengths CSS actually defines, including four-digit with alpha', () => {
    // Worth stating because it is a real consequence: 3 and 4 digits are both legal, so typing a
    // six-digit hex passes through TWO valid intermediate colours before reaching the one you want.
    // That is CSS, not a parser bug — the alternative would be refusing shorthand people do type.
    expect(parseColour('#193', NONE)).toEqual({ rgb: [17, 153, 51], a: 1 })
    const four = parseColour('#193d', NONE)
    expect(four.rgb).toEqual([17, 153, 51])
    expect(four.a).toBeCloseTo(0.867, 2)
  })

  it('refuses nonsense and empty input', () => {
    for (const bad of ['', '   ', 'nonsense', '#gggggg', 'rgb(1,2)', null, undefined]) {
      expect(parseColour(bad, NONE)).toBe(NONE)
    }
  })
})
