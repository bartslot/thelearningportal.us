import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { EASE } from '../easing.js'

/**
 * The app has ONE motion vocabulary, and both halves of the app can say it.
 *
 * resources/js/easing.js exports EASE.enter/exit/move/drawOn/pop, which JS animations pick by
 * intent. CSS could not reach them — a stylesheet cannot import a JS module — so every keyframe
 * hand-copied four numbers instead, and the copies drifted. A Playwright spec
 * (tests/playwright/quiz-card-easing-vocabulary.spec.ts) exists purely because of that drift: it
 * measured a bundle where tuning EASE.enter would have moved nothing in CSS at all, because no CSS
 * rule was on it.
 *
 * app.css now declares the same five curves as `--ease-*` custom properties, which is the only
 * bridge that exists between the two. A mirror is only worth having while it still matches, so:
 *
 *   1. every --ease-* token must equal its EASE.* counterpart, and
 *   2. nothing else in the stylesheet may write one of those curves out by hand.
 *
 * (2) is the half that stops the drift coming back. A curve typed in full is a copy, however
 * correct it is on the day it is typed.
 */

const CSS = readFileSync(resolve(__dirname, '../../css/app.css'), 'utf8')

/** `--ease-pop: cubic-bezier(...)` → { pop: 'cubic-bezier(...)' }, camelCased to match EASE. */
function tokens() {
  const found = {}
  for (const m of CSS.matchAll(/--ease-([a-z-]+):\s*(cubic-bezier\([^)]*\))/g)) {
    const name = m[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase())
    found[name] = m[2]
  }
  return found
}

describe('the CSS easing tokens mirror easing.js', () => {
  it('declares a token for every named curve, and no extras', () => {
    expect(Object.keys(tokens()).sort()).toEqual(Object.keys(EASE).sort())
  })

  for (const [name, curve] of Object.entries(EASE)) {
    it(`--ease-${name.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())} is EASE.${name}`, () => {
      expect(tokens()[name], `app.css has drifted from easing.js for "${name}"`).toBe(curve)
    })
  }

  it('no rule writes a named curve out by hand — that is what drifts', () => {
    // Blank the token block itself: those five ARE the declaration, not copies of it.
    const rules = CSS.replace(/--ease-[a-z-]+:\s*cubic-bezier\([^)]*\)/g, '')

    const copies = Object.entries(EASE)
      .filter(([, curve]) => rules.includes(curve))
      .map(([name, curve]) => `${curve} should be var(--ease-${name.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())})`)

    expect(copies, 'hand-copied curves in app.css').toEqual([])
  })
})
