import { test, expect } from '@playwright/test'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'

/**
 * Captures the screenshots the help centre shows, one set per language.
 *
 * Driven by `php artisan help:screenshots`, which sets the demo teacher's language, resolves every
 * named route to a real URL, and passes the work list in HELP_SHOTS. Running this spec on its own
 * does nothing useful — it needs that manifest.
 *
 * Each shot is a real page of the running app, so the pictures can never describe an interface that
 * no longer exists: re-run the command after a UI change and they are current again.
 */

type Shot = {
    key: string
    url: string
    /** Element to wait for before shooting, so a half-rendered page is never captured. */
    waitFor?: string
    /** Extra settle time for pages that animate in (maps, canvases). */
    settleMs?: number
    fullPage?: boolean
    /** WebGL pages: headless Chromium renders through swiftshader and complains regardless. */
    allowConsoleErrors?: boolean
}

/** Console noise produced by the software GL used in headless runs, not by the app. */
const HEADLESS_GL_NOISE = [
    /uniformMatrix4fv/i,
    /WebGL|WebGL2RenderingContext/i,
    /swiftshader/i,
]

const locale = process.env.HELP_LOCALE ?? 'en'
const outputDir = process.env.HELP_OUTPUT_DIR
const shots: Shot[] = JSON.parse(process.env.HELP_SHOTS ?? '[]')

test.describe(`help screenshots (${locale})`, () => {
    test.skip(shots.length === 0 || !outputDir, 'Run through: php artisan help:screenshots')

    test.use({ viewport: { width: 1440, height: 900 } })

    for (const shot of shots) {
        test(`capture ${shot.key}`, async ({ page }) => {
            const failures: string[] = []
            page.on('pageerror', (error) => failures.push(error.message))

            await page.goto(shot.url, { waitUntil: 'networkidle' })

            if (shot.waitFor) {
                await page.waitForSelector(shot.waitFor, { timeout: 20_000 })
            }

            // Local-only chrome that would otherwise appear in every help image.
            await page.addStyleTag({
                content: `
                    [data-dev-panel] { display: none !important; }
                    [data-welcome-tour] { display: none !important; }
                    *, *::before, *::after {
                        animation-duration: 0s !important;
                        transition-duration: 0s !important;
                    }
                `,
            })

            // Let fonts and lazily-loaded imagery land before the shutter.
            await page.evaluate(() => document.fonts?.ready)
            await page.waitForTimeout(shot.settleMs ?? 400)

            const path = `${outputDir}/${shot.key}.png`
            mkdirSync(dirname(path), { recursive: true })
            await page.screenshot({ path, fullPage: shot.fullPage ?? false })

            const real = failures.filter((message) => !HEADLESS_GL_NOISE.some((pattern) => pattern.test(message)))

            if (shot.allowConsoleErrors) {
                if (real.length > 0) {
                    console.warn(`[${shot.key}] page errors (not failing this shot): ${real.join(' | ')}`)
                }
                return
            }

            expect(real, `console errors while capturing ${shot.key}: ${real.join(' | ')}`).toEqual([])
        })
    }

    test.afterAll(async () => {
        if (!outputDir) return
        // A manifest makes it obvious which language a set belongs to and when it was taken.
        writeFileSync(
            `${outputDir}/captured.json`,
            JSON.stringify({ locale, keys: shots.map((s) => s.key) }, null, 2),
        )
    })
})
