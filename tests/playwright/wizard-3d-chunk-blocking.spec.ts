import { test, expect } from '@playwright/test'
import { gzipSync } from 'node:zlib'
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Does the scene editor's 3D chunk actually stop a teacher from working?
 *
 * A performance report called this a blocker: opening the editor pulls a Gaussian-splat renderer
 * (@sparkjsdev/spark, inside the avatar-3d chunk) before the teacher can touch anything, and time
 * to interactive tracked the parse at 1.6–6.6 s.
 *
 * "Time to interactive" is a heuristic, not an experience. This spec measures the experience:
 * how soon can a teacher SEE the scene rail, and — the part that matters — how long does the
 * browser take to answer their very first click, fired the instant that rail exists, i.e. while
 * the chunk is still downloading and parsing. If the main thread were held for 2.5 s, that first
 * click would sit in the queue for 2.5 s. That is the falsifiable claim.
 *
 * Two things this spec deliberately keeps apart:
 *  - BYTES ON THE WIRE IN DEV ARE NOT PRODUCT BYTES. `public/hot` exists under `composer dev`, so
 *    Vite serves unminified, ungzipped modules; @sparkjsdev_spark.js is 5,252 KB there and
 *    1.7 MB gzipped in the built asset. The dev figure is a measurement artefact.
 *  - WHETHER THE IMPORT IS EAGER IS A CODE FACT, not a timing one, so it is asserted against the
 *    built manifest rather than a stopwatch.
 */

const OUT = process.env.CHUNK_REPORT_DIR ?? join(process.cwd(), 'tests/playwright/results/3d-chunk')
const LESSON = Number(process.env.PERF_LESSON_ID ?? 41)

test.describe.configure({ mode: 'serial' })

test('a teacher can click the scene rail while the 3D chunk is still loading', async ({ page }, testInfo) => {
    const tag = testInfo.project.name
    mkdirSync(OUT, { recursive: true })

    const requests: { url: string; startMs: number; endMs: number }[] = []
    const t0 = Date.now()
    page.on('requestfinished', (r) => {
        const t = r.timing()
        requests.push({ url: r.url(), startMs: Date.now() - t0, endMs: Date.now() - t0 })
    })

    await page.addInitScript(() => {
        ;(window as any).__longTasks = []
        try {
            new PerformanceObserver((l) => {
                for (const e of l.getEntries()) (window as any).__longTasks.push({ start: e.startTime, dur: e.duration })
            }).observe({ entryTypes: ['longtask'] })
        } catch {}
    })

    const nav = Date.now()
    await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`, { waitUntil: 'commit' })

    // The scene rail is server-rendered Blade — it is the first thing a teacher reaches for.
    const thumbs = page.locator('[data-thumb]')
    await thumbs.first().waitFor({ state: 'visible', timeout: 60_000 })
    const railVisibleMs = Date.now() - nav

    // Fire the FIRST click the moment the rail exists — deliberately racing the chunk. Round-trip
    // latency here is the honest answer to "can a teacher touch anything".
    const targetId = await thumbs.nth(1).getAttribute('data-scene-id')
    const clickStart = Date.now()
    await thumbs.nth(1).click({ timeout: 30_000 })
    const clickAcceptedMs = Date.now() - clickStart

    // And the app has to actually respond, not just accept the event: the server agrees the
    // selection moved and the thumb is marked. A click fired before Livewire has bound its
    // listeners is swallowed silently, so keep clicking until one lands — the moment it does is
    // the moment the editor is genuinely usable.
    const marked = page.locator(`[data-thumb][data-scene-id="${targetId}"][data-thumb-selected]`).first()
    let respondedMs = -1
    let clicks = 1
    const deadline = Date.now() + 30_000
    while (Date.now() < deadline) {
        if (await marked.count()) { respondedMs = Date.now() - clickStart; break }
        await page.waitForTimeout(250)
        if (await marked.count()) { respondedMs = Date.now() - clickStart; break }
        if (Date.now() - clickStart > 1_000 * clicks) {
            await thumbs.nth(1).click({ timeout: 10_000 }).catch(() => {})
            clicks += 1
        }
    }
    const usableMs = respondedMs < 0 ? -1 : Date.now() - nav

    const longTasks = await page.evaluate(() => (window as any).__longTasks ?? [])
    const longest = longTasks.reduce((m: number, t: any) => Math.max(m, t.dur), 0)

    const sparkReq = requests.find((r) => /spark/i.test(r.url))
    const avatarReq = requests.find((r) => /avatar-3d/i.test(r.url))

    const shot = join(OUT, `wizard-editor-first-click-${tag}.png`)
    await page.screenshot({ path: shot, fullPage: false })

    const report = {
        project: tag,
        lesson: LESSON,
        longTasks: longTasks.map((t: any) => ({ start: Math.round(t.start), dur: Math.round(t.dur) })),
        railVisibleMs,
        firstClickAcceptedMs: clickAcceptedMs,
        firstClickRespondedMs: respondedMs,
        clicksNeeded: clicks,
        sceneSwitchUsableAtMs: usableMs,
        longTaskCount: longTasks.length,
        longestTaskMs: Math.round(longest),
        sparkRequestedAtMs: sparkReq?.endMs ?? null,
        avatar3dRequestedAtMs: avatarReq?.endMs ?? null,
        devMode: existsSync(join(process.cwd(), 'public/hot')),
        screenshot: shot,
    }
    writeFileSync(join(OUT, `first-click-${tag}.json`), JSON.stringify(report, null, 2))
    console.log('[3d-chunk] ' + JSON.stringify(report))

    // A click on the rail always lands eventually — the editor is never wedged.
    expect(respondedMs, 'a click on the scene rail eventually selects that scene').toBeGreaterThan(-1)

    if (tag === 'swiftshader') {
        // Software WebGL: one multi-second task swallows the teacher's click. This is the number
        // the performance report published, and it is the harness, not the app.
        expect(longest, 'SwiftShader produces the multi-second stall').toBeGreaterThan(2_000)
    } else {
        // Same page, same machine, real GPU: the stall is gone. Whatever the avatar chunk costs,
        // it does not hold the main thread for seconds.
        expect(longest, 'no multi-second main-thread block on a real GPU').toBeLessThan(2_000)
        expect(clickAcceptedMs, 'the first click is accepted immediately').toBeLessThan(1_500)
        expect(usableMs, 'switching scenes works within a few seconds of opening').toBeLessThan(6_000)
    }
})

/**
 * What does the PRODUCTION chunk cost the main thread?
 *
 * The editor page can't answer this while `public/hot` exists — Vite is serving unminified
 * modules, and the report's headline "5,252 KB" is that dev artefact, not a shipped byte. But the
 * built asset is on disk and served straight out of public/build, so it can be imported on its own
 * and timed. Everything the browser does here — download, parse, compile, evaluate — is exactly
 * what production does, minus the rest of the page competing for the thread.
 */
test('the production avatar-3d chunk, imported and timed on its own', async ({ page }, testInfo) => {
    mkdirSync(OUT, { recursive: true })
    const manifest = JSON.parse(readFileSync(join(process.cwd(), 'public/build/manifest.json'), 'utf8'))
    const file = Object.values(manifest as Record<string, any>)
        .map((n) => n.file as string)
        .find((f) => /avatar-3d.*\.js$/.test(f ?? '')) ?? 'assets/avatar-3d-BagkU99e.js'
    await page.goto('/', { waitUntil: 'domcontentloaded' })

    const timing = await page.evaluate(async (url) => {
        const longs: number[] = []
        try {
            new PerformanceObserver((l) => { for (const e of l.getEntries()) longs.push(e.duration) })
                .observe({ entryTypes: ['longtask'] })
        } catch {}
        const t = performance.now()
        await import(/* @vite-ignore */ url)
        const total = performance.now() - t
        await new Promise((r) => setTimeout(r, 400))

        return { totalMs: Math.round(total), longestMs: Math.round(Math.max(0, ...longs)) }
    }, `/build/${file}`)

    const out = { project: testInfo.project.name, file, ...timing }
    writeFileSync(join(OUT, `prod-chunk-parse-${testInfo.project.name}.json`), JSON.stringify(out, null, 2))
    console.log('[3d-chunk] ' + JSON.stringify(out))
    expect(timing.totalMs).toBeGreaterThan(0)
})

test('the built avatar-3d chunk is a static import of the scene module, and this is what it costs', () => {
    mkdirSync(OUT, { recursive: true })
    const manifestPath = join(process.cwd(), 'public/build/manifest.json')
    expect(existsSync(manifestPath), 'production build must exist').toBeTruthy()
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))

    const sceneEntry = Object.keys(manifest).find((k) => k.endsWith('resources/js/scene/index.js') || k === 'resources/js/scene/index.js')
    const rows: { file: string; rawKB: number; gzKB: number; eager: boolean }[] = []

    const walk = (key: string, eager: boolean, seen = new Set<string>()) => {
        if (!key || seen.has(key)) return
        seen.add(key)
        const node = manifest[key]
        if (!node) return
        const p = join(process.cwd(), 'public/build', node.file)
        if (existsSync(p)) {
            const buf = readFileSync(p)
            rows.push({ file: node.file, rawKB: Math.round(buf.length / 1024), gzKB: Math.round(gzipSync(buf).length / 1024), eager })
        }
        for (const i of node.imports ?? []) walk(i, eager, seen)
        for (const d of node.dynamicImports ?? []) walk(d, false, seen)
    }

    if (sceneEntry) walk(sceneEntry, true)

    const eager = rows.filter((r) => r.eager)
    const avatar = rows.find((r) => /avatar-3d/.test(r.file))
    const totals = {
        eagerFiles: eager.length,
        eagerRawKB: eager.reduce((s, r) => s + r.rawKB, 0),
        eagerGzKB: eager.reduce((s, r) => s + r.gzKB, 0),
        avatar3d: avatar ?? null,
    }
    writeFileSync(join(OUT, 'scene-module-graph.json'), JSON.stringify({ totals, rows }, null, 2))
    console.log('[3d-chunk] ' + JSON.stringify(totals))

    // The chunk really is reached by a STATIC import from scene/index.js — no guard, no branch.
    expect(avatar, 'avatar-3d chunk reachable from scene/index.js').toBeTruthy()
    expect(avatar!.eager, 'avatar-3d is statically imported, not lazy').toBeTruthy()
})
