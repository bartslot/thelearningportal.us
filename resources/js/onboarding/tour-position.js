// Placement maths for the onboarding spotlight. Pure functions on plain rectangles: no DOM, no
// Alpine, so the awkward cases (no room below, anchor scrolled off screen, tiny viewport) are unit
// tested instead of discovered by a teacher on a laptop.
//
// All rectangles are viewport-relative, like getBoundingClientRect(): {top, left, width, height}.

/** Gap between the spotlighted element and the card, and between the card and the viewport edge. */
export const GAP = 14
export const VIEWPORT_PADDING = 16

/** Below this viewport width the card is a bottom sheet — there is no room to sit beside anything. */
export const COMPACT_BREAKPOINT = 640

/** Ring drawn around the spotlighted element, a little larger than the element itself. */
export const SPOTLIGHT_PADDING = 8


const clamp = (value, min, max) => {
    // A card taller/wider than the space available would give max < min; pinning to min keeps the
    // top-left corner visible, which is the readable half.
    if (max < min) return min

    return Math.min(Math.max(value, min), max)
}

const rectOf = (input) => ({
    top: Number(input?.top ?? 0),
    left: Number(input?.left ?? 0),
    width: Number(input?.width ?? 0),
    height: Number(input?.height ?? 0),
})

const bottomOf = (rect) => rect.top + rect.height
const rightOf = (rect) => rect.left + rect.width
const centerX = (rect) => rect.left + rect.width / 2
const centerY = (rect) => rect.top + rect.height / 2

/** Is the anchor actually on screen (at all) right now? */
export const isAnchorVisible = (anchor, viewport) => {
    if (!anchor) return false

    const rect = rectOf(anchor)

    if (rect.width <= 0 || rect.height <= 0) return false

    return bottomOf(rect) > 0
        && rect.top < viewport.height
        && rightOf(rect) > 0
        && rect.left < viewport.width
}

/** Space between the anchor and each viewport edge, minus the gap the card needs. */
export const availableSpace = (anchor, viewport) => ({
    top: anchor.top - GAP - VIEWPORT_PADDING,
    bottom: viewport.height - bottomOf(anchor) - GAP - VIEWPORT_PADDING,
    left: anchor.left - GAP - VIEWPORT_PADDING,
    right: viewport.width - rightOf(anchor) - GAP - VIEWPORT_PADDING,
})

/**
 * Pick the side the card sits on: the preferred one when it fits, otherwise its opposite, otherwise
 * whichever side has the most room. Returns 'center' when nothing fits — the card then floats over
 * the middle and the ring alone shows what is being talked about.
 */
export const choosePlacement = (preferred, anchor, card, viewport) => {
    const space = availableSpace(anchor, viewport)
    const needed = { top: card.height, bottom: card.height, left: card.width, right: card.width }
    const fits = (side) => space[side] >= needed[side]
    const opposite = { top: 'bottom', bottom: 'top', left: 'right', right: 'left' }

    if (preferred === 'center' || !opposite[preferred]) {
        return 'center'
    }

    if (fits(preferred)) return preferred
    if (fits(opposite[preferred])) return opposite[preferred]

    const roomiest = ['bottom', 'top', 'right', 'left']
        .filter((side) => fits(side))
        .sort((a, b) => space[b] - space[a])[0]

    return roomiest ?? 'center'
}

/**
 * Where to put the card, in viewport coordinates, always inside the viewport.
 *
 * Returns { placement, top, left, arrow } where `arrow` is the pointer's offset along the card's
 * edge (null when the card is centred, or when the anchor is no longer beside the card after
 * clamping — an arrow pointing at empty space is worse than no arrow).
 */
export const positionCard = ({ anchor, card, viewport, preferred = 'bottom', compact = false }) => {
    const cardRect = rectOf(card)
    const view = { width: Number(viewport?.width ?? 0), height: Number(viewport?.height ?? 0) }

    const centred = () => ({
        placement: 'center',
        top: Math.max(VIEWPORT_PADDING, (view.height - cardRect.height) / 2),
        left: Math.max(VIEWPORT_PADDING, (view.width - cardRect.width) / 2),
        arrow: null,
    })

    if (compact || !isAnchorVisible(anchor, view)) {
        return centred()
    }

    const anchorRect = rectOf(anchor)
    const placement = choosePlacement(preferred, anchorRect, cardRect, view)

    if (placement === 'center') {
        return centred()
    }

    const maxLeft = view.width - cardRect.width - VIEWPORT_PADDING
    const maxTop = view.height - cardRect.height - VIEWPORT_PADDING

    let top
    let left

    if (placement === 'bottom' || placement === 'top') {
        top = placement === 'bottom'
            ? bottomOf(anchorRect) + GAP
            : anchorRect.top - GAP - cardRect.height
        left = clamp(centerX(anchorRect) - cardRect.width / 2, VIEWPORT_PADDING, maxLeft)
    } else {
        left = placement === 'right'
            ? rightOf(anchorRect) + GAP
            : anchorRect.left - GAP - cardRect.width
        top = clamp(centerY(anchorRect) - cardRect.height / 2, VIEWPORT_PADDING, maxTop)
    }

    return {
        placement,
        top: clamp(top, VIEWPORT_PADDING, maxTop),
        left: clamp(left, VIEWPORT_PADDING, maxLeft),
        arrow: arrowOffset(placement, anchorRect, cardRect, { top, left }),
    }
}

/**
 * Offset of the arrow along the card edge it points from, or null when the anchor's centre falls
 * outside the card's span (which happens after clamping near a viewport corner).
 */
export const arrowOffset = (placement, anchor, card, cardPosition) => {
    const inset = 18

    if (placement === 'bottom' || placement === 'top') {
        const offset = centerX(anchor) - cardPosition.left
        return offset >= inset && offset <= card.width - inset ? offset : null
    }

    if (placement === 'left' || placement === 'right') {
        const offset = centerY(anchor) - cardPosition.top
        return offset >= inset && offset <= card.height - inset ? offset : null
    }

    return null
}

/** The ring rectangle: the anchor plus padding, never spilling outside the viewport. */
export const spotlightRect = (anchor, viewport) => {
    const rect = rectOf(anchor)
    const top = Math.max(0, rect.top - SPOTLIGHT_PADDING)
    const left = Math.max(0, rect.left - SPOTLIGHT_PADDING)

    return {
        top,
        left,
        width: Math.max(0, Math.min(rightOf(rect) + SPOTLIGHT_PADDING, viewport.width) - left),
        height: Math.max(0, Math.min(bottomOf(rect) + SPOTLIGHT_PADDING, viewport.height) - top),
    }
}

/** Does the anchor sit far enough inside the viewport to read, or should the page scroll to it? */
export const needsScrollIntoView = (anchor, viewport, margin = 80) => {
    const rect = rectOf(anchor)

    return rect.top < margin || bottomOf(rect) > viewport.height - margin
}
