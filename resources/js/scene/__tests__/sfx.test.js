import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { Sfx } from '../sfx.js'
import { QuizOverlay } from '../QuizOverlay.js'

/**
 * The interface's sound effects, and the one that matters: the chime on a right answer.
 *
 * The point of these tests is the mute button. A teacher who silenced the lesson silenced the
 * lesson — a chime arriving anyway from a corner of the code that kept its own volume reads as
 * the button being broken, and it is the sort of thing nobody notices until it happens in front
 * of a class.
 */

const CORRECT_URL = '/sound/sfx/correct.mp3'

const QUESTIONS = [
  { question: 'Which city fought Rome?', options: ['Athens', 'Carthage', 'Sparta', 'Alexandria'], correct_index: 1 },
  { question: 'Who crossed the Alps?', options: ['Scipio', 'Hannibal', 'Hamilcar', 'Cato'], correct_index: 1 },
]

class FakeAudio {
  static made = []

  constructor (src) {
    this.src = src
    this.volume = 1
    this.currentTime = 0
    this.plays = 0
    FakeAudio.made.push(this)
  }

  play () { this.plays++; return Promise.resolve() }
  pause () {}
  load () {}
  addEventListener () {}
}

// Sfx keeps ONE element per effect for the life of the page — that is the point of it, so the
// first correct answer isn't waiting on a download. Tests therefore count plays rather than
// constructions, and measure each one against where the count started.
const chime = () => FakeAudio.made.find(a => a.src === CORRECT_URL)
const playCount = () => chime()?.plays ?? 0

let host
let overlay
let playsAtStart

const openQuiz = () => {
  overlay = new QuizOverlay(host)
  overlay.show({ questions: QUESTIONS, shuffleMode: 'off' })
}

/** Answer the question at `displayIndex`, past the read-gate the clock would have opened. */
const answer = (displayIndex) => {
  overlay._gateUntil.set(overlay._index, 0)
  overlay._gateTick()
  host.querySelectorAll('[data-opt]')[displayIndex].click()
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.stubGlobal('Audio', FakeAudio)
  window.LP_AUDIO = { sfx: { correct: CORRECT_URL } }
  Sfx.setMuted(false)
  Sfx.setVolume(1)
  playsAtStart = playCount()
  host = document.createElement('div')
  document.body.appendChild(host)
})

afterEach(() => {
  overlay?.hide()
  host.remove()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  delete window.LP_AUDIO
})

describe('Sfx', () => {
  it('plays nothing while the lesson is muted', () => {
    Sfx.setMuted(true)
    Sfx.play('correct')
    expect(playCount()).toBe(playsAtStart)
  })

  it('plays nothing when the volume slider is at zero', () => {
    Sfx.setVolume(0)
    Sfx.play('correct')
    expect(playCount()).toBe(playsAtStart)
  })

  it('follows the volume slider', () => {
    Sfx.setVolume(0.4)
    Sfx.play('correct')
    expect(chime().volume).toBeCloseTo(0.4, 5)
  })

  it('restarts the effect, so two quick answers both sound', () => {
    Sfx.play('correct')
    Sfx.play('correct')
    expect(playCount()).toBe(playsAtStart + 2)
    expect(chime().currentTime).toBe(0)
  })

  it('stays quiet rather than throwing when the page ships no soundtrack', () => {
    delete window.LP_AUDIO
    expect(() => Sfx.play('nothing-here')).not.toThrow()
  })
})

describe('the quiz chime', () => {
  it('sounds on a right answer', () => {
    openQuiz()
    answer(1)
    expect(playCount()).toBe(playsAtStart + 1)
  })

  it('says nothing on a wrong answer', () => {
    openQuiz()
    answer(0)
    expect(playCount()).toBe(playsAtStart)
  })

  it('is silent when the student muted the player', () => {
    Sfx.setMuted(true)
    openQuiz()
    answer(1)
    expect(playCount()).toBe(playsAtStart)
  })
})
