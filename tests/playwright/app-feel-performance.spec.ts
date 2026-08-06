import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'
import { gzipSync } from 'node:zlib'
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * "Does it feel like an app?" — measured, not opined.
 *
 * Every number below comes out of a real Chromium. Bytes are read from the Chrome DevTools
 * Protocol (`Network.loadingFinished.encodedDataLength` — what actually crossed the wire, headers
 * included); timings come from the page's own Navigation Timing and a PerformanceObserver on
 * long tasks.
 *
 * ONE THING TO KNOW BEFORE READING THE BYTE COLUMNS. This repo is normally run with
 * `composer dev`, so `public/hot` exists and Vite serves unbundled ES modules — a dev page pulls
 * hundreds of tiny files instead of a handful of chunks. Those request COUNTS are an artefact of
 * dev mode. The latencies, the Livewire round trips, the idle poll traffic and the student's
 * press-Start-to-first-word time are not: they are the same code paths a teacher hits in
 * production.
 *
 * So the bundle question is answered separately and exactly, from the built manifest in
 * public/build — the last test in this file. Both halves are measured; neither is guessed.
 *
 * INSTRUMENTATION NOTE, learned the hard way: do NOT replace `window.Audio`. The player builds
 * narration with `new Audio()`, and swapping the constructor for a wrapper stops the title screen
 * dead — Start does nothing. Patch `HTMLMediaElement.prototype.play` instead; it is invisible to
 * the player and records the same moment.
 */

const REPORT_DIR = process.env.PERF_REPORT_DIR ?? join(process.cwd(), 'tests/playwright/results/app-feel')

const TEACHER_LESSON = Number(process.env.PERF_LESSON_ID ?? 41)        // #41 LYTVSK Abel Tasman
const STUDENT_CODES = (process.env.PERF_LESSON_CODES ?? 'LYTVSK,CF0AVG').split(',')

// ─────────────────────────────────────────────────────────────────────────────
// Network capture over CDP
// ─────────────────────────────────────────────────────────────────────────────

type Wire = { url: string; kind: string; mime: string; status: number; bytes: number }

async function captureNetwork(page: Page) {
    const cdp = await page.context().newCDPSession(page)
    await cdp.send('Network.enable', { maxTotalBufferSize: 200_000_000, maxResourceBufferSize: 100_000_000 })

    const open = new Map<string, Partial<Wire>>()
    const wire: Wire[] = []

    cdp.on('Network.requestWillBeSent', (e: any) =>
        open.set(e.requestId, { url: e.request.url, kind: e.type ?? 'Other' }))
    cdp.on('Network.responseReceived', (e: any) =>
        open.set(e.requestId, {
            ...(open.get(e.requestId) ?? {}),
            url: e.response.url,
            mime: e.response.mimeType,
            status: e.response.status,
            kind: e.type ?? open.get(e.requestId)?.kind ?? 'Other',
        }))
    cdp.on('Network.loadingFinished', (e: any) => {
        const m = open.get(e.requestId)
        if (!m) return
        wire.push({
            url: m.url ?? '', kind: m.kind ?? 'Other', mime: m.mime ?? '',
            status: m.status ?? 0, bytes: e.encodedDataLength ?? 0,
        })
        open.delete(e.requestId)
    })
    cdp.on('Network.loadingFailed', (e: any) => open.delete(e.requestId))

    return {
        wire,
        /** Bill one interaction rather than the whole page: mark, act, then read `since(mark)`. */
        mark: () => wire.length,
        since: (n: number) => wire.slice(n),
    }
}

const isJs = (r: Wire) => /javascript|ecmascript/i.test(r.mime) || (r.kind === 'Script' && !/css/i.test(r.mime))
const isCss = (r: Wire) => /text\/css/i.test(r.mime) || r.kind === 'Stylesheet'
const kb = (b: number) => Math.round(b / 1024)
const short = (u: string) => {
    try { return new URL(u).pathname.split('/').slice(-2).join('/') } catch { return u.slice(-48) }
}

function summarise(rows: Wire[]) {
    const sum = (rs: Wire[]) => rs.reduce((s, r) => s + r.bytes, 0)
    const js = rows.filter(isJs)
    const media = rows.filter((r) => /^(audio|video)\//.test(r.mime))

    return {
        requests: rows.length,
        totalKB: kb(sum(rows)),
        jsKB: kb(sum(js)),
        jsRequests: js.length,
        cssKB: kb(sum(rows.filter(isCss))),
        imageKB: kb(sum(rows.filter((r) => /^image\//.test(r.mime)))),
        mediaKB: kb(sum(media)),
        livewireKB: kb(sum(rows.filter((r) => /livewire\/update/.test(r.url)))),
        largest: [...rows].sort((a, b) => b.bytes - a.bytes).slice(0, 8)
            .map((r) => `${short(r.url)} ${kb(r.bytes)}KB`),
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Page instrumentation
// ─────────────────────────────────────────────────────────────────────────────

const INSTRUMENT = `
  window.__longTasks = [];
  try {
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) window.__longTasks.push([e.startTime, e.startTime + e.duration]);
    }).observe({ type: 'longtask', buffered: true });
  } catch (_) {}

  // Narration is built with new Audio() and never enters the DOM, so there is nothing to query.
  // Wrapping play() on the prototype records the moment sound actually starts without touching
  // the constructor the player depends on.
  window.__audioMarks = [];
  (function () {
    const proto = window.HTMLMediaElement && HTMLMediaElement.prototype;
    if (!proto || proto.__perfPatched) return;
    const play = proto.play;
    proto.play = function () {
      try {
        this.addEventListener('playing', () => window.__audioMarks.push({
          t: performance.now(), src: this.currentSrc || this.src || '', tag: this.tagName,
        }), { once: true });
      } catch (_) {}
      return play.apply(this, arguments);
    };
    proto.__perfPatched = true;
  })();
`

async function timings(page: Page) {
    return page.evaluate(() => {
        const nav = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined
        const fcp = performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? 0
        const tasks = ((window as any).__longTasks as [number, number][] ?? []).sort((a, b) => a[0] - b[0])

        // TTI: the first moment after DOMContentLoaded/FCP with a 500ms window free of long tasks.
        let t = Math.max(fcp, nav?.domContentLoadedEventEnd ?? 0)
        for (const [start, end] of tasks) {
            if (end <= t) continue
            if (start - t >= 500) break
            t = end
        }

        return {
            ttfbMs: Math.round(nav?.responseStart ?? 0),
            htmlDoneMs: Math.round(nav?.responseEnd ?? 0),
            fcpMs: Math.round(fcp),
            domContentLoadedMs: Math.round(nav?.domContentLoadedEventEnd ?? 0),
            loadMs: Math.round(nav?.loadEventEnd ?? 0),
            ttiMs: Math.round(t),
            longTasks: tasks.length,
            longestTaskMs: Math.round(Math.max(0, ...tasks.map(([s, e]) => e - s))),
        }
    })
}

/**
 * Each record is written to its own file the moment it is taken. Playwright restarts the worker
 * process after a failed test, so anything accumulated in a module-level object is lost — the
 * first version of this file collected six measurements and saved one.
 */
function record(slug: string, data: unknown) {
    if (!existsSync(REPORT_DIR)) mkdirSync(REPORT_DIR, { recursive: true })
    writeFileSync(join(REPORT_DIR, `${slug}.json`), JSON.stringify(data, null, 2))
    console.log(`\n── ${slug}\n${JSON.stringify(data, null, 2)}`)
}

const shot = (page: Page, name: string) => {
    if (!existsSync(REPORT_DIR)) mkdirSync(REPORT_DIR, { recursive: true })
    return page.screenshot({ path: join(REPORT_DIR, `${name}.png`) })
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Teacher: the dashboard
// ─────────────────────────────────────────────────────────────────────────────

test('teacher: the dashboard becomes interactive', async ({ page }) => {
    await page.addInitScript(INSTRUMENT)
    const net = await captureNetwork(page)

    await page.goto('/teacher', { waitUntil: 'load' })
    await page.waitForLoadState('networkidle').catch(() => {})

    const measured = { url: page.url(), ...(await timings(page)), ...summarise(net.wire) }
    await shot(page, 'teacher-dashboard')
    record('teacher-dashboard', measured)

    expect(page.url()).toContain('/teacher')
    // Eight seconds to become interactive is the line between "app" and "website".
    expect(measured.ttiMs).toBeLessThan(8_000)
})

// ─────────────────────────────────────────────────────────────────────────────
// 2. Teacher: opening the scene editor
// ─────────────────────────────────────────────────────────────────────────────

test('teacher: opening the scene editor on the Tasman lesson', async ({ page }) => {
    test.setTimeout(150_000)
    await page.addInitScript(INSTRUMENT)
    const net = await captureNetwork(page)

    await page.goto(`/teacher/lessons/${TEACHER_LESSON}/wizard?step=4`, { waitUntil: 'load' })

    // Wait on state, not on a clock: the scene rail is the editor's first real affordance.
    await expect(page.locator('[data-thumb]').first()).toBeVisible({ timeout: 90_000 })
    const railVisibleMs = Math.round(await page.evaluate(() => performance.now()))
    await page.waitForLoadState('networkidle').catch(() => {})

    const measured = {
        railVisibleMs,
        thumbs: await page.locator('[data-thumb]').count(),
        ...(await timings(page)),
        ...summarise(net.wire),
    }
    await shot(page, 'wizard-open')
    record('teacher-wizard-open', measured)

    expect(measured.thumbs).toBeGreaterThan(1)
    expect(measured.ttiMs).toBeLessThan(30_000)
})

// ─────────────────────────────────────────────────────────────────────────────
// 3. Teacher: switching scenes, and what standing still costs
// ─────────────────────────────────────────────────────────────────────────────

test('teacher: switching scene, and the idle cost of the editor', async ({ page }) => {
    test.setTimeout(210_000)
    await page.addInitScript(INSTRUMENT)
    const net = await captureNetwork(page)

    await page.goto(`/teacher/lessons/${TEACHER_LESSON}/wizard?step=4`, { waitUntil: 'load' })
    await expect(page.locator('[data-thumb]').first()).toBeVisible({ timeout: 90_000 })
    await page.waitForLoadState('networkidle').catch(() => {})

    const ids = await page.locator('[data-thumb]').evaluateAll((els) =>
        els.map((e) => (e as HTMLElement).dataset.sceneId).filter(Boolean) as string[])
    expect(ids.length).toBeGreaterThan(2)

    const switches: any[] = []
    for (const id of [ids[2], ids[0], ids[3]].filter(Boolean)) {
        const before = net.mark()
        const started = Date.now()

        await page.locator(`[data-thumb][data-scene-id="${id}"]`).first().click()
        // Done when the server has agreed the selection moved and the thumb is marked.
        await expect(page.locator(`[data-thumb][data-scene-id="${id}"][data-thumb-selected]`).first())
            .toBeVisible({ timeout: 30_000 })
        const settledMs = Date.now() - started

        await page.waitForTimeout(1_500)   // let that scene's media land before billing it
        const rows = net.since(before)
        switches.push({
            sceneId: id,
            settledMs,
            // The switch time tracks this, not the server: the editor fetches each scene's
            // artwork at its original resolution. One switch pulled a 6.3 MB JPEG.
            biggestImageKB: Math.max(0, ...rows.filter((r) => /^image\//.test(r.mime)).map((r) => kb(r.bytes))),
            ...summarise(rows),
        })
    }
    record('teacher-scene-switch', switches)

    // Now stand still and see what the editor spends anyway.
    //
    // Two caveats travel with this number, both measured rather than assumed. Livewire suspends
    // `wire:poll` when the tab is not the user's focus, and it also backs off on its own — across
    // runs this window caught anywhere from 0 to 1 poll in 12–20s against a declared 3s cadence.
    // So the poll COUNT here is a floor, not the teacher's cadence. What is exact, and what
    // actually matters, is the SIZE of a single `livewire/update` response.
    const idleFrom = net.mark()
    const idleStart = Date.now()
    await page.waitForTimeout(20_000)
    const idle = net.since(idleFrom)
    const polls = idle.filter((r) => /livewire\/update/.test(r.url))
    const pollBytes = polls.reduce((s, r) => s + r.bytes, 0)

    // Every Livewire response the editor produced in this whole test — switches and polls alike.
    // They are the same round trip and the same payload, so size them together.
    const allLivewire = net.wire.filter((r) => /livewire\/update/.test(r.url))
    const biggestLivewireKB = Math.max(0, ...allLivewire.map((r) => kb(r.bytes)))
    const avgLivewireKB = allLivewire.length
        ? Math.round(allLivewire.reduce((s, r) => s + r.bytes, 0) / allLivewire.length / 1024) : 0

    const idleReport = {
        windowSeconds: (Date.now() - idleStart) / 1000,
        pageVisibility: await page.evaluate(() => ({ state: document.visibilityState, focused: document.hasFocus() })),
        requests: idle.length,
        totalKB: kb(idle.reduce((s, r) => s + r.bytes, 0)),
        idleSample: idle.slice(0, 10).map((r) => `${short(r.url)} ${kb(r.bytes)}KB`),
        livewirePollsSeen: polls.length,
        livewirePollKB: kb(pollBytes),
        livewireResponsesThisTest: allLivewire.length,
        livewireSizesKB: allLivewire.map((r) => kb(r.bytes)),
        avgLivewireKB,
        biggestLivewireKB,
        // What the declared 3s cadence would cost an idle editor at the measured response size.
        ifPolledEvery3s: {
            perMinuteMB: +(avgLivewireKB * 20 / 1024).toFixed(1),
            perHourMB: +(avgLivewireKB * 20 * 60 / 1024).toFixed(0),
        },
    }
    await shot(page, 'wizard-after-switch')
    record('teacher-editor-idle', idleReport)

    /*
     * Budgets pinned to what was measured across six runs on 2026-08-05. Not aspirations — a
     * ratchet, so the editor cannot quietly get slower or heavier.
     *
     * Switch times are asserted on the median, not on every sample, and deliberately so. Ten
     * measured switches ran 0.8 / 1.4 / 1.7 / 1.7 / 2.2 / 2.3 / 3.2 / 3.5 / 7.4 / 13.2 seconds:
     * a typical switch is fine and the tail is awful, and the tail is not noise — it is whichever
     * scene happens to hold full-resolution artwork. Asserting the median keeps this spec a
     * regression guard rather than a coin flip, and `biggestImageKB` in the record above says why
     * the slow ones were slow.
     */
    const settled = switches.map((s) => s.settledMs).sort((a, b) => a - b)
    const median = settled[Math.floor(settled.length / 2)]

    expect(median, 'the median scene switch should not feel like a page load').toBeLessThan(5_000)
    expect(Math.max(...settled), 'no scene switch should ever take this long').toBeLessThan(20_000)
    expect(allLivewire.length, 'the editor talks to the server on every switch').toBeGreaterThan(0)
    expect(biggestLivewireKB, 'a single Livewire response should not be half a megabyte').toBeLessThan(600)
})

// ─────────────────────────────────────────────────────────────────────────────
// 4. Teacher: the preview step
// ─────────────────────────────────────────────────────────────────────────────

test('teacher: opening the preview step', async ({ page }) => {
    test.setTimeout(150_000)
    await page.addInitScript(INSTRUMENT)
    const net = await captureNetwork(page)

    await page.goto(`/teacher/lessons/${TEACHER_LESSON}/wizard?step=5`, { waitUntil: 'load' })

    // For a lesson holding map / gallery / voyage scenes the preview is not a canvas rail — it is
    // the whole student player, embedded in an iframe. The teacher pays for a second player boot.
    const embedded = page.frameLocator('iframe')
    const start = embedded.getByRole('button', { name: /start lesson/i }).first()
    await expect(start).toBeVisible({ timeout: 90_000 })
    const previewReadyMs = Math.round(await page.evaluate(() => performance.now()))
    await page.waitForLoadState('networkidle').catch(() => {})

    const embedUrl = page.frames().map((f) => f.url()).find((u) => /\/lesson\//.test(u)) ?? null
    const measured = {
        previewReadyMs,
        embeds: embedUrl,
        ...(await timings(page)),
        ...summarise(net.wire),
    }
    await shot(page, 'wizard-preview')
    record('teacher-preview', measured)

    expect(previewReadyMs).toBeLessThan(60_000)
})

// ─────────────────────────────────────────────────────────────────────────────
// 5. Student: open a lesson and get to the first narrated word
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Everything a student can press to move the lesson forward, in PRIORITY order — not DOM order.
 *
 * The order matters more than it looks. The intro challenge ends on a "join the leaderboard" card
 * holding a name field, a Submit button and a Skip link, with Submit first in the DOM. A loop that
 * simply takes the first match presses Submit on an empty field forever: it is accepted, nothing
 * happens, and no message says why (measured: 222 presses, no state change). Skip is the honest
 * student action here, so it is ranked above Submit.
 */
/*
 * Selected structurally, not by label. The player's own chrome does not reliably come back in the
 * lesson's language — the same English lesson served "Continue", "Continuer" and "Verder" on three
 * consecutive loads — so a text selector silently stops finding the button and the student looks
 * stuck. The Alpine binding is the same in every language.
 */
const ADVANCE = [
    'button[data-opt="0"]:not([disabled])',       // an intro-challenge answer
    'button[x-show*="showMapContinue"]',          // the map block's Continue, whatever it is called
    'button:has-text("Continue")',                // fallbacks, in case the binding is refactored
    'button:has-text("Continuer")',
    'button:has-text("Verder")',
    'button:has-text("Doorgaan")',
    'button[data-done]',                          // "Skip ›" past the leaderboard card
]

/** SFX are audio too. The question is when the student hears a NARRATOR. */
const isNarration = (src: string) => /\/storage\/lessons\/.*\.(mp3|m4a|wav|ogg)/.test(src) || /narration/.test(src)

for (const code of STUDENT_CODES) {
    test(`student: /lesson/${code} — press Start, reach the first narrated word`, async ({ page }) => {
        test.setTimeout(240_000)
        await page.addInitScript(INSTRUMENT)
        const net = await captureNetwork(page)

        // Vite's dev server occasionally decides an HMR update needs a full reload, which lands
        // the student back on the title screen mid-lesson and destroys the execution context.
        // That is the harness, not the app, so the run is repeated rather than reported.
        let reloads = 0
        page.on('framenavigated', (f) => { if (f === page.mainFrame()) reloads++ })

        const runOnce = async () => {
            await page.goto(`/lesson/${code}`, { waitUntil: 'load' })
            const reloadsAtStart = reloads

            const start = page.getByRole('button', { name: /start lesson/i }).first()
            await expect(start).toBeVisible({ timeout: 60_000 })
            const titleScreenReadyMs = Math.round(await page.evaluate(() => performance.now()))

            const onLoad = { ...(await timings(page)), ...summarise(net.wire) }
            const beforeStart = net.mark()

            const t0 = await page.evaluate(() => performance.now())
            await start.click()

            // Drive it like a student in a hurry: press whatever the player offers, as soon as it
            // is offered, until a narrator is audible. The click COUNT is the interesting number —
            // it is how many taps stand between opening a lesson and the lesson talking to you.
            const heardNarration = () => page.evaluate(
                (pattern) => ((window as any).__audioMarks ?? []).some((m: any) => new RegExp(pattern).test(m.src)),
                '\\/storage\\/lessons\\/.*\\.(mp3|m4a|wav|ogg)',
            ).catch(() => false)

            let clicks = 0
            let firstGateMs: number | null = null
            const trail: string[] = []
            const deadline = Date.now() + 150_000

            while (Date.now() < deadline && !(await heardNarration())) {
                if (reloads > reloadsAtStart) return null      // the page went away under us

                let pressed = false
                for (const selector of ADVANCE) {
                    const el = page.locator(selector).first()
                    if (!(await el.count().catch(() => 0))) continue
                    if (!(await el.isVisible().catch(() => false))) continue

                    if (firstGateMs === null) {
                        const now = await page.evaluate(() => performance.now()).catch(() => null)
                        if (now === null) return null
                        firstGateMs = Math.round(now - t0)
                    }
                    trail.push((await el.innerText().catch(() => '?')).replace(/\s+/g, ' ').trim().slice(0, 34) || selector)
                    await el.click({ force: true }).catch(() => {})
                    clicks++
                    pressed = true
                    await page.waitForTimeout(500)
                    break
                }
                if (!pressed) await page.waitForTimeout(400)

                // A control pressed ten times in a row with the lesson still where it was is a
                // dead end, not a slow one. Stop, and let the report say so.
                const last = trail.slice(-10)
                if (last.length === 10 && new Set(last).size === 1) break
            }

            const marks: any[] = await page.evaluate(() => (window as any).__audioMarks ?? []).catch(() => [])
            const firstNarration = marks.find((m) => isNarration(m.src))
            const firstAnySound = marks[0]

            return {
                titleScreenReadyMs,
                startToFirstGateMs: firstGateMs,
                clicksBeforeFirstNarration: clicks,
                gateTrail: trail,
                startToFirstSoundMs: firstAnySound ? Math.round(firstAnySound.t - t0) : null,
                firstSoundWas: firstAnySound ? short(firstAnySound.src) : null,
                startToFirstNarrationMs: firstNarration ? Math.round(firstNarration.t - t0) : null,
                firstNarrationSrc: firstNarration ? short(firstNarration.src) : null,
                reachedNarration: Boolean(firstNarration),
                onLoad,
                afterStartPress: summarise(net.since(beforeStart)),
            }
        }

        let measured = await runOnce()
        if (measured === null) measured = await runOnce()
        expect(measured, 'the dev server reloaded the page twice mid-run').not.toBeNull()

        await shot(page, `student-${code}`)
        record(`student-${code}`, measured)

        expect(measured!.titleScreenReadyMs, 'the title screen has to be pressable').toBeLessThan(30_000)
        expect(measured!.reachedNarration, 'a student pressing forward should reach narration').toBe(true)
        expect(measured!.startToFirstNarrationMs!).toBeLessThan(120_000)
    })
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. The production payload — what a teacher actually downloads
// ─────────────────────────────────────────────────────────────────────────────

test('production payload: the built chunk graph per entry', async () => {
    const root = process.cwd()
    const manifestPath = join(root, 'public/build/manifest.json')
    expect(existsSync(manifestPath), `no built manifest at ${manifestPath}`).toBe(true)

    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8')) as Record<string, any>
    const sizeCache = new Map<string, { raw: number; gz: number }>()

    const sizeOf = (file: string) => {
        if (!sizeCache.has(file)) {
            const p = join(root, 'public/build', file)
            const buf = existsSync(p) ? readFileSync(p) : Buffer.alloc(0)
            sizeCache.set(file, { raw: buf.length, gz: buf.length ? gzipSync(buf).length : 0 })
        }
        return sizeCache.get(file)!
    }

    /** Static imports block the entry; dynamic imports do not, so they are counted separately. */
    const graph = (keys: string[]) => {
        const eager = new Set<string>()
        const lazy = new Set<string>()
        const walk = (k: string, seen = new Set<string>()) => {
            if (seen.has(k) || !manifest[k]) return
            seen.add(k)
            const c = manifest[k]
            eager.add(c.file)
            for (const css of c.css ?? []) eager.add(css)
            for (const i of c.imports ?? []) walk(i, seen)
            for (const d of c.dynamicImports ?? []) {
                const dc = manifest[d]
                if (!dc) continue
                lazy.add(dc.file)
                for (const css of dc.css ?? []) lazy.add(css)
                for (const i of dc.imports ?? []) if (manifest[i] && !eager.has(manifest[i].file)) lazy.add(manifest[i].file)
                for (const dd of dc.dynamicImports ?? []) if (manifest[dd]) lazy.add(manifest[dd].file)
            }
        }
        keys.forEach((k) => walk(k))
        const tally = (set: Set<string>) => [...set].filter(Boolean).map((f) => ({ file: f, ...sizeOf(f) }))
        return { eager: tally(eager), lazy: tally(lazy) }
    }

    const report = (keys: string[]) => {
        const g = graph(keys)
        const sum = (rows: { raw: number; gz: number }[]) => ({
            rawKB: kb(rows.reduce((s, r) => s + r.raw, 0)),
            gzipKB: kb(rows.reduce((s, r) => s + r.gz, 0)),
        })
        const list = (rows: { file: string; raw: number; gz: number }[]) =>
            [...rows].sort((a, b) => b.raw - a.raw).slice(0, 6)
                .map((r) => `${r.file.replace('assets/', '')} ${kb(r.raw)}KB raw / ${kb(r.gz)}KB gz`)
        return {
            entries: keys,
            blocking: { files: g.eager.length, ...sum(g.eager) },
            onDemand: { files: g.lazy.length, ...sum(g.lazy) },
            largestBlocking: list(g.eager),
            largestOnDemand: list(g.lazy),
        }
    }

    const shell = report(['resources/css/app.css', 'resources/js/app.js'])
    const player = report(['resources/css/app.css', 'resources/js/app.js', 'resources/js/lesson-player.js'])
    const editor = report(['resources/js/lesson-map.js', 'resources/js/gallery-scene.js', 'resources/js/voyage-tour.js'])

    // The one chunk this exercise exists to name.
    const [avatarKey, avatarChunk] = Object.entries(manifest).find(([k]) => k.endsWith('avatar-3d.js')) ?? []
    const avatarSize = avatarChunk ? sizeOf(avatarChunk.file) : null
    const importers = (pred: (c: any) => boolean) => Object.entries(manifest).filter(([, c]) => pred(c)).map(([k]) => k)
    const staticImporters = importers((c) => (c.imports ?? []).some((i: string) => i.endsWith('avatar-3d.js')))
    const dynamicImporters = importers((c) => (c.dynamicImports ?? []).some((i: string) => i.endsWith('avatar-3d.js')))

    /**
     * The scene module is the one that matters, and it is not an entry — it is the chunk behind
     * `window.loadLessonScene()`, pulled by both the scene editor and the player's canvas scenes.
     * Its own comment claims three.js is the reason it is lazy. It is not: `scene/index.js`
     * re-exports `mountWizardScene` from wizard-bridge, wizard-bridge statically imports
     * avatar-3d.js, and avatar-3d statically imports @sparkjsdev/spark. Everything downstream of
     * that one re-export arrives eagerly the moment a teacher opens a scene.
     */
    const sceneModule = report(['resources/js/scene/index.js'])

    const measured = {
        note: 'raw = bytes on disk, gz = what a gzip-enabled server transfers',
        appShell: shell,
        studentPlayer: player,
        sceneEditorExtras: editor,
        sceneModule,
        avatar3d: avatarChunk && {
            key: avatarKey,
            file: avatarChunk.file,
            rawKB: kb(avatarSize!.raw),
            gzipKB: kb(avatarSize!.gz),
            staticallyImportedBy: staticImporters,
            dynamicallyImportedBy: dynamicImporters,
        },
    }
    record('production-bundle-graph', measured)

    expect(shell.blocking.rawKB).toBeGreaterThan(0)
    expect(player.blocking.rawKB).toBeGreaterThan(0)
    // The map / gallery / voyage entries must not drag the 3D avatar chunk in eagerly.
    expect(editor.largestBlocking.join(' ')).not.toContain('avatar-3d')

    // A ratchet on the scene module, pinned to what was measured on 2026-08-05: 5,769 KB raw /
    // 1,974 KB gzipped, of which avatar-3d alone is 4,876 KB / 1,733 KB. Make wizard-bridge's
    // `import { Avatar3DPlayer }` dynamic — the lesson player already does exactly that — and
    // this number drops to roughly 240 KB gzipped. Tighten the ceiling when it does.
    expect(sceneModule.blocking.gzipKB, 'the scene module must not grow further').toBeLessThan(2_100)
})
