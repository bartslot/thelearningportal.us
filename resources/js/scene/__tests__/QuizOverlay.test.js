import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { QuizOverlay } from '../QuizOverlay.js'

/**
 * The quiz card navigates itself.
 *
 * Reported as "can we not show the next and previous buttons? selecting the answer is next" and
 * "why the continue button — just auto continue after showing a countdown". Answering is the only
 * control: the card holds long enough to read the feedback, then moves on, and the end screen
 * counts down and hands the lesson back on its own. These tests own that contract, because it is
 * all timer-driven and a browser tab that loses focus throttles exactly those timers.
 */

const QUESTIONS = [
  { question: 'Which city fought Rome?', options: ['Athens', 'Carthage', 'Sparta', 'Alexandria'], correct_index: 1 },
  { question: 'Who crossed the Alps?', options: ['Scipio', 'Hannibal', 'Hamilcar', 'Cato'], correct_index: 1, asks_ahead: true, explanation: 'Hannibal crossed in 218 BCE.' },
  { question: 'How many Punic Wars?', options: ['One', 'Two', 'Three', 'Four'], correct_index: 2 },
]

let host
let overlay
let completed

const openQuiz = (questions = QUESTIONS) => {
  completed = 0
  overlay = new QuizOverlay(host)
  overlay.show({ questions, shuffleMode: 'off', onComplete: () => { completed++ } })
  return overlay
}

// The read-gate is a separate feature with its own timing. Open it the way the clock would —
// _gateTick re-enables the buttons in place — because a click on a disabled button does nothing.
const answer = (displayIndex) => {
  overlay._gateUntil.set(overlay._index, 0)
  overlay._gateTick()
  host.querySelectorAll('[data-opt]')[displayIndex].click()
}

const text = () => host.textContent.replace(/\s+/g, ' ').trim()

beforeEach(() => {
  vi.useFakeTimers()
  host = document.createElement('div')
  document.body.appendChild(host)
})

afterEach(() => {
  overlay?.hide()
  host.remove()
  vi.useRealTimers()
})

describe('the question card', () => {
  it('offers no Next or Previous — the answers are the only control', () => {
    openQuiz()
    expect(host.querySelector('[data-next]')).toBeNull()
    expect(host.querySelector('[data-prev]')).toBeNull()
    expect(host.querySelectorAll('[data-opt]')).toHaveLength(4)
  })

  it('does not label a look-ahead question as a sneak peek', () => {
    openQuiz([QUESTIONS[1]])
    expect(text()).not.toMatch(/sneak peek/i)
    // The idea survives where it belongs: in the feedback, after answering.
    answer(1)
    expect(text()).toMatch(/already knew this/i)
  })

  it('moves to the next question by itself once the feedback has been read', () => {
    openQuiz()
    answer(1)
    expect(host.querySelector('[data-pager]').textContent).toBe('1 / 3')   // holds on the answer

    vi.advanceTimersByTime(1500)
    expect(host.querySelector('[data-pager]').textContent).toBe('1 / 3')   // still reading

    vi.advanceTimersByTime(200)
    expect(host.querySelector('[data-pager]').textContent).toBe('2 / 3')
  })

  it('holds longer when there is an explanation to take in', () => {
    openQuiz([QUESTIONS[1], QUESTIONS[2]])
    answer(1)
    expect(text()).toContain('Hannibal crossed in 218 BCE.')

    vi.advanceTimersByTime(1700)                                            // the plain hold
    expect(host.querySelector('[data-pager]').textContent).toBe('1 / 2')    // not yet

    vi.advanceTimersByTime(2000)
    expect(host.querySelector('[data-pager]').textContent).toBe('2 / 2')
  })

  it('scores the answer before it moves on', () => {
    openQuiz()
    answer(1)
    expect(overlay._score).toBe(10)
    vi.advanceTimersByTime(1700)
    expect(host.querySelector('[data-score-value]')).not.toBeNull()
  })
})

describe('the end screen', () => {
  const playThrough = () => {
    openQuiz()
    answer(1); vi.advanceTimersByTime(1700)
    answer(1); vi.advanceTimersByTime(3700)
    answer(2); vi.advanceTimersByTime(1700)
  }

  it('counts down and hands the lesson back with no button to press', () => {
    playThrough()
    expect(host.querySelector('[data-done]')).toBeNull()
    expect(text()).toMatch(/Lesson starts in 5/)

    vi.advanceTimersByTime(1000)
    expect(text()).toMatch(/Lesson starts in 4/)

    vi.advanceTimersByTime(3000)
    expect(text()).toMatch(/Lesson starts in 1/)
    expect(completed).toBe(0)                 // still on screen at 1

    vi.advanceTimersByTime(1000)
    expect(completed).toBe(1)                 // …and gone at 0
    expect(host.innerHTML).toBe('')
  })

  it('lets a tap skip the rest of the countdown', () => {
    playThrough()
    host.querySelector('.qz-card').click()
    expect(completed).toBe(1)

    // The timer must not fire a second time after the overlay is gone.
    vi.advanceTimersByTime(6000)
    expect(completed).toBe(1)
  })

  it('keeps a Skip button while there is a leaderboard form to fill in', () => {
    completed = 0
    overlay = new QuizOverlay(host)
    overlay.show({ questions: QUESTIONS, shuffleMode: 'off', submitUrl: '/quiz-score', onComplete: () => { completed++ } })
    answer(1); vi.advanceTimersByTime(1700)
    answer(1); vi.advanceTimersByTime(3700)
    answer(2); vi.advanceTimersByTime(1700)

    // Nothing may snatch the card away while a student is typing their name.
    expect(host.querySelector('[data-done]')).not.toBeNull()
    expect(text()).not.toMatch(/Lesson starts in/)
    vi.advanceTimersByTime(10000)
    expect(completed).toBe(0)
  })

  it('counts down on the leaderboard too', () => {
    openQuiz()
    overlay._renderLeaderboard({ top: [{ nickname: 'Fatima', score: 60 }], players: 4, rank: 1 }, 'Fatima')
    expect(host.querySelector('[data-done]')).toBeNull()
    expect(text()).toMatch(/Lesson starts in 5/)

    vi.advanceTimersByTime(5000)
    expect(completed).toBe(1)
  })
})
