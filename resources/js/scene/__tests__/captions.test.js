import { describe, it, expect } from 'vitest'
import { buildCues, cueAt, splitIntoCues, spokenText } from '../captions.js'

/**
 * Narration subtitles.
 *
 * Azure returns no word timings, so most lessons have to place their captions from character
 * counts against the real audio length. That estimate is the part worth pinning down: it decides
 * whether a line appears while it is being said or a sentence late.
 */

const SCRIPT = 'Venice was a city of merchants. [position:left] In 1271 a boy of seventeen left it for the east, and would not see home again for twenty-four years.'

describe('spokenText', () => {
  it('drops the direction tags the narrator never reads aloud', () => {
    expect(spokenText('Hello [position:left] there')).toBe('Hello there')
    expect(spokenText('[game]')).toBe('')
  })

  it('survives an empty or missing script', () => {
    expect(spokenText('')).toBe('')
    expect(spokenText(null)).toBe('')
    expect(spokenText(undefined)).toBe('')
  })
})

describe('splitIntoCues', () => {
  it('breaks on sentences', () => {
    const cues = splitIntoCues('One thing happened. Then another. Then a third.')
    expect(cues).toEqual(['One thing happened.', 'Then another.', 'Then a third.'])
  })

  it('splits a long sentence at a word boundary, never mid-word', () => {
    const long = `He carried ${'silk '.repeat(40)}home.`
    for (const cue of splitIntoCues(long)) {
      expect(cue.length).toBeLessThanOrEqual(84)
      expect(cue).not.toMatch(/^\s|\s$/)
    }
    // Nothing is lost in the cutting.
    expect(splitIntoCues(long).join(' ')).toBe(spokenText(long))
  })

  it('keeps every word of the script, in order', () => {
    expect(splitIntoCues(SCRIPT).join(' ')).toBe(spokenText(SCRIPT))
  })
})

describe('buildCues without TTS timings', () => {
  const cues = buildCues(SCRIPT, 20)

  it('starts at the beginning and ends with the audio', () => {
    expect(cues[0].start).toBe(0)
    expect(cues[cues.length - 1].end).toBe(20)
  })

  it('runs forwards with no gaps or overlaps', () => {
    for (let i = 1; i < cues.length; i++) {
      expect(cues[i].start).toBeCloseTo(cues[i - 1].end, 5)
    }
  })

  it('gives the longer line the longer time on screen', () => {
    const sorted = [...cues].sort((a, b) => a.text.length - b.text.length)
    expect(sorted[0].end - sorted[0].start).toBeLessThan(sorted[sorted.length - 1].end - sorted[sorted.length - 1].start)
  })

  it('has nothing to show without a script or a duration', () => {
    expect(buildCues('', 20)).toEqual([])
    expect(buildCues(SCRIPT, 0)).toEqual([])
    expect(buildCues(SCRIPT, null)).toEqual([])
    expect(buildCues(SCRIPT, NaN)).toEqual([])
  })

  it('merges a line too short to read into its neighbour', () => {
    // Three sentences over two seconds would flash by; they end up as fewer, readable lines.
    const short = buildCues('Yes. No. Maybe so, he thought, and said nothing more about it at all.', 2)
    expect(short.length).toBeLessThan(3)
    expect(short.map((c) => c.text).join(' ')).toContain('Yes. No.')
  })
})

describe('buildCues with TTS timings', () => {
  /** A character alignment for `text`, spoken at a steady `secondsPerChar`. */
  const alignmentFor = (text, secondsPerChar) =>
    [...text].map((character, i) => ({ character, start_time: i * secondsPerChar }))

  it('places each line at the moment its first character is spoken', () => {
    const text = spokenText(SCRIPT)
    const cues = buildCues(SCRIPT, text.length * 0.05, alignmentFor(text, 0.05))

    for (const cue of cues) {
      expect(cue.start).toBeCloseTo(text.indexOf(cue.text.slice(0, 20)) * 0.05, 4)
    }
  })

  it('falls back to the end rather than throwing when the script outgrew its audio', () => {
    const cues = buildCues(SCRIPT, 10, alignmentFor('Venice was', 0.05))
    expect(cues.every((c) => c.start <= 10 && c.end <= 10)).toBe(true)
  })
})

describe('cueAt', () => {
  const cues = [
    { start: 0, end: 2, text: 'first' },
    { start: 2, end: 5, text: 'second' },
  ]

  it('finds the line being spoken right now', () => {
    expect(cueAt(cues, 0)?.text).toBe('first')
    expect(cueAt(cues, 1.99)?.text).toBe('first')
    expect(cueAt(cues, 2)?.text).toBe('second')
    expect(cueAt(cues, 4.9)?.text).toBe('second')
  })

  it('shows nothing once the narration has finished, or before it starts', () => {
    expect(cueAt(cues, 5)).toBeNull()
    expect(cueAt(cues, 900)).toBeNull()
    expect(cueAt([], 1)).toBeNull()
    expect(cueAt(null, 1)).toBeNull()
  })
})
