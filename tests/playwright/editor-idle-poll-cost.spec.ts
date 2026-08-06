import { test, expect } from '@playwright/test'
import { gzipSync } from 'node:zlib'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * What an IDLE scene editor actually costs — refutation pass.
 *
 * A performance-lane finding claims that `wire:poll.3s` on
 * resources/views/livewire/wizard/step3-scene-configurator.blade.php transfers "361 KB per poll,
 * 423 MB/hour". That number came from a run that (a) averaged POLL responses together with
 * scene-SWITCH responses, and (b) measured `php artisan serve`, which sends no Content-Encoding.
 * Production is nginx with gzip (verified: `content-encoding: gzip` on https://thelearningportal.us/).
 *
 * So this spec measures three separate things instead of one blended one:
 *   1. the real CADENCE while the tab is visible and the teacher does nothing,
 *   2. the size of a POLL response only, with switches excluded,
 *   3. what that response weighs once gzipped — i.e. what a teacher on a school connection pays.
 *
 * Nothing here is asserted from motion or animation, so the default chromium project is fine.
 */

const REPORT_DIR = join(process.cwd(), 'tests/playwright/results/idle-poll-cost')
const LESSON = Number(process.env.PERF_LESSON_ID ?? process.env.PW_TEACHER_LESSON_ID ?? 41)
const IDLE_MS = 30_000
const DECLARED_CADENCE_MS = 3_000

type Poll = { at: number; encoded: number; raw: number; gzip: number }

test('an idle scene editor: how often it polls, and what it really weighs', async ({ page }) => {
    mkdirSync(REPORT_DIR, { recursive: true })

    const cdp = await page.context().newCDPSession(page)
    await cdp.send('Network.enable', { maxTotalBufferSize: 200_000_000, maxResourceBufferSize: 100_000_000 })

    const open = new Map<string, { url: string }>()
    const finished: { requestId: string; url: string; at: number; encoded: number }[] = []
    const encodings: string[] = []

    cdp.on('Network.requestWillBeSent', (e: any) => open.set(e.requestId, { url: e.request.url }))
    cdp.on('Network.responseReceived', (e: any) => {
        if (/livewire\/update/.test(e.response.url ?? '')) {
            const h = e.response.headers ?? {}
            encodings.push(String(h['content-encoding'] ?? h['Content-Encoding'] ?? '(none)'))
        }
        open.set(e.requestId, { url: e.response.url })
    })
    cdp.on('Network.loadingFinished', (e: any) => {
        const m = open.get(e.requestId)
        if (!m) return
        open.delete(e.requestId)
        if (!/livewire\/update/.test(m.url)) return
        finished.push({ requestId: e.requestId, url: m.url, at: Date.now(), encoded: e.encodedDataLength ?? 0 })
    })

    // A teacher opens the editor on a real, published lesson and leaves it alone.
    await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('[wire\\:poll\\.3s], [wire\\:poll]').first()).toBeAttached({ timeout: 30_000 })
    // Let the first paint and its opening round trips settle before the idle window opens.
    await page.waitForLoadState('networkidle', { timeout: 45_000 }).catch(() => {})

    const visible = await page.evaluate(() => ({ state: document.visibilityState, hidden: document.hidden }))
    expect(visible.hidden, 'a hidden tab throttles Livewire polling — this must be a visible one').toBe(false)

    // ── the idle window: hands off the keyboard, nothing clicked ───────────────
    const windowStart = finished.length
    const t0 = Date.now()
    await page.waitForTimeout(IDLE_MS)
    const elapsed = (Date.now() - t0) / 1000

    const idleRows = finished.slice(windowStart)

    // Bodies, so the wire cost can be modelled honestly for a gzip-enabled server.
    const polls: Poll[] = []
    for (const r of idleRows) {
        let body = ''
        try {
            const res: any = await cdp.send('Network.getResponseBody', { requestId: r.requestId })
            body = res.base64Encoded ? Buffer.from(res.body, 'base64').toString('utf8') : res.body
        } catch {
            /* body evicted from the CDP buffer — encoded length still counts */
        }
        const buf = Buffer.from(body, 'utf8')
        polls.push({ at: r.at, encoded: r.encoded, raw: buf.length, gzip: buf.length ? gzipSync(buf).length : 0 })
    }

    const kb = (b: number) => Math.round(b / 1024)
    const gaps = polls.slice(1).map((p, i) => Math.round((p.at - polls[i].at) / 100) / 10)
    const avg = (ns: number[]) => (ns.length ? Math.round(ns.reduce((s, n) => s + n, 0) / ns.length) : 0)

    const avgRawKB = kb(avg(polls.map((p) => p.raw)))
    const avgGzipKB = kb(avg(polls.map((p) => p.gzip)))
    const pollsPerMinute = polls.length ? +(polls.length / elapsed * 60).toFixed(1) : 0

    const record = {
        lesson: LESSON,
        idleSeconds: +elapsed.toFixed(1),
        pageVisibility: visible,
        pollsDuringIdle: polls.length,
        pollsPerMinute,
        observedCadenceSeconds: gaps,
        declaredCadenceSeconds: DECLARED_CADENCE_MS / 1000,
        // What the dev server sent (php artisan serve: no compression at all).
        devServerContentEncoding: [...new Set(encodings)],
        rawSizesKB: polls.map((p) => kb(p.raw)),
        encodedSizesKB: polls.map((p) => kb(p.encoded)),
        avgRawKB,
        avgGzipKB,
        // Production is nginx + gzip, so this is the row that describes a teacher's connection.
        costPerHour: {
            uncompressedMB: +(avgRawKB * pollsPerMinute * 60 / 1024).toFixed(1),
            gzippedMB: +(avgGzipKB * pollsPerMinute * 60 / 1024).toFixed(1),
        },
    }
    writeFileSync(join(REPORT_DIR, 'idle-poll.json'), JSON.stringify(record, null, 2))
    await page.screenshot({ path: join(REPORT_DIR, 'idle-editor.png'), fullPage: false })
    console.log(JSON.stringify(record, null, 2))

    // ── what must hold ────────────────────────────────────────────────────────
    // The poll is real and it does fire while the teacher is idle: this is the part of the
    // finding that survives.
    expect(polls.length, 'an untouched editor still talks to the server').toBeGreaterThan(0)

    // A single response must stay far below the half-megabyte the finding implies is routine.
    expect(kb(Math.max(...polls.map((p) => p.raw))), 'no single idle poll may cross 600 KB').toBeLessThan(600)

    // The claim that matters for a school connection: what crosses the wire once compressed.
    // 423 MB/hour would need ~120 KB gzipped per poll at 20 polls/minute. Guard well under that.
    expect(record.costPerHour.gzippedMB, 'an idle hour must not cost a teacher 100 MB').toBeLessThan(100)
})
