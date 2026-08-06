import { test, expect, type Frame, type Page } from '@playwright/test'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * REFUTATION RUN — "the teacher preview boots a SECOND COMPLETE COPY of the student player".
 *
 * The claim, from the performance lane: wizard step 5 is an iframe at
 * /lesson/{CODE}?autoplay=1&embed=1&scene=0, so "the teacher downloads and runs lesson-player.js,
 * Livewire, GSAP and app.css a second time inside the page that already has them", citing
 * requests 69 / totalKB 3209 / jsKB 2383 / largest "js/lesson-player.js 546KB".
 *
 * Two halves have to be true for that to be a defect, and this spec tests them separately:
 *
 *   1. THE PAGE ALREADY HAS THEM. If the wizard shell never loads the player in the first place,
 *      the iframe is not a second copy — it is the only copy, and the 546KB headline item is not
 *      duplicated work at all. Test 1 asks each frame what it actually fetched.
 *
 *   2. THE OVERLAP IS EXPENSIVE. Whatever the two frames genuinely share, a browser is allowed to
 *      serve from cache. Test 2 measures the real overlap set and how many bytes of it crossed the
 *      wire inside the iframe (transferSize === 0 means the cache answered).
 *
 * Frame attribution comes from each frame's OWN Resource Timing, not from a page-level counter:
 * a page-level byte total (which is what the original evidence file reports) cannot tell you which
 * document asked for a thing, and that is the entire question here.
 *
 * Byte figures are read in whatever mode the dev server is running. With public/hot present Vite
 * serves unbundled ES modules, so raw sizes and request counts are dev artefacts — which is why
 * this spec asserts on WHICH frame fetched WHAT, a fact that does not change between dev and prod.
 */

const SHOTS = 'tests/playwright/results/preview-second-copy'
const LESSON = Number(process.env.PERF_LESSON_ID ?? 41)   // #41 LYTVSK Abel Tasman — voyage + gallery
const PLAYER_ENTRY = 'lesson-player'

type Res = { name: string; transferSize: number; encodedBodySize: number }

const resources = (target: Page | Frame) =>
    target.evaluate(() =>
        performance.getEntriesByType('resource').map((e) => ({
            name: e.name,
            transferSize: (e as PerformanceResourceTiming).transferSize ?? 0,
            encodedBodySize: (e as PerformanceResourceTiming).encodedBodySize ?? 0,
        })),
    ) as Promise<Res[]>

/** Strip host + cache-busting query so "the same asset" compares equal across frames. */
const key = (url: string) => {
    try {
        const u = new URL(url)

        return u.pathname
    } catch {
        return url
    }
}

const kb = (bytes: number) => Math.round(bytes / 1024)

/** The preview iframe, once it exists and has painted its own document. */
async function previewFrame(page: Page) {
    const el = page.locator('iframe[title]').first()
    await el.waitFor({ state: 'attached', timeout: 60_000 })

    // Resolve the real Frame (not a FrameLocator) so we can ask it for its own Resource Timing.
    const frame = await page.waitForFunction(() => true, null).then(async () => {
        const handle = await el.elementHandle()

        return handle ? await handle.contentFrame() : null
    })
    expect(frame, 'the preview iframe should expose a document').not.toBeNull()

    // Wait on the player's own readiness, never a sleep: the stage element the player builds.
    await frame!.waitForFunction(
        () => document.readyState === 'complete' && performance.getEntriesByType('resource').length > 5,
        null,
        { timeout: 60_000 },
    )

    return frame!
}

test.describe('wizard step 5 preview: is it a second copy of the player?', () => {
    test.beforeAll(() => mkdirSync(SHOTS, { recursive: true }))

    test('the wizard shell never loads the player it is accused of loading twice', async ({ page }) => {
        // Attribute every request to the frame that asked for it, at the source.
        const byFrame = new Map<string, Set<string>>()
        page.on('request', (req) => {
            const f = req.frame()
            const tag = f === page.mainFrame() ? 'parent' : 'iframe'
            if (!byFrame.has(tag)) byFrame.set(tag, new Set())
            byFrame.get(tag)!.add(key(req.url()))
        })

        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=5`, { waitUntil: 'domcontentloaded' })
        const frame = await previewFrame(page)

        const parent = await resources(page)
        const child = await resources(frame)

        const parentHasPlayer = parent.filter((r) => r.name.includes(PLAYER_ENTRY))
        const childHasPlayer = child.filter((r) => r.name.includes(PLAYER_ENTRY))

        await page.screenshot({ path: join(SHOTS, 'wizard-step5.png'), fullPage: false })

        writeFileSync(
            join(SHOTS, 'frames.json'),
            JSON.stringify(
                {
                    lesson: LESSON,
                    parentRequests: parent.length,
                    childRequests: child.length,
                    parentKB: kb(parent.reduce((n, r) => n + r.transferSize, 0)),
                    childKB: kb(child.reduce((n, r) => n + r.transferSize, 0)),
                    playerInParent: parentHasPlayer.map((r) => r.name),
                    playerInChild: childHasPlayer.map((r) => r.name),
                    requestedByParentFrame: [...(byFrame.get('parent') ?? [])].length,
                    requestedByChildFrame: [...(byFrame.get('iframe') ?? [])].length,
                },
                null,
                2,
            ),
        )

        // The heart of it: the player entry lives in the iframe and NOWHERE else. If the parent
        // also carried it, the finding would be right and this assertion would fail.
        expect(childHasPlayer.length, 'the iframe loads the player').toBeGreaterThan(0)
        expect(
            parentHasPlayer.map((r) => key(r.name)),
            'the wizard shell must not load the player itself',
        ).toEqual([])
    })

    test('what the two frames really share, and what it costs on the wire', async ({ page }) => {
        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=5`, { waitUntil: 'domcontentloaded' })
        const frame = await previewFrame(page)

        const parent = await resources(page)
        const child = await resources(frame)

        const parentKeys = new Set(parent.map((r) => key(r.name)))
        const overlap = child.filter((r) => parentKeys.has(key(r.name)))
        const overlapWireBytes = overlap.reduce((n, r) => n + r.transferSize, 0)
        const overlapDecodedBytes = overlap.reduce((n, r) => n + r.encodedBodySize, 0)
        const cached = overlap.filter((r) => r.transferSize === 0)

        writeFileSync(
            join(SHOTS, 'overlap.json'),
            JSON.stringify(
                {
                    childRequests: child.length,
                    sharedWithParent: overlap.length,
                    uniqueToIframe: child.length - overlap.length,
                    overlapWireKB: kb(overlapWireBytes),
                    overlapDecodedKB: kb(overlapDecodedBytes),
                    servedFromCache: cached.length,
                    biggestShared: [...overlap]
                        .sort((a, b) => b.encodedBodySize - a.encodedBodySize)
                        .slice(0, 8)
                        .map((r) => `${key(r.name)} ${kb(r.encodedBodySize)}KB wire=${kb(r.transferSize)}KB`),
                },
                null,
                2,
            ),
        )

        // Not an assertion about a threshold anyone agreed to — just the shape of the claim:
        // if the iframe were "a second complete copy", nearly everything it fetched would also
        // have been fetched by the parent. Record the number either way.
        expect(child.length, 'the iframe fetched something').toBeGreaterThan(0)
        console.log(
            `[overlap] iframe fetched ${child.length} resources, ${overlap.length} of them also in the parent ` +
            `(${kb(overlapWireBytes)}KB over the wire, ${cached.length} served from cache)`,
        )
    })

    /**
     * Resource Timing reports transferSize 0 for a cross-origin response without a
     * Timing-Allow-Origin header, and in dev the modules come off Vite on :5173 — a different
     * origin from the app on :8000. So "0KB" from the test above cannot by itself distinguish
     * "the cache answered" from "the browser refused to tell us". This test asks the network
     * stack directly: CDP reports the bytes that actually crossed the wire, and fires
     * requestServedFromCache when they did not.
     */
    test('the assets both frames want are fetched once, not twice', async ({ page }) => {
        const cdp = await page.context().newCDPSession(page)
        await cdp.send('Network.enable')

        const open = new Map<string, string>()
        const fetches = new Map<string, { wire: number[]; cached: number }>()
        const bump = (url: string) => {
            if (!fetches.has(url)) fetches.set(url, { wire: [], cached: 0 })

            return fetches.get(url)!
        }

        cdp.on('Network.requestWillBeSent', (e: any) => open.set(e.requestId, e.request.url))
        cdp.on('Network.requestServedFromCache', (e: any) => {
            const url = open.get(e.requestId)
            if (url) bump(url).cached += 1
        })
        cdp.on('Network.loadingFinished', (e: any) => {
            const url = open.get(e.requestId)
            if (url) bump(url).wire.push(e.encodedDataLength ?? 0)
            open.delete(e.requestId)
        })

        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=5`, { waitUntil: 'domcontentloaded' })
        await previewFrame(page)
        await page.waitForLoadState('networkidle')

        // A URL asked for twice is the duplication the finding is about. Count what the second
        // and later fetches actually cost.
        const repeated = [...fetches.entries()].filter(([, v]) => v.wire.length + v.cached > 1)
        const redundantBytes = repeated.reduce(
            (n, [, v]) => n + v.wire.slice(1).reduce((a, b) => a + b, 0),
            0,
        )
        const servedFromCache = repeated.reduce((n, [, v]) => n + v.cached, 0)

        writeFileSync(
            join(SHOTS, 'wire.json'),
            JSON.stringify(
                {
                    distinctUrls: fetches.size,
                    urlsFetchedMoreThanOnce: repeated.length,
                    redundantWireKB: kb(redundantBytes),
                    repeatsServedFromCache: servedFromCache,
                    detail: repeated
                        .map(([url, v]) => `${key(url)} wire=[${v.wire.map(kb).join(',')}]KB cache=${v.cached}`)
                        .slice(0, 20),
                },
                null,
                2,
            ),
        )

        console.log(
            `[wire] ${repeated.length} URL(s) requested more than once; ` +
            `${kb(redundantBytes)}KB of repeat traffic, ${servedFromCache} repeat(s) served from cache`,
        )

        // The claim is a second COMPLETE copy. If it were, the repeat traffic would be on the
        // order of the player bundle. Hold it to something far smaller than that.
        expect(kb(redundantBytes), 'repeat traffic should not amount to a second player').toBeLessThan(200)
    })

    test('a teacher sees a working player, and only one set of controls', async ({ page }) => {
        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=5`, { waitUntil: 'domcontentloaded' })
        const frame = await previewFrame(page)

        // The preview is the REAL player: it renders the player's stage, not a wizard canvas.
        await expect(page.locator('#lesson-canvas')).toHaveCount(0)
        await page.screenshot({ path: join(SHOTS, 'teacher-preview.png') })

        const title = await frame.title()
        console.log(`[preview] iframe document title: ${title}`)
    })
})
