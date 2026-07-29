// Alpine component behind the welcome tour: a spotlight that follows real elements on the page.
//
// Design notes, all of them lessons about not breaking:
//   • A step whose anchor is missing (collapsed nav, another screen size, a page that does not have
//     that button) degrades to a centred card. It never points at nothing and never blocks.
//   • Position is recomputed on scroll, resize and whenever the card's own size changes, so the
//     card cannot drift away from what it is pointing at.
//   • Focus is trapped inside the card and handed back to whatever had it when the tour closes.
//   • prefers-reduced-motion turns off both the scrolling animation and the card transitions.
//   • Progress is written back to the server as the teacher moves, so closing the tab and coming
//     back resumes on the same step instead of starting over.
//
// The placement maths lives in tour-position.js and is unit tested there.

import {
    COMPACT_BREAKPOINT,
    isAnchorVisible,
    needsScrollIntoView,
    positionCard,
    spotlightRect,
} from './tour-position.js'

const FOCUSABLE = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'

const PERSIST_DEBOUNCE_MS = 350

const prefersReducedMotion = () =>
    window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false

// documentElement.clientWidth/Height first, not innerWidth/Height: the card is placed in the same
// coordinate space as getBoundingClientRect, and innerWidth can differ from it (it includes the
// scrollbar, and embedded/zoomed viewports report it differently) — which lands "centred" cards off
// to one side.
const viewportSize = () => ({
    width: document.documentElement.clientWidth || window.innerWidth || 0,
    height: document.documentElement.clientHeight || window.innerHeight || 0,
})

export const createTour = ({ steps = [], startStep = 0 } = {}) => ({
    steps,
    index: Math.min(Math.max(Number(startStep) || 0, 0), Math.max(steps.length - 1, 0)),
    total: steps.length,

    // Layout state read by the template.
    placement: 'center',
    cardStyle: '',
    spotlight: null,
    arrow: null,
    compact: false,
    ready: false,

    // Internals.
    _persistTimer: null,
    _frame: null,
    _resizeObserver: null,
    _previouslyFocused: null,
    _listeners: [],

    init() {
        if (this.total === 0) {
            return
        }

        this._previouslyFocused = document.activeElement
        this.compact = viewportSize().width < COMPACT_BREAKPOINT

        const onViewportChange = () => this.scheduleReposition()

        this.on(window, 'resize', onViewportChange)
        this.on(window, 'scroll', onViewportChange, { passive: true, capture: true })
        this.on(window, 'orientationchange', onViewportChange)

        if (typeof ResizeObserver !== 'undefined' && this.$refs.card) {
            // The card changes height between steps; its own resize must move it back into place.
            this._resizeObserver = new ResizeObserver(() => this.scheduleReposition())
            this._resizeObserver.observe(this.$refs.card)
        }

        // First paint: let the card render so it has a measurable size, then place it.
        this.$nextTick(() => {
            this.revealStep({ persist: false })
            this.ready = true
        })
    },

    destroy() {
        this._listeners.forEach(([target, event, handler, options]) =>
            target.removeEventListener(event, handler, options))
        this._listeners = []
        this._resizeObserver?.disconnect()
        this._resizeObserver = null

        if (this._frame) {
            cancelAnimationFrame(this._frame)
            this._frame = null
        }

        if (this._persistTimer) {
            clearTimeout(this._persistTimer)
            this._persistTimer = null
        }

        this.restoreFocus()
    },

    on(target, event, handler, options) {
        target.addEventListener(event, handler, options)
        this._listeners.push([target, event, handler, options])
    },

    // ── Steps ───────────────────────────────────────────────────────────────

    get step() {
        return this.steps[this.index] ?? null
    },

    get isFirst() {
        return this.index === 0
    },

    get isLast() {
        return this.index >= this.total - 1
    },

    goTo(index, options = {}) {
        const next = Math.min(Math.max(Number(index) || 0, 0), this.total - 1)

        if (next === this.index && this.ready) {
            return
        }

        this.index = next
        this.$nextTick(() => this.revealStep(options))
    },

    next() {
        if (this.isLast) {
            this.finish()
            return
        }

        this.goTo(this.index + 1)
    },

    previous() {
        this.goTo(this.index - 1)
    },

    /** Scroll the step's anchor into view if needed, place the card, and move focus to it. */
    revealStep({ persist = true } = {}) {
        const anchor = this.anchorElement()

        if (anchor && needsScrollIntoView(anchor.getBoundingClientRect(), viewportSize())) {
            anchor.scrollIntoView({
                block: 'center',
                inline: 'nearest',
                behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            })
        }

        this.scheduleReposition()
        this.focusCard()

        if (persist) {
            this.persistStep()
        }
    },

    anchorElement() {
        const name = this.step?.anchor

        if (!name) {
            return null
        }

        const element = document.querySelector(`[data-tour="${CSS.escape(name)}"]`)

        // An element can exist but be hidden (a collapsed mobile menu). offsetParent is null then,
        // except for position:fixed elements — hence the rect check as well.
        if (!element) {
            return null
        }

        const rect = element.getBoundingClientRect()

        return rect.width > 0 && rect.height > 0 ? element : null
    },

    // ── Placement ───────────────────────────────────────────────────────────

    scheduleReposition() {
        if (this._frame) {
            return
        }

        this._frame = requestAnimationFrame(() => {
            this._frame = null
            this.reposition()
        })
    },

    reposition() {
        const card = this.$refs.card

        if (!card) {
            return
        }

        const viewport = viewportSize()
        this.compact = viewport.width < COMPACT_BREAKPOINT

        const anchor = this.anchorElement()
        const anchorRect = anchor ? anchor.getBoundingClientRect() : null
        const cardRect = card.getBoundingClientRect()

        const visible = anchorRect ? isAnchorVisible(anchorRect, viewport) : false

        this.spotlight = visible ? spotlightRect(anchorRect, viewport) : null

        if (this.compact) {
            // Bottom sheet: the template pins the card, so no inline position is written.
            this.placement = 'sheet'
            this.cardStyle = ''
            this.arrow = null

            return
        }

        const position = positionCard({
            anchor: visible ? anchorRect : null,
            card: { width: cardRect.width, height: cardRect.height },
            viewport,
            preferred: this.step?.placement ?? 'center',
        })

        this.placement = position.placement
        this.arrow = position.arrow
        this.cardStyle = `top: ${Math.round(position.top)}px; left: ${Math.round(position.left)}px;`
    },

    get arrowStyle() {
        if (this.arrow === null) {
            return 'display: none;'
        }

        return this.placement === 'top' || this.placement === 'bottom'
            ? `left: ${Math.round(this.arrow)}px;`
            : `top: ${Math.round(this.arrow)}px;`
    },

    get spotlightStyle() {
        if (!this.spotlight) {
            return 'display: none;'
        }

        const { top, left, width, height } = this.spotlight

        return `top: ${Math.round(top)}px; left: ${Math.round(left)}px; `
            + `width: ${Math.round(width)}px; height: ${Math.round(height)}px;`
    },

    // ── Focus ───────────────────────────────────────────────────────────────

    focusCard() {
        this.$refs.card?.focus({ preventScroll: true })
    },

    restoreFocus() {
        const target = this._previouslyFocused

        if (target && typeof target.focus === 'function' && document.contains(target)) {
            target.focus({ preventScroll: true })
        }

        this._previouslyFocused = null
    },

    /** Keep Tab inside the card: a tour that lets focus wander behind the backdrop is a trap. */
    trapFocus(event) {
        if (event.key !== 'Tab') {
            return
        }

        const card = this.$refs.card

        if (!card) {
            return
        }

        const focusable = [...card.querySelectorAll(FOCUSABLE)].filter((element) =>
            element.offsetParent !== null || element === document.activeElement)

        if (focusable.length === 0) {
            event.preventDefault()
            card.focus({ preventScroll: true })

            return
        }

        const first = focusable[0]
        const last = focusable[focusable.length - 1]
        const active = document.activeElement

        if (event.shiftKey && (active === first || active === card)) {
            event.preventDefault()
            last.focus()
        } else if (!event.shiftKey && active === last) {
            event.preventDefault()
            first.focus()
        }
    },

    // ── Server state ────────────────────────────────────────────────────────

    persistStep() {
        if (this._persistTimer) {
            clearTimeout(this._persistTimer)
        }

        const index = this.index

        this._persistTimer = setTimeout(() => {
            this._persistTimer = null
            // Fire and forget: a failed progress write must never block the tour.
            this.$wire?.recordStep(index)
        }, PERSIST_DEBOUNCE_MS)
    },

    finish() {
        this.restoreFocus()
        this.$wire?.finish(this.index)
    },

    dismiss() {
        this.restoreFocus()
        this.$wire?.dismiss(this.index)
    },
})

export default createTour
