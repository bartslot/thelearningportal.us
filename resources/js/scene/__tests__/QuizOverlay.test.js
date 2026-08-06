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

/**
 * The read-gate is a countdown followed by the answers arriving one at a time. The countdown part
 * has to be a whole number of seconds, because a number that changes every 1000ms but appears
 * part-way through the first one reads as a stutter: measured in a browser before this was fixed,
 * the first number held for 600ms and the rest for 1000ms.
 *
 * The cascade's own length is not asserted here — it is tuning, and it moves. What must not move
 * is that whatever it costs, the countdown in front of it still gets whole seconds.
 */
describe('the read-gate arithmetic', () => {
  const CASCADE_MS = 1480   // REVEAL_LEAD + 3 × REVEAL_STAGGER + REVEAL, for four answers

  const gateFor = (question, options) => QuizOverlay._readGateMs({ question, options })

  it('leaves the countdown a whole number of seconds, whatever the question costs', () => {
    // A range of lengths, so this holds for the reading estimate generally and not for one string.
    for (const length of [10, 40, 90, 160, 240, 400, 800]) {
      const gate = gateFor('q'.repeat(length), ['a', 'b', 'c', 'd'])
      const countdown = gate - CASCADE_MS

      expect(countdown % 1000, `a ${length}-character question leaves a ragged ${countdown}ms countdown`).toBe(0)
    }
  })

  it('never counts down from fewer than 3, however short the question', () => {
    const gate = gateFor('Who?', ['a', 'b', 'c', 'd'])

    expect(gate - CASCADE_MS).toBeGreaterThanOrEqual(3000)
  })

  /**
   * Whole seconds are a coarse ruler, and the 3-second floor is above what an ordinary question
   * needs, so most of them land on the same gate. That is the cost of counting 3, 2, 1 every time
   * and it is deliberate — but it must not go so far as to stop responding to length at all.
   */
  it('still gives a genuinely long question longer, even in whole seconds', () => {
    const lengths = [10, 100, 300, 545, 800, 1500]
    const gates = lengths.map((n) => gateFor('q'.repeat(n), ['a', 'b', 'c', 'd']))

    for (let i = 1; i < gates.length; i++) {
      expect(gates[i], `a ${lengths[i]}-character question got less than a ${lengths[i - 1]}-character one`)
        .toBeGreaterThanOrEqual(gates[i - 1])
    }
    expect(gates.at(-1), 'length stopped mattering entirely').toBeGreaterThan(gates[0])
  })

  it('never holds a class longer than the reading estimate was ever allowed to ask for', () => {
    const gate = gateFor('q'.repeat(5000), ['a'.repeat(500), 'b', 'c', 'd'])

    // The estimate caps at 7s; the gate is that rounded to whole seconds, so it may land just
    // above. What it may not do is grow with the text once the cap is reached.
    expect(gate).toBeLessThanOrEqual(7000 + 1000)
  })
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
    // The idea survives, but as motion rather than words: a question that reaches ahead of the
    // story is not answered wrongly, it is just answered early, so it never gets the wobble.
    answer(0)
    expect(host.querySelector('.qz-wrong')).toBeNull()
  })

  it('says how the answer went without saying anything', () => {
    openQuiz()
    answer(1)
    // No praise, no encouragement — the pop and the colour of the row carry it.
    expect(text()).not.toMatch(/nice|great|perfect|brilliant|on fire|almost|good try|keep going/i)
    expect(host.querySelector('.qz-correct'), 'the right answer pops').not.toBeNull()
  })

  it('keeps the explanation, because that is what the question teaches', () => {
    openQuiz([QUESTIONS[1]])
    answer(1)
    expect(text()).toContain('Hannibal crossed in 218 BCE.')
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
