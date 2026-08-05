import { describe, it, expect } from 'vitest'
import { focusLabelLayout } from '../map-annotations.js'
import { placeLabelLayout, PLACE_LABEL } from '../map-place-label.js'

/**
 * Focus labels are the teacher's own annotations on the map — the names a class reads out loud.
 *
 * The bug these guard: a Dutch Revolt map put Den Briel, Delft, Leiden and Haarlem within 40 km of
 * each other and drew all four names on the same pixels, one unreadable dark-red blot. The cause
 * was `text-allow-overlap`, which does not mean "prefer to overlap" — it switches MapLibre's
 * collision detection off entirely. It had been set because each name was drawn twice (a dark copy
 * behind a red copy, faking a drop shadow) and two copies of one word collide with each other.
 */
describe('focus label placement', () => {
  const layout = focusLabelLayout()

  it('never switches collision detection off', () => {
    // The single property that caused the blot. Absent or false — both leave collision on.
    expect(layout['text-allow-overlap']).toBeFalsy()
    expect(layout['text-ignore-placement']).toBeFalsy()
  })

  it('can move a name to whichever side of its dot is free', () => {
    const anchors = layout['text-variable-anchor']

    // Four sides at minimum, or a cluster of four towns has nowhere to go.
    expect(anchors.length).toBeGreaterThanOrEqual(4)
    expect(anchors).toEqual(expect.arrayContaining(['top', 'bottom', 'left', 'right']))
    // Variable anchors are ignored by MapLibre unless justification is automatic.
    expect(layout['text-justify']).toBe('auto')
  })

  it('tries beside the dot before above or below it', () => {
    // Towns on a coast stack north-to-south, so the vertical axis is the one with no room. Placing
    // to the side first is what lets four neighbours alternate instead of queueing for one gap.
    const [first, second] = layout['text-variable-anchor']

    expect([first, second].sort()).toEqual(['left', 'right'])
  })

  it('keeps the name clear of its own dot', () => {
    expect(layout['text-radial-offset']).toBeGreaterThan(0)
  })

  it('wraps a long annotation instead of laying a bar of text across the map', () => {
    // "Leiden relieved 1574" is ~19 characters; 8 ems breaks it onto two short lines.
    expect(layout['text-max-width']).toBeGreaterThan(0)
    expect(layout['text-max-width']).toBeLessThanOrEqual(10)
  })

  it('gives neighbouring names air between them', () => {
    expect(layout['text-padding']).toBeGreaterThan(0)
  })

  it('lets a capital win the space when the map is genuinely crowded', () => {
    const sortKey = layout['symbol-sort-key']
    const evaluate = (capital) => {
      // ['case', ['boolean', ['get','capital'], false], <capitalRank>, <otherRank>]
      const [, , capitalRank, otherRank] = sortKey
      return capital ? capitalRank : otherRank
    }

    // Lower sort key = placed first = survives a collision.
    expect(evaluate(true)).toBeLessThan(evaluate(false))
  })

  /**
   * One place-label style, not three. The voyage itinerary drew a light pill with dark ink, focus
   * cities drew red calligraphy with a parchment halo, and waypoint titles were a third thing —
   * three looks for "this place, named", so nothing was recognisable across screens.
   */
  it('takes its placement from the shared place-label rules, not its own copy', () => {
    const shared = placeLabelLayout({ font: ['Eagle Lake'], textField: ['get', 'label'] })

    expect(layout['text-variable-anchor']).toEqual(shared['text-variable-anchor'])
    expect(layout['text-radial-offset']).toBe(shared['text-radial-offset'])
    expect(layout['text-max-width']).toBe(shared['text-max-width'])
  })

  it('keeps the pill tokens as one source of truth for the itinerary pins', () => {
    // The map layers do not draw the pill yet (see map-place-label.js), but the DOM pins do, and
    // they read these numbers rather than repeating them.
    expect(PLACE_LABEL.ink).toBe('#0f172a')
    expect(PLACE_LABEL.fill).toContain('255,255,255')
  })

  it('takes the map style\'s hand, so satellite and night get the modern sans', () => {
    expect(focusLabelLayout(['inter'])['text-font']).toEqual(['inter'])
    expect(layout['text-font']).toEqual(['Eagle Lake'])
  })
})
