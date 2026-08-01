/**
 * A stopwatch that can be paused.
 *
 * Animations timed against `performance.now()` cannot simply be resumed: the wall clock kept
 * running while the student was paused, so the next frame jumps forward by however long they
 * stood still. This keeps the running total instead — pause banks the time so far, resume starts
 * counting again from zero-added — and every consumer just asks for `elapsed()`.
 *
 * `now` is injectable so the behaviour can be tested without waiting in real time.
 */
export function createPausableClock (now = () => performance.now()) {
  let startedAt = now()
  let banked = 0        // running time accumulated before the current pause
  let paused = false

  return {
    /** Restart from zero, running. */
    reset () {
      startedAt = now()
      banked = 0
      paused = false
    },

    /** Milliseconds this clock has actually been running, pauses excluded. */
    elapsed () {
      return paused ? banked : banked + (now() - startedAt)
    },

    pause () {
      if (paused) return
      banked += now() - startedAt
      paused = true
    },

    resume () {
      if (!paused) return
      startedAt = now()
      paused = false
    },

    get isPaused () {
      return paused
    },
  }
}
