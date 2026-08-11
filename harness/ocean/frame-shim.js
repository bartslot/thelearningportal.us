/**
 * frame-shim.js — give a hidden document frames anyway. Imported FIRST, before MapLibre.
 *
 * An automated browser pane is hidden almost all the time, and a hidden document gets no
 * `requestAnimationFrame` callbacks at all — measured here, not assumed: zero in two seconds. That
 * is not merely a paused render loop. MapLibre defers even parsing an inline style object onto a
 * frame, so with no frames the map never gains a single source or layer: `getStyle()` comes back
 * undefined, `addLayer` never runs, and the whole page sits at a state that looks like a hang and
 * reports no error anywhere. Forcing renders with `map.redraw()` cannot rescue it, because the
 * thing that never happened is upstream of rendering.
 *
 * So frames are supplied by hand. `MessageChannel` posts an ordinary task, which the hidden-document
 * throttle does not touch — unlike `setTimeout`, which is clamped to about one call per second and
 * would take a page load into the minutes.
 *
 * This is harness-only. Nothing in resources/js knows about it, and the layer under test runs the
 * same code path it runs in a visible tab; what changes is only when the browser agrees to run it.
 */

const FRAME_MS = 1000 / 60

let nextHandle = 1
const pending = new Map()

const channel = new MessageChannel()
channel.port1.onmessage = () => {
  // Snapshot first: a callback that schedules another frame must not be run inside this drain.
  const due = [...pending.entries()]
  pending.clear()
  const stamp = performance.now()
  for (const [, callback] of due) {
    try { callback(stamp) } catch (error) { console.error('frame callback threw', error) }
  }
}

window.requestAnimationFrame = (callback) => {
  const handle = nextHandle++
  pending.set(handle, callback)
  // One post per drain, not per callback, so a frame stays a frame. The post itself is enough to
  // batch: everything scheduled synchronously before the task runs lands in the same drain. It has
  // to be a post and not a `setTimeout(0)`, which is exactly the clamped call being routed around.
  if (pending.size === 1) channel.port2.postMessage(0)
  return handle
}

window.cancelAnimationFrame = (handle) => { pending.delete(handle) }

// Kept so measurements can state the frame rate they were taken at rather than imply 60.
window.__frameShim = { FRAME_MS, pendingCount: () => pending.size }
