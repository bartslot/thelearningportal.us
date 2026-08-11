import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import theme from '../theme.json'

/**
 * theme.json supplies the paint for the layers the map builds in its `load` handler, BEFORE
 * applyMapStyle has run and recoloured them from MAP_STYLES.
 *
 * Why this test exists, stated plainly: `map.addLayer` runs in one long unguarded block, so a
 * property read that throws does not fail that ONE layer — it abandons every line after it. A
 * missing `theme.text` took out the remaining layers, all seven custom globe layers
 * (starfield, sun, moon, ocean, daylight, clouds, atmosphere) and every settings-panel group,
 * and reported nothing but a single TypeError. The map still drew, in flat base colours, which
 * is exactly what makes it hard to spot: it looks like a style choice, not a crash.
 *
 * So the assertion is not "theme.json looks sensible". It is: every key index.js dereferences
 * during init is present, checked against the source rather than a list kept in step by hand.
 */
describe('theme.json covers what the map reads at init', () => {
  const source = readFileSync(resolve(process.cwd(), 'resources/js/timemap/index.js'), 'utf8')

  /** Every `theme.a.b` in index.js — the reads that throw if the middle object is missing. */
  const nestedReads = [...source.matchAll(/\btheme\.([a-zA-Z]+)\.([a-zA-Z]+)/g)]
    .map(([, group, key]) => ({ group, key }))

  it('finds the nested reads it is meant to be checking', () => {
    // A positive control. If index.js stops using `theme.x.y` this test must fail loudly rather
    // than pass by having nothing left to assert.
    expect(nestedReads.length).toBeGreaterThan(0)
    expect(nestedReads.map((r) => r.group)).toContain('text')
  })

  it('defines every group that index.js dereferences', () => {
    const missing = [...new Set(nestedReads.map((r) => r.group))].filter((g) => !theme[g])
    expect(missing).toEqual([])
  })

  it('defines every key read off those groups', () => {
    const missing = nestedReads
      .filter((r) => theme[r.group] && theme[r.group][r.key] === undefined)
      .map((r) => `theme.${r.group}.${r.key}`)
    expect([...new Set(missing)]).toEqual([])
  })

  it('gives labels a colour and a halo, since a label needs both to be legible', () => {
    expect(theme.text).toBeDefined()
    expect(theme.text.color).toMatch(/^#[0-9a-f]{3,8}$/i)
    expect(theme.text.halo).toMatch(/^#[0-9a-f]{3,8}$/i)
  })
})
