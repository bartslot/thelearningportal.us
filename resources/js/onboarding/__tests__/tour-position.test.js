import { describe, it, expect } from 'vitest'
import {
    GAP,
    VIEWPORT_PADDING,
    arrowOffset,
    choosePlacement,
    isAnchorVisible,
    needsScrollIntoView,
    positionCard,
    spotlightRect,
} from '../tour-position.js'

const viewport = { width: 1280, height: 800 }
const card = { width: 380, height: 260 }

// A nav button near the top of the page — the real shape of the "New lesson" anchor.
const navButton = { top: 20, left: 1100, width: 130, height: 36 }

describe('isAnchorVisible', () => {
    it('rejects an anchor that is missing or has collapsed to nothing', () => {
        expect(isAnchorVisible(null, viewport)).toBe(false)
        expect(isAnchorVisible({ top: 10, left: 10, width: 0, height: 0 }, viewport)).toBe(false)
    })

    it('rejects an anchor scrolled out of the viewport', () => {
        expect(isAnchorVisible({ top: -200, left: 10, width: 100, height: 40 }, viewport)).toBe(false)
        expect(isAnchorVisible({ top: 900, left: 10, width: 100, height: 40 }, viewport)).toBe(false)
    })

    it('accepts an anchor that is even partly on screen', () => {
        expect(isAnchorVisible({ top: -10, left: 10, width: 100, height: 40 }, viewport)).toBe(true)
        expect(isAnchorVisible(navButton, viewport)).toBe(true)
    })
})

describe('choosePlacement', () => {
    it('keeps the preferred side when the card fits there', () => {
        expect(choosePlacement('bottom', navButton, card, viewport)).toBe('bottom')
    })

    it('flips to the opposite side when the preferred one has no room', () => {
        const nearBottom = { top: 740, left: 600, width: 120, height: 40 }
        expect(choosePlacement('bottom', nearBottom, card, viewport)).toBe('top')
    })

    it('falls back to the roomiest side when neither the preferred nor its opposite fits', () => {
        // Vertically squeezed: room above and below is too small, but there is width to the left.
        const squeezed = { top: 300, left: 900, width: 120, height: 260 }
        const tallCard = { width: 380, height: 400 }
        expect(choosePlacement('bottom', squeezed, tallCard, viewport)).toBe('left')
    })

    it('gives up and centres when the card fits nowhere', () => {
        const tiny = { width: 320, height: 300 }
        const anchor = { top: 100, left: 100, width: 200, height: 200 }
        expect(choosePlacement('bottom', anchor, card, tiny)).toBe('center')
    })

    it('honours an explicit center preference', () => {
        expect(choosePlacement('center', navButton, card, viewport)).toBe('center')
    })
})

describe('positionCard', () => {
    it('sits below the anchor with a gap and stays inside the viewport', () => {
        const result = positionCard({ anchor: navButton, card, viewport, preferred: 'bottom' })

        expect(result.placement).toBe('bottom')
        expect(result.top).toBe(navButton.top + navButton.height + GAP)
        // Centring on the button would run off the right edge, so it clamps.
        expect(result.left).toBe(viewport.width - card.width - VIEWPORT_PADDING)
        expect(result.left + card.width).toBeLessThanOrEqual(viewport.width - VIEWPORT_PADDING)
    })

    it('centres the card when the anchor is missing', () => {
        const result = positionCard({ anchor: null, card, viewport, preferred: 'bottom' })

        expect(result.placement).toBe('center')
        expect(result.arrow).toBeNull()
        expect(result.left).toBe((viewport.width - card.width) / 2)
    })

    it('centres the card on a compact screen, whatever the anchor says', () => {
        const result = positionCard({ anchor: navButton, card, viewport, preferred: 'bottom', compact: true })

        expect(result.placement).toBe('center')
    })

    it('never places the card outside the viewport, even when it is larger than the screen', () => {
        const small = { width: 360, height: 240 }
        const result = positionCard({ anchor: { top: 100, left: 40, width: 80, height: 30 }, card, viewport: small, preferred: 'bottom' })

        expect(result.top).toBeGreaterThanOrEqual(0)
        expect(result.left).toBeGreaterThanOrEqual(0)
    })

    it('drops the arrow when clamping has moved the card away from the anchor', () => {
        // Anchor hard against the right edge: the card clamps left and the arrow would miss.
        const edgeAnchor = { top: 20, left: 1275, width: 5, height: 20 }
        const result = positionCard({ anchor: edgeAnchor, card, viewport, preferred: 'bottom' })

        expect(result.arrow).toBeNull()
    })

    it('keeps the arrow when the anchor is comfortably inside the card span', () => {
        const middle = { top: 300, left: 600, width: 120, height: 40 }
        const result = positionCard({ anchor: middle, card, viewport, preferred: 'bottom' })

        expect(result.arrow).toBeGreaterThan(0)
        expect(result.arrow).toBeLessThan(card.width)
    })
})

describe('arrowOffset', () => {
    it('returns null for a centred card', () => {
        expect(arrowOffset('center', navButton, card, { top: 0, left: 0 })).toBeNull()
    })
})

describe('spotlightRect', () => {
    it('pads the anchor without spilling off screen', () => {
        const rect = spotlightRect({ top: 2, left: 2, width: 100, height: 40 }, viewport)

        expect(rect.top).toBe(0)
        expect(rect.left).toBe(0)
        expect(rect.width).toBeGreaterThan(100)
    })

    it('clips to the viewport for an anchor hanging off the right edge', () => {
        const rect = spotlightRect({ top: 100, left: 1240, width: 200, height: 40 }, viewport)

        expect(rect.left + rect.width).toBeLessThanOrEqual(viewport.width)
    })
})

describe('needsScrollIntoView', () => {
    it('asks for a scroll when the anchor is under the top edge or below the fold', () => {
        expect(needsScrollIntoView({ top: 10, left: 10, width: 100, height: 30 }, viewport)).toBe(true)
        expect(needsScrollIntoView({ top: 790, left: 10, width: 100, height: 30 }, viewport)).toBe(true)
    })

    it('leaves a comfortably placed anchor alone', () => {
        expect(needsScrollIntoView({ top: 300, left: 10, width: 100, height: 30 }, viewport)).toBe(false)
    })
})
