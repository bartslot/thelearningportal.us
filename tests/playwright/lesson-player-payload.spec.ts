import { test, expect } from '@playwright/test'

/**
 * The lesson payload is inlined as `window.LESSON` and is by far the heaviest thing on the player
 * page — mostly per-character narration timings. It is emitted with JSON_HEX_TAG only, dropping the
 * quote/apostrophe/ampersand escaping Blade's bare @json adds (~2.5x inflation for no benefit inside
 * a <script> block).
 *
 * This guards that trade: the payload must still parse, and text with apostrophes and quotes must
 * survive intact. `domcontentloaded`, not `networkidle` — Vite's HMR socket keeps the network busy
 * in dev and networkidle never settles.
 */
test('the inlined lesson payload parses, with punctuation intact', async ({ page }) => {
    const failures: string[] = []
    page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`))

    await page.goto('/lesson/YWH3NW', { waitUntil: 'domcontentloaded' })

    const shape = await page.evaluate(() => {
        const lesson = (window as any).LESSON
        const scenes = lesson?.scenes ?? []
        const timed = scenes.filter((s: any) => Array.isArray(s.alignment) && s.alignment.length)

        return {
            parsed: !!lesson,
            title: lesson?.title ?? null,
            scenes: scenes.length,
            timedScenes: timed.length,
            firstTiming: timed[0]?.alignment?.[0] ?? null,
            script: scenes.find((s: any) => s.script)?.script ?? '',
        }
    })

    expect(shape.parsed, 'window.LESSON must parse as an object').toBe(true)
    expect(shape.scenes).toBeGreaterThan(0)
    expect(typeof shape.title).toBe('string')

    // Punctuation is where looser escaping would show up first.
    expect(shape.script).not.toContain('\\u0022')
    expect(shape.script).not.toContain('\\u0027')

    // Narration timings, when present, keep their shape.
    if (shape.timedScenes > 0) {
        expect(shape.firstTiming).toHaveProperty('character')
        expect(shape.firstTiming).toHaveProperty('start_time')
        expect(shape.firstTiming).toHaveProperty('end_time')
    }

    expect(failures.filter((f) => !/WebGL|uniformMatrix4fv/i.test(f))).toEqual([])
})
