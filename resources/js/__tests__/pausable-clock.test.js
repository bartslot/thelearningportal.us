import { describe, test, expect } from 'vitest'
import { createPausableClock } from '../pausable-clock.js'

/**
 * The voyage sail is timed against this clock. A student pausing mid-ocean must find the ship
 * exactly where they left it — which only holds if paused time is never counted as sailing time.
 */
function fakeClock () {
  let t = 1000
  return {
    now: () => t,
    advance (ms) { t += ms },
  }
}

describe('createPausableClock', () => {
  test('counts real time while running', () => {
    const fake = fakeClock()
    const clock = createPausableClock(fake.now)

    fake.advance(250)

    expect(clock.elapsed()).toBe(250)
  })

  test('freezes while paused, however long the pause lasts', () => {
    const fake = fakeClock()
    const clock = createPausableClock(fake.now)

    fake.advance(400)
    clock.pause()
    fake.advance(10_000)

    expect(clock.elapsed()).toBe(400)
    expect(clock.isPaused).toBe(true)
  })

  test('resumes from where it stopped instead of jumping ahead', () => {
    const fake = fakeClock()
    const clock = createPausableClock(fake.now)

    fake.advance(400)
    clock.pause()
    fake.advance(10_000)   // the wall clock ran on; the ship must not
    clock.resume()
    fake.advance(100)

    expect(clock.elapsed()).toBe(500)
  })

  test('accumulates across several pauses', () => {
    const fake = fakeClock()
    const clock = createPausableClock(fake.now)

    for (let i = 0; i < 3; i++) {
      fake.advance(200)
      clock.pause()
      fake.advance(5_000)
      clock.resume()
    }

    expect(clock.elapsed()).toBe(600)
  })

  test('ignores a repeated pause or resume', () => {
    const fake = fakeClock()
    const clock = createPausableClock(fake.now)

    fake.advance(100)
    clock.pause()
    clock.pause()          // must not bank the same stretch twice
    fake.advance(1_000)
    clock.resume()
    clock.resume()         // must not rebase the start time again
    fake.advance(50)

    expect(clock.elapsed()).toBe(150)
  })

  test('reset starts over, running, even from a paused clock', () => {
    const fake = fakeClock()
    const clock = createPausableClock(fake.now)

    fake.advance(900)
    clock.pause()
    clock.reset()

    expect(clock.isPaused).toBe(false)
    expect(clock.elapsed()).toBe(0)

    fake.advance(75)
    expect(clock.elapsed()).toBe(75)
  })
})
