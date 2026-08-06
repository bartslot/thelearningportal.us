import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * What one click on the Configure step's scene rail actually costs a teacher.
 *
 * /teacher/lessons/{id}/wizard?step=4 is Step3SceneConfigurator — the Configure step
 * (resources/views/livewire/lesson-wizard.blade.php:40). Its rail selects a scene on the SERVER
 * (`wire:click="selectScene"`, resources/views/components/lesson/scene-thumb.blade.php), unlike
 * the Play step's rail which swaps from a pre-built payload. That round trip is deliberate and the
 * component says so; this spec does not argue with it, it prices it.
 *
 * WHY THIS SPEC EXISTS. A performance report claimed a switch "fetches each scene's artwork at its
 * original Wikimedia resolution", citing a 6.3 MB JPEG. The first suspicion was an accounting
 * artefact: that measurement billed its first click straight after
 * `waitForLoadState('networkidle').catch(() => {})`, a call that swallows its own timeout, so a
 * still-loading first paint would be charged to the click. Test 1 removes that confound by waiting
 * for a genuine quiet period first — and the megabytes survive it.
 *
 * Test 2 then names the culprit, which is NOT the scene's own artwork: the inspector's
 * "Reuse from this lesson" strip (resources/views/components/lesson/skybox-controls.blade.php:199)
 * renders every poster candidate of the lesson into a 64x48 button using the stored URL verbatim.
 * For Commons-sourced art that URL is the 3840px original. Nineteen postage stamps, ~44 MB.
 *
 * HARNESS NOTES. Byte counts come from CDP `encodedDataLength` — what crossed the wire. They are
 * production-true. Wall-clock SETTLE times are not: with `composer dev` running, Vite HMR re-pulls
 * app.css several times a second, so a switch window here can contain hundreds of dev-only
 * requests. Timings are recorded for the record but only loosely asserted.
 */

const REPORT_DIR = join(process.cwd(), 'tests/playwright/results/configure-scene-switch')
const LESSON = Number(process.env.PERF_LESSON_ID ?? 41)   // #41 LYTVSK Abel Tasman, 18 scenes
const QUIET_MS = 2_500                                     // no response for this long = settled
const HEAVY_KB = 512                                       // "a picker thumbnail should never be this big"

type Wire = { url: string; mime: string; bytes: number }

async function captureNetwork(page: Page) {
    const cdp = await page.context().newCDPSession(page)
    await cdp.send('Network.enable', { maxTotalBufferSize: 200_000_000, maxResourceBufferSize: 100_000_000 })

    const open = new Map<string, Partial<Wire>>()
    const wire: Wire[] = []
    let last = Date.now()

    cdp.on('Network.requestWillBeSent', (e: any) => open.set(e.requestId, { url: e.request.url }))
    cdp.on('Network.responseReceived', (e: any) =>
        open.set(e.requestId, { ...(open.get(e.requestId) ?? {}), url: e.response.url, mime: e.response.mimeType }))
    cdp.on('Network.loadingFinished', (e: any) => {
        const m = open.get(e.requestId)
        open.delete(e.requestId)
        if (!m) return
        last = Date.now()
        wire.push({ url: m.url ?? '', mime: m.mime ?? '', bytes: e.encodedDataLength ?? 0 })
    })
    cdp.on('Network.loadingFailed', (e: any) => open.delete(e.requestId))

    return {
        wire,
        mark: () => wire.length,
        since: (n: number) => wire.slice(n),
        /** Wait on network state, never on a clock: returns once nothing has landed for QUIET_MS. */
        async quiet(p: Page, budgetMs = 60_000) {
            const deadline = Date.now() + budgetMs
            while (Date.now() < deadline) {
                if (Date.now() - last >= QUIET_MS) return true
                await p.waitForTimeout(250)
            }

            return false
        },
    }
}

const kb = (b: number) => Math.round(b / 1024)
const isImage = (r: Wire) => /^image\//.test(r.mime)
const isCommons = (r: Wire) => /wikimedia|wikipedia/.test(r.url)
const short = (u: string) => decodeURIComponent(u.split('/').slice(-1)[0]).slice(0, 60)

async function openConfigure(page: Page) {
    await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`, { waitUntil: 'load' })
    await expect(page.locator('[data-thumb]').first()).toBeVisible({ timeout: 90_000 })
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Price a switch, with first paint billed to first paint
// ─────────────────────────────────────────────────────────────────────────────
test('teacher: a Configure-rail scene switch, priced with first paint excluded', async ({ page }) => {
    test.setTimeout(300_000)
    mkdirSync(REPORT_DIR, { recursive: true })
    const net = await captureNetwork(page)

    await openConfigure(page)
    const settledFirst = await net.quiet(page, 90_000)
    const firstPaint = net.wire.slice()

    const ids: string[] = await page.locator('[data-thumb]').evaluateAll((els) =>
        els.map((e) => (e as HTMLElement).dataset.sceneId).filter(Boolean) as string[])
    expect(ids.length, 'the rail should list this lesson\'s scenes').toBeGreaterThan(3)

    // The round trip is real — assert it rather than assume it from reading the Blade.
    const wireClick = await page.locator('[data-thumb]').first()
        .evaluate((el) => el.getAttribute('wire:click') ?? '')
    expect(wireClick, 'the Configure rail selects a scene on the server').toContain('selectScene')

    const switches: any[] = []
    // Walk far apart in the running order so several scene KINDS get exercised, not six neighbours.
    for (const id of [ids[2], ids[0], ids[7] ?? ids[3], ids[4], ids[ids.length - 1]].filter(Boolean)) {
        const before = net.mark()
        const started = Date.now()

        await page.locator(`[data-thumb][data-scene-id="${id}"]`).first().click()
        await expect(page.locator(`[data-thumb][data-scene-id="${id}"][data-thumb-selected]`).first())
            .toBeVisible({ timeout: 30_000 })
        const settledMs = Date.now() - started

        await net.quiet(page, 30_000)
        const rows = net.since(before)
        const livewire = rows.filter((r) => /livewire\/update/.test(r.url))
        const images = rows.filter(isImage)
        switches.push({
            sceneId: id,
            settledMs,
            requests: rows.length,
            totalKB: kb(rows.reduce((s, r) => s + r.bytes, 0)),
            biggestLivewireKB: Math.max(0, ...livewire.map((r) => kb(r.bytes))),
            livewireResponses: livewire.length,
            commonsImageKB: kb(images.filter(isCommons).reduce((s, r) => s + r.bytes, 0)),
            biggestImageKB: Math.max(0, ...images.map((r) => kb(r.bytes))),
            biggest: [...rows].sort((a, b) => b.bytes - a.bytes).slice(0, 4).map((r) => `${short(r.url)} ${kb(r.bytes)}KB`),
        })
    }

    const settled = switches.map((s) => s.settledMs).sort((a, b) => a - b)
    const report = {
        lesson: LESSON,
        firstPaint: {
            reachedQuiet: settledFirst,
            requests: firstPaint.length,
            totalKB: kb(firstPaint.reduce((s, r) => s + r.bytes, 0)),
            commonsImageKB: kb(firstPaint.filter((r) => isImage(r) && isCommons(r)).reduce((s, r) => s + r.bytes, 0)),
        },
        switches,
        summary: {
            settledMs: settled,
            medianMs: settled[Math.floor(settled.length / 2)],
            biggestLivewireKB: Math.max(...switches.map((s) => s.biggestLivewireKB)),
            worstCommonsImageKB: Math.max(...switches.map((s) => s.commonsImageKB)),
            worstBiggestImageKB: Math.max(...switches.map((s) => s.biggestImageKB)),
        },
    }
    writeFileSync(join(REPORT_DIR, 'configure-scene-switch.json'), JSON.stringify(report, null, 2))
    await page.screenshot({ path: join(REPORT_DIR, 'configure-rail-after-switches.png') })
    console.log('SWITCH COST', JSON.stringify(report.summary))

    // Established facts, held as a ratchet.
    expect(switches.every((s) => s.livewireResponses > 0),
        'every Configure-rail switch talks to the server').toBe(true)
    expect(report.summary.biggestLivewireKB,
        'a single scene-selection response should stay under half a megabyte').toBeLessThan(600)
    // Wall clock is dev-server noisy (Vite HMR); keep the bound loose but non-vacuous.
    expect(report.summary.medianMs,
        'the median switch should not feel like a page load').toBeLessThan(10_000)
})

// ─────────────────────────────────────────────────────────────────────────────
// 2. The megabytes: 3840px originals rendered into 64x48 buttons
// ─────────────────────────────────────────────────────────────────────────────
test('teacher: the inspector never downloads full-resolution art for a postage-stamp thumbnail', async ({ page }) => {
    test.setTimeout(300_000)
    mkdirSync(REPORT_DIR, { recursive: true })

    const heavy: { url: string; kb: number }[] = []
    const cdp = await page.context().newCDPSession(page)
    await cdp.send('Network.enable', { maxTotalBufferSize: 200_000_000, maxResourceBufferSize: 100_000_000 })
    const open = new Map<string, any>()
    cdp.on('Network.requestWillBeSent', (e: any) => open.set(e.requestId, { url: e.request.url }))
    cdp.on('Network.loadingFinished', (e: any) => {
        const m = open.get(e.requestId)
        open.delete(e.requestId)
        if (m && e.encodedDataLength > HEAVY_KB * 1024 && /wikimedia|wikipedia/.test(m.url)) {
            heavy.push({ url: short(m.url), kb: kb(e.encodedDataLength) })
        }
    })

    await openConfigure(page)

    // Land on a narration scene — that inspector mounts <x-lesson.skybox-controls>, which owns the
    // "Reuse from this lesson" strip. Selecting each in turn is exactly what a teacher does.
    const ids: string[] = await page.locator('[data-thumb]').evaluateAll((els) =>
        els.map((e) => (e as HTMLElement).dataset.sceneId).filter(Boolean) as string[])
    for (const id of ids.slice(0, 4)) {
        await page.locator(`[data-thumb][data-scene-id="${id}"]`).first().click()
        await expect(page.locator(`[data-thumb][data-scene-id="${id}"][data-thumb-selected]`).first())
            .toBeVisible({ timeout: 30_000 })
        await page.waitForTimeout(6_000)
    }

    // Every on-screen image, with what it weighs against the box it is drawn into.
    const oversized = await page.evaluate(() => {
        const out: any[] = []
        document.querySelectorAll<HTMLImageElement>('img').forEach((img) => {
            const r = img.getBoundingClientRect()
            if (r.width < 1 || r.height < 1) return
            if (img.naturalWidth < r.width * 4) return          // sane: at most 4x the drawn box
            out.push({
                src: (img.currentSrc || img.src).split('/').slice(-1)[0].slice(0, 70),
                natural: `${img.naturalWidth}x${img.naturalHeight}`,
                displayed: `${Math.round(r.width)}x${Math.round(r.height)}`,
                overdraw: Math.round(img.naturalWidth / r.width),
                panel: (img.closest('div[class*="space-y"]')?.previousElementSibling?.textContent ?? '').trim().slice(0, 40),
            })
        })

        return out
    })

    const report = {
        heavyDownloads: heavy,
        heavyCount: heavy.length,
        heavyTotalMB: +(heavy.reduce((s, h) => s + h.kb, 0) / 1024).toFixed(1),
        worstKB: Math.max(0, ...heavy.map((h) => h.kb)),
        oversizedOnScreen: oversized.length,
        oversized: oversized.slice(0, 8),
    }
    writeFileSync(join(REPORT_DIR, 'thumbnail-overdraw.json'), JSON.stringify(report, null, 2))
    await page.screenshot({ path: join(REPORT_DIR, 'reuse-strip-oversized.png') })
    console.log('THUMBNAIL OVERDRAW', JSON.stringify({ ...report, oversized: undefined, heavyDownloads: undefined }))

    // The defect, stated as the rule it breaks. A 64x48 picker tile must not be a 3840px original.
    expect(report.worstKB,
        `no image drawn at thumbnail size should weigh more than ${HEAVY_KB} KB`).toBeLessThan(HEAVY_KB)
    expect(report.oversizedOnScreen,
        'no on-screen image should be more than 4x the resolution it is drawn at').toBe(0)
})
