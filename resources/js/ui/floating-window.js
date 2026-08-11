/**
 * floating-window.js — the geometry and persistence behind a draggable tool window.
 *
 * WHY THIS IS A MODULE AND NOT TWENTY LINES IN THE TEMPLATE. This screen has had a floating panel
 * before and it was removed, for a reason recorded in step3-scene-configurator.blade.php: teachers
 * LOST it. A window that can be dragged mostly off-screen, or that restores to coordinates from a
 * larger monitor, is gone — there is no error and no empty state, just a panel that is not there.
 * That is a geometry bug, and geometry is testable, so it lives here where a test can hold it
 * rather than in an Alpine attribute where it cannot.
 *
 * The drag ITSELF stays in the template: it is three pointer handlers and nothing about it has ever
 * been the thing that broke.
 */

/** Keep at least this much of the window on screen, in px, so there is always something to grab. */
export const MIN_VISIBLE_PX = 64

/**
 * Is there a viewport worth positioning against yet?
 *
 * A zero-sized viewport is not a small screen, it is a screen that has not been measured. Placing
 * against it produces a position that is legal, permanent and wrong: the browser pane reports
 * innerWidth 0 until it is resized, and a window placed then landed at left -224 and stayed, since
 * every later re-clamp agreed that -224 was fine. Callers must wait rather than clamp.
 */
export const isViewportUsable = (viewport) =>
  Number.isFinite(viewport?.width) && Number.isFinite(viewport?.height)
  && viewport.width > 0 && viewport.height > 0

/**
 * The nearest position to `desired` that still leaves the window grabbable.
 *
 * Clamps against the window's own size, so a wide panel cannot be pushed off the right edge, and
 * the top is never negative — a title bar above the viewport cannot be dragged back down.
 */
export const clampToViewport = (desired, size, viewport) => {
  const width = Math.max(0, size?.width ?? 0)
  const height = Math.max(0, size?.height ?? 0)
  const viewWidth = Math.max(0, viewport?.width ?? 0)
  const viewHeight = Math.max(0, viewport?.height ?? 0)

  /**
   * How much of the window has to stay on screen, per axis.
   *
   * A window smaller than MIN_VISIBLE_PX must be kept fully visible rather than partly, hence the
   * min. But an extent of ZERO means "not measured yet", not "zero tall" — and the difference is
   * a bug found by dragging one, not by reading it: `Math.min(64, 288, 0)` is 0, which makes every
   * bound degenerate and lets the window leave the screen entirely. getBoundingClientRect returns
   * 0 more often than you would think (mid-layout, or while display is still none), so an
   * unmeasured extent falls back to the minimum instead of to no limit at all.
   */
  const keepOnScreen = (extent) =>
    (Number.isFinite(extent) && extent > 0 ? Math.min(MIN_VISIBLE_PX, extent) : MIN_VISIBLE_PX)

  const grabX = keepOnScreen(width)
  const grabY = keepOnScreen(height)

  // Horizontally the window may hang off either edge; vertically only the bottom, because the title
  // bar is the only part you can drag and it is at the top.
  const minLeft = grabX - width
  const maxLeft = Math.max(minLeft, viewWidth - grabX)
  const maxTop = Math.max(0, viewHeight - grabY)

  const left = Number.isFinite(desired?.left) ? desired.left : 0
  const top = Number.isFinite(desired?.top) ? desired.top : 0

  return {
    left: Math.min(maxLeft, Math.max(minLeft, left)),
    top: Math.min(maxTop, Math.max(0, top)),
  }
}

/**
 * Where a window should open: its remembered spot if it is still reachable, otherwise the fallback.
 *
 * The clamp is applied on the way OUT, not on the way in, which is the part that matters. A
 * position saved on a 2560px monitor is perfectly valid until the same user opens the lesson on a
 * laptop, and a stored value that was legal when written is exactly the kind of thing nobody
 * re-checks.
 */
export const restorePosition = (key, size, viewport, fallback, storage = globalThis.localStorage) => {
  let saved = null
  try {
    const raw = JSON.parse(storage?.getItem(key) || 'null')
    if (raw && typeof raw.left === 'number' && typeof raw.top === 'number'
        && Number.isFinite(raw.left) && Number.isFinite(raw.top)) {
      saved = raw
    }
  } catch (_) { /* blocked or corrupt — the fallback stands */ }
  return clampToViewport(saved ?? fallback, size, viewport)
}

/** Remember where it was left. Never throws: a browser blocking storage must not break dragging. */
export const savePosition = (key, position, storage = globalThis.localStorage) => {
  try { storage?.setItem(key, JSON.stringify(position)) } catch (_) { /* private mode */ }
}

/**
 * Where the fallback puts a window that has never been opened: inset from the top-right, clear of
 * the 4rem app header.
 */
export const defaultPosition = (size, viewport, inset = 24, headerPx = 72) => clampToViewport(
  { left: (viewport?.width ?? 0) - (size?.width ?? 0) - inset, top: headerPx },
  size,
  viewport,
)
