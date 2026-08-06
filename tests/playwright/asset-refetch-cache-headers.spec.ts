import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'
import { createServer } from 'node:http'
import type { Server } from 'node:http'
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { createHash } from 'node:crypto'

/**
 * "The same image is fetched three times, so the bytes are paid three times."
 *
 * That claim was raised off a network capture of a student playthrough of CF0AVG, where
 * `paintings/Q18889542.jpg` shows up three times at 654 KB. This spec asks the only question that
 * decides whether a student ever pays for it: WHY did the second and third fetch cross the wire?
 *
 * A browser re-downloads a URL it already has for exactly one reason — it was never allowed to
 * keep it. So the test is a controlled A/B, run in the same Chromium, in the same page, against
 * the same 654 KB JPEG:
 *
 *   A. served by the app's dev server  (`php artisan serve`, i.e. the PHP built-in web server)
 *   B. served by a local server that answers the way Apache answers on the deployed site —
 *      Last-Modified + ETag, nothing more, no hand-written Cache-Control
 *
 * If B dedupes and A does not, the duplication belongs to the harness, not the lesson player.
 *
 * Third test walks the real lesson as a student and reports the duplicates it actually sees, so
 * the claim is reproduced rather than argued with.
 */

const REPORT_DIR = join(process.cwd(), 'tests/playwright/results/asset-refetch')
const PAINTING = '/storage/lessons/3/paintings/Q18889542.jpg'
const PAINTING_FILE = join(process.cwd(), 'storage/app/public/lessons/3/paintings/Q18889542.jpg')
const STUDENT_CODE = 'CF0AVG'

const out = (name: string) => {
    if (!existsSync(REPORT_DIR)) mkdirSync(REPORT_DIR, { recursive: true })
    return join(REPORT_DIR, name)
}
const record = (slug: string, data: unknown) => {
    writeFileSync(out(`${slug}.json`), JSON.stringify(data, null, 2))
    console.log(`\n── ${slug}\n${JSON.stringify(data, null, 2)}`)
}
const kb = (b: number) => Math.round(b / 1024)
const short = (u: string) => { try { return new URL(u).pathname.split('/').slice(-2).join('/') } catch { return u } }

// ─────────────────────────────────────────────────────────────────────────────
// Network capture. Unlike a plain request listener this keeps the two facts that decide the
// finding: whether the response came off the wire or out of a cache, and which caching headers
// it carried.
// ─────────────────────────────────────────────────────────────────────────────

type Wire = {
    url: string
    status: number
    bytes: number
    fromDiskCache: boolean
    fromMemoryCache: boolean
    cacheControl: string
    etag: string
    lastModified: string
}

async function captureNetwork(page: Page) {
    const cdp = await page.context().newCDPSession(page)
    await cdp.send('Network.enable', {})

    const open = new Map<string, Partial<Wire>>()
    const wire: Wire[] = []
    /** Chrome fires this INSTEAD of a network round trip when the memory cache answers. */
    const memoryHits: string[] = []

    cdp.on('Network.requestWillBeSent', (e: any) => open.set(e.requestId, { url: e.request.url }))
    cdp.on('Network.requestServedFromCache', (e: any) => {
        const m = open.get(e.requestId)
        if (m?.url) memoryHits.push(m.url)
        open.set(e.requestId, { ...(m ?? {}), fromMemoryCache: true })
    })
    cdp.on('Network.responseReceived', (e: any) => {
        const h = Object.fromEntries(
            Object.entries(e.response.headers ?? {}).map(([k, v]) => [k.toLowerCase(), String(v)]),
        )
        open.set(e.requestId, {
            ...(open.get(e.requestId) ?? {}),
            url: e.response.url,
            status: e.response.status,
            fromDiskCache: !!e.response.fromDiskCache,
            cacheControl: h['cache-control'] ?? '',
            etag: h['etag'] ?? '',
            lastModified: h['last-modified'] ?? '',
        })
    })
    cdp.on('Network.loadingFinished', (e: any) => {
        const m = open.get(e.requestId)
        if (!m) return
        wire.push({
            url: m.url ?? '', status: m.status ?? 0, bytes: e.encodedDataLength ?? 0,
            fromDiskCache: !!m.fromDiskCache, fromMemoryCache: !!m.fromMemoryCache,
            cacheControl: m.cacheControl ?? '', etag: m.etag ?? '', lastModified: m.lastModified ?? '',
        })
        open.delete(e.requestId)
    })
    cdp.on('Network.loadingFailed', (e: any) => open.delete(e.requestId))

    return { wire, memoryHits, mark: () => wire.length, since: (n: number) => wire.slice(n) }
}

/** Load the same URL into three <img> in turn, one fully settled before the next starts. */
async function loadThreeTimes(page: Page, url: string) {
    return page.evaluate(async (src) => {
        const once = () => new Promise<boolean>((resolve) => {
            const img = new Image()
            img.onload = () => resolve(true)
            img.onerror = () => resolve(false)
            img.src = src
            document.body.appendChild(img)
        })
        const results: boolean[] = []
        for (let i = 0; i < 3; i++) results.push(await once())
        return results
    }, url)
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. What the dev server actually promises about this file
// ─────────────────────────────────────────────────────────────────────────────

test('the dev server hands out the painting with no way to cache it', async ({ page }) => {
    const net = await captureNetwork(page)
    await page.goto('/lesson/' + STUDENT_CODE, { waitUntil: 'domcontentloaded' })

    const from = net.mark()
    await loadThreeTimes(page, PAINTING)
    const rows = net.since(from).filter((r) => r.url.includes('Q18889542'))

    const headers = {
        cacheControl: rows[0]?.cacheControl ?? '',
        etag: rows[0]?.etag ?? '',
        lastModified: rows[0]?.lastModified ?? '',
    }
    record('dev-server-headers', { url: PAINTING, headers, fetches: rows.length, bytes: rows.map((r) => kb(r.bytes)) })

    expect(rows.length, 'the image did load').toBeGreaterThan(0)
    // No freshness lifetime, no validator: nothing a browser is permitted to reuse.
    expect(headers.cacheControl).toBe('')
    expect(headers.etag).toBe('')
    expect(headers.lastModified).toBe('')
})

// ─────────────────────────────────────────────────────────────────────────────
// 2. The A/B. Same JPEG, same browser, same page — only the response headers differ.
// ─────────────────────────────────────────────────────────────────────────────

test('the same image is re-downloaded under the dev server and reused under Apache-style headers', async ({ page }) => {
    const bytes = readFileSync(PAINTING_FILE)
    const etag = '"' + createHash('sha1').update(bytes).digest('hex').slice(0, 16) + '"'
    const lastModified = new Date(Date.now() - 30 * 24 * 3600 * 1000).toUTCString()

    /*
     * Deliberately minimal: Last-Modified + ETag and NOTHING else. That is stock Apache for a file
     * under public/, which is what serves storage/ on the deployed site — no Cache-Control was
     * added to public/.htaccess. It is the weakest prod-shaped answer available, so if even this
     * dedupes, anything stronger does too.
     */
    let hits = 0
    const server: Server = createServer((req, res) => {
        hits++
        res.writeHead(200, {
            'Content-Type': 'image/jpeg',
            'Content-Length': String(bytes.length),
            'Last-Modified': lastModified,
            ETag: etag,
        })
        res.end(bytes)
    })
    await new Promise<void>((r) => server.listen(0, '127.0.0.1', r))
    const port = (server.address() as any).port
    const cacheableUrl = `http://127.0.0.1:${port}${PAINTING}`

    try {
        const net = await captureNetwork(page)
        await page.goto('/lesson/' + STUDENT_CODE, { waitUntil: 'domcontentloaded' })

        const devFrom = net.mark()
        const devLoaded = await loadThreeTimes(page, PAINTING)
        const devRows = net.since(devFrom).filter((r) => r.url.includes('Q18889542'))

        const prodFrom = net.mark()
        const prodLoaded = await loadThreeTimes(page, cacheableUrl)
        const prodRows = net.since(prodFrom).filter((r) => r.url.includes('Q18889542'))

        const report = {
            image: short(PAINTING),
            fileKB: kb(bytes.length),
            devServer: {
                headers: 'none',
                imagesLoaded: devLoaded.filter(Boolean).length,
                wireResponses: devRows.length,
                wireKB: kb(devRows.reduce((s, r) => s + r.bytes, 0)),
                servedFromCache: devRows.filter((r) => r.fromDiskCache || r.fromMemoryCache).length,
            },
            apacheStyle: {
                headers: 'Last-Modified + ETag',
                imagesLoaded: prodLoaded.filter(Boolean).length,
                originHits: hits,
                wireResponses: prodRows.length,
                wireKB: kb(prodRows.reduce((s, r) => s + r.bytes, 0)),
                memoryCacheHits: net.memoryHits.filter((u) => u === cacheableUrl).length,
            },
        }
        record('ab-cache-headers', report)

        expect(devLoaded.filter(Boolean).length, 'all three loads succeeded under the dev server').toBe(3)
        expect(prodLoaded.filter(Boolean).length, 'all three loads succeeded under prod-style headers').toBe(3)

        // The whole finding in two lines: three requests, three downloads without headers;
        // three requests, ONE download with them.
        expect(report.devServer.wireResponses, 'header-less: every reference re-downloads').toBe(3)
        expect(hits, 'Apache-style: the origin is asked exactly once for three references').toBe(1)
        expect(report.apacheStyle.wireKB, 'Apache-style: the repeats cost no bytes').toBeLessThan(
            report.devServer.wireKB / 2,
        )
    } finally {
        await new Promise<void>((r) => server.close(() => r()))
    }
})

// ─────────────────────────────────────────────────────────────────────────────
// 3. Reproduce the original capture: walk CF0AVG as a student and list the duplicates
// ─────────────────────────────────────────────────────────────────────────────

const ADVANCE = [
    'button[data-opt="0"]:not([disabled])',
    'button[x-show*="showMapContinue"]',
    'button:has-text("Continue")',
    'button[data-done]',
]

test(`student: /lesson/${STUDENT_CODE} — which assets really cross the wire twice`, async ({ page }) => {
    test.setTimeout(180_000)
    const net = await captureNetwork(page)

    await page.goto(`/lesson/${STUDENT_CODE}`, { waitUntil: 'load' })
    const start = page.getByRole('button', { name: /start lesson/i }).first()
    await expect(start).toBeVisible({ timeout: 60_000 })

    const from = net.mark()
    await start.click()

    // Move like a student in a hurry for a fixed window, pressing whatever is offered.
    const until = Date.now() + 60_000
    let clicks = 0
    while (Date.now() < until) {
        let pressed = false
        for (const sel of ADVANCE) {
            const btn = page.locator(sel).first()
            if (await btn.isVisible().catch(() => false)) {
                await btn.click({ timeout: 2_000 }).catch(() => {})
                clicks++
                pressed = true
                break
            }
        }
        await page.waitForTimeout(pressed ? 400 : 1_200)
    }

    const rows = net.since(from).filter((r) => /\/storage\//.test(r.url))
    const byUrl = new Map<string, Wire[]>()
    for (const r of rows) byUrl.set(r.url, [...(byUrl.get(r.url) ?? []), r])

    const duplicates = [...byUrl.entries()]
        .filter(([, rs]) => rs.length > 1)
        .map(([url, rs]) => ({
            asset: short(url),
            fetches: rs.length,
            eachKB: rs.map((r) => kb(r.bytes)),
            wastedKB: kb(rs.slice(1).reduce((s, r) => s + r.bytes, 0)),
            anyServedFromCache: rs.some((r) => r.fromDiskCache || r.fromMemoryCache),
            cacheControl: rs[0].cacheControl || '(none)',
            validator: rs[0].etag || rs[0].lastModified || '(none)',
        }))
        .sort((a, b) => b.wastedKB - a.wastedKB)

    const report = {
        clicks,
        storageRequests: rows.length,
        distinctAssets: byUrl.size,
        duplicatedAssets: duplicates.length,
        wastedKB: duplicates.reduce((s, d) => s + d.wastedKB, 0),
        /** The point: every duplicated asset was served without a validator, so the browser had
         *  no choice. Anything here with a validator WOULD be an application bug. */
        duplicatesThatHadACachingHeader: duplicates.filter((d) => d.validator !== '(none)' || d.cacheControl !== '(none)'),
        duplicates: duplicates.slice(0, 15),
    }
    await page.screenshot({ path: out('student-playthrough.png') })
    record('student-duplicates', report)

    expect(rows.length, 'the student pulled lesson media').toBeGreaterThan(0)
    // The verdict, asserted: no asset was re-downloaded despite being cacheable.
    expect(report.duplicatesThatHadACachingHeader, 'a duplicate of a CACHEABLE asset would be a real bug').toEqual([])
})
