import { test, expect } from '@playwright/test'

/**
 * The Play step's scene rail must switch instantly.
 *
 * It used to be wire:click="selectScene", so every thumbnail click was a Livewire round trip that
 * returned a payload built entirely from rows the page already had — about a second of dead time per
 * click on production. Step4Preview now hands the browser a payload for every scene up front and the
 * rail swaps the stage locally, telling the server afterwards without anyone waiting.
 *
 * Lesson 11 is the canvas variant (narration scenes only); lessons containing a map, gallery or
 * voyage scene hand the preview to the real player instead and have no rail.
 */
test('clicking a scene thumbnail swaps the stage without waiting for the server', async ({ page }) => {
    await page.goto('/teacher/lessons/11/wizard?step=5', { waitUntil: 'domcontentloaded' })

    const track = page.locator('#timeline-track')
    await expect(track).toBeVisible()
    await page.waitForTimeout(5_000) // the stage bridge boots asynchronously

    const measurements = await page.evaluate(async () => {
        const rail = document.getElementById('timeline-track')!
        const ids = [...rail.querySelectorAll('[data-thumb]')].map((t) => Number((t as HTMLElement).dataset.sceneId))

        let stageLoads = 0
        window.addEventListener('scene:load-local', () => stageLoads++)

        const results: { swapMs: number; stageSwapped: boolean; ringOnClicked: boolean }[] = []

        for (const id of [ids[2], ids[0], ids[3]].filter(Boolean)) {
            const before = stageLoads
            const started = performance.now()
            rail.querySelector<HTMLElement>(`[data-thumb][data-scene-id="${id}"]`)!.click()
            const swapMs = performance.now() - started

            await new Promise((r) => setTimeout(r, 120))

            results.push({
                swapMs,
                stageSwapped: stageLoads > before,
                ringOnClicked: Number(
                    rail.querySelector<HTMLElement>('[data-thumb][data-thumb-selected]')?.dataset.sceneId,
                ) === id,
            })
        }

        return { thumbs: ids.length, results }
    })

    expect(measurements.thumbs).toBeGreaterThan(1)

    for (const result of measurements.results) {
        // The stage changes in the click handler itself, so this is single-digit milliseconds.
        expect(result.swapMs).toBeLessThan(50)
        expect(result.stageSwapped).toBe(true)
        // And the highlight moves with it, rather than a round trip later.
        expect(result.ringOnClicked).toBe(true)
    }
})
