import { describe, it, expect } from 'vitest'
import { resolveAnchorTime, pickShotIndex } from '../shot-sync.js'

// Build an ElevenLabs-style character alignment from a sentence: each char gets a
// start_time of index * 0.1s. Lets tests assert the resolved timestamp precisely.
function alignmentFor (text) {
  return [...text].map((character, i) => ({ character, start_time: i * 0.1 }))
}

describe('resolveAnchorTime', () => {
  const script = 'Napoleon paces the tent. The Russian winter closes in fast.'
  const alignment = alignmentFor(script)

  it('returns the start_time of the anchor sentence', () => {
    // "The Russian winter" begins at index 25 → 2.5s.
    expect(resolveAnchorTime(alignment, 'The Russian winter')).toBeCloseTo(2.5, 5)
  })

  it('is case- and whitespace-insensitive', () => {
    expect(resolveAnchorTime(alignment, '  the   RUSSIAN   winter ')).toBeCloseTo(2.5, 5)
  })

  it('returns null when the anchor is not a substring of the narration', () => {
    expect(resolveAnchorTime(alignment, 'the Prussian cavalry')).toBeNull()
  })

  it('returns null for empty/short anchors and empty alignment', () => {
    expect(resolveAnchorTime(alignment, '')).toBeNull()
    expect(resolveAnchorTime(alignment, 'too short')).toBeNull() // < 10 chars
    expect(resolveAnchorTime([], 'The Russian winter')).toBeNull()
    expect(resolveAnchorTime(null, 'The Russian winter')).toBeNull()
  })

  it('matches even when the alignment carries different whitespace than the script', () => {
    // Spoken text collapses the double space + newline that the stored script had.
    const spoken = alignmentFor('Napoleon paces the tent.\nThe  Russian winter closes in.')
    expect(resolveAnchorTime(spoken, 'The Russian winter')).not.toBeNull()
  })
})

describe('pickShotIndex', () => {
  // Three shots: shot 0 at t=0, shot 1 at 3s, shot 2 at 7s.
  const plan = [{ time: 0 }, { time: 3 }, { time: 7 }]

  it('holds shot 0 until the first anchor, then advances as time crosses each', () => {
    expect(pickShotIndex(plan, 0, 10)).toBe(0)
    expect(pickShotIndex(plan, 2.9, 10)).toBe(0)
    expect(pickShotIndex(plan, 3, 10)).toBe(1)
    expect(pickShotIndex(plan, 6.9, 10)).toBe(1)
    expect(pickShotIndex(plan, 7, 10)).toBe(2)
    expect(pickShotIndex(plan, 99, 10)).toBe(2)
  })

  it('falls back to an even split for shots with an unresolved anchor time', () => {
    // 3 shots, duration 9 → even boundaries at 3s and 6s.
    const unresolved = [{ time: 0 }, { time: null }, { time: null }]
    expect(pickShotIndex(unresolved, 2, 9)).toBe(0)
    expect(pickShotIndex(unresolved, 3, 9)).toBe(1)
    expect(pickShotIndex(unresolved, 6, 9)).toBe(2)
  })

  it('never advances past shot 0 when duration is unknown and anchors are unresolved', () => {
    const unresolved = [{ time: 0 }, { time: null }]
    expect(pickShotIndex(unresolved, 100, 0)).toBe(0)
  })
})
