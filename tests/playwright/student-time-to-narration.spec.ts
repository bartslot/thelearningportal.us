import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * "A student presses Start and waits 14–32 seconds before hearing a narrator."
 *
 * That claim was measured by a bot that presses every control the instant it appears and pads each
 * press with a fixed 500 ms, then reports the WALL CLOCK from Start to the first narration file.
 * A wall clock cannot tell the difference between an app that is slow and a lesson whose author
 * put four quiz questions in front of the story. This spec takes the two apart.
 *
 * Three things are measured, all in a real Chromium:
 *
 *  1. WHAT the gates are — read out of the player's own scene list, not guessed from the screen.
 *     If scene 1 is a `game` scene with no intro audio, the quiz is the lesson, not a stall.
 *  2. The APP's share of the wait — for every press, the ms from the click to the next affordance
 *     appearing. That is the only part the code is responsible for.
 *  3. Whether the "wall" is mandatory at all — the transport deck carries a chapter list on every
 *     non-game scene, so an Anne Frank student can be inside narration in two presses.
 *
 * Audio is observed the same way the performance lane observed it: by patching
 * HTMLMediaElement.prototype.play. Do NOT replace window.Audio — the player builds narration with
 * `new Audio()` and swapping the constructor stops the title screen dead.
 */

const REPORT_DIR = join(process.cwd(), 'tests/playwright/results/first-narration')

const INSTRUMENT = `
  window.__audioMarks = [];
  (function () {
    const proto = window.HTMLMediaElement && HTMLMediaElement.prototype;
    if (!proto || proto.__ttnPatched) return;
    const play = proto.play;
    proto.play = function () {
      try {
        this.addEventListener('playing', () => window.__audioMarks.push({
          t: performance.now(), src: this.currentSrc || this.src || '',
        }), { once: true });
      } catch (_) {}
      return play.apply(this, arguments);
    };
    proto.__ttnPatched = true;
  })();
`

const isNarration = (src: string) => /\/storage\/lessons\/.*\.(mp3|m4a|wav|ogg)/.test(src)

function record(slug: string, data: unknown) {
    if (!existsSync(REPORT_DIR)) mkdirSync(REPORT_DIR, { recursive: true })
    writeFileSync(join(REPORT_DIR, `${slug}.json`), JSON.stringify(data, null, 2))
    console.log(`\n── ${slug}\n${JSON.stringify(data, null, 2)}`)
}

const shot = (page: Page, name: string) => {
    if (!existsSync(REPORT_DIR)) mkdirSync(REPORT_DIR, { recursive: true })
    return page.screenshot({ path: join(REPORT_DIR, `${name}.png`) })
}

/**
 * The player's own state, straight out of Alpine — no guessing from pixels.
 *
 * `window.Alpine` is not exposed on this page (the bundle imports it as a module), so the
 * component is read off the element's own `_x_dataStack`, which is what Alpine.$data does anyway.
 */
const playerState = (page: Page) => page.evaluate(() => {
    const el = document.querySelector('[x-data*="lessonGame"]') as any
    const d = el?._x_dataStack?.[0]
    if (!d) return null
    return {
        sceneIndex: d._sceneIndex,
        chapters: (d.chapters ?? []).length,
        currentIsGame: !!d.currentIsGame,
        scenes: ((window as any).LESSON?.scenes ?? []).map((s: any, i: number) => ({
            i, kind: s.kind, hasAudio: !!s.audio_url,
            playback: s.config?.playback_mode ?? null,
        })),
    }
})

const heardNarration = (page: Page) => page.evaluate(
    () => ((window as any).__audioMarks ?? []).some((m: any) =>
        /\/storage\/lessons\/.*\.(mp3|m4a|wav|ogg)/.test(m.src)),
).catch(() => false)

const marksOf = (page: Page) => page.evaluate(() => (window as any).__audioMarks ?? [])

async function pressStart(page: Page, code: string) {
    await page.addInitScript(INSTRUMENT)
    await page.goto(`/lesson/${code}`, { waitUntil: 'load' })
    const start = page.getByRole('button', { name: /start lesson/i }).first()
    await expect(start).toBeVisible({ timeout: 60_000 })
    const t0 = await page.evaluate(() => performance.now())
    await start.click()
    return t0
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Abel Tasman — the wait is four authored questions, and the app's share of it
// ─────────────────────────────────────────────────────────────────────────────

test('student LYTVSK: the pre-narration gates are authored scenes, and the app answers each press fast', async ({ page }) => {
    test.setTimeout(180_000)

    const t0 = await pressStart(page, 'LYTVSK')

    // What the lesson is made of, read from the payload the player is driving.
    const state = await playerState(page)
    expect(state, 'the player exposes its scene list').not.toBeNull()
    const scenes = state!.scenes

    // Scene 1 is a game scene with no narrated intro; scene 2 is the first narrated scene. That
    // ordering is the lesson spec (LessonComposer::quizScene, `when: pre` — "asks for prior
    // knowledge"), not a code path that delays audio.
    expect(scenes[0].kind, 'scene 1 of this lesson is the intro challenge').toBe('game')
    expect(scenes[0].hasAudio, 'the intro challenge carries no narration to play').toBe(false)
    expect(scenes[1].kind).toBe('narration')
    expect(scenes[1].hasAudio, 'the first narrated scene is narrated').toBe(true)

    // Every control a student can press to move forward, in the order a student would reach for.
    const ADVANCE = [
        'button[data-done]',                    // "Skip ›" past the leaderboard card
        'button[data-opt="0"]:not([disabled])', // an intro-challenge answer
        'button[x-show*="showMapContinue"]',
    ]

    /*
     * "Nothing offers a first pressable control until 3–10 s after Start" is true and is the
     * point: QuizOverlay holds the answers behind a READ GATE (~55 ms per character of question
     * and options, capped at 7 s and floored at ~4.5 s — long enough to count 3, 2, 1 AND to lift
     * the four bars covering the answers one at a time — "Kills the 1-second straight-line sprint
     * without feeling like a punishment"). The student is not staring at an empty screen while it
     * runs; the card is up with the question, a live countdown, and answers arriving in order.
     * So the card's arrival is measured separately from the first pressable answer.
     */
    const card = page.locator('.qz-card').first()
    await expect(card, 'the quiz card is on screen').toBeVisible({ timeout: 30_000 })
    const cardVisibleMs = Math.round((await page.evaluate(() => performance.now())) - t0)
    const gateCountdown = page.locator('[data-gate-secs]').first()
    const gateShown = await gateCountdown.isVisible().catch(() => false)

    const firstControl = page.locator(ADVANCE.join(', ')).first()
    await expect(firstControl).toBeVisible({ timeout: 30_000 })
    const firstControlMs = Math.round((await page.evaluate(() => performance.now())) - t0)

    /**
     * The app's share, press by press. A real student spends seconds reading the question; the
     * code's job is only to answer the tap. So each step bills click → next affordance, and human
     * reading time is explicitly NOT counted.
     */
    const steps: { label: string; appMs: number }[] = []
    const deadline = Date.now() + 120_000
    let clicks = 0

    while (Date.now() < deadline && !(await heardNarration(page))) {
        const el = page.locator(ADVANCE.join(', ')).first()
        if (!(await el.isVisible().catch(() => false))) { await page.waitForTimeout(200); continue }

        const label = (await el.innerText().catch(() => '?')).replace(/\s+/g, ' ').trim().slice(0, 30)
        const pressedAt = await page.evaluate(() => performance.now())
        await el.click({ force: true })
        clicks++

        // Settled = the app has offered the next thing to do, or the narrator has started.
        await page.waitForFunction(
            (prev) => {
                const marks = (window as any).__audioMarks ?? []
                if (marks.some((m: any) => /\/storage\/lessons\/.*\.(mp3|m4a|wav|ogg)/.test(m.src))) return true
                const next = document.querySelector(
                    'button[data-done], button[data-opt="0"]:not([disabled]), button[x-show*="showMapContinue"]') as HTMLElement | null
                if (!next || next.offsetParent === null) return false
                return (next.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 30) !== prev
            },
            label,
            { timeout: 30_000 },
        ).catch(() => {})

        steps.push({ label, appMs: Math.round((await page.evaluate(() => performance.now())) - pressedAt) })
        if (clicks > 12) break
    }

    const marks = await marksOf(page)
    const firstNarration = marks.find((m: any) => isNarration(m.src))

    const measured = {
        lesson: 'LYTVSK',
        sceneKindsBeforeFirstNarratedScene: scenes.slice(0, 2).map((s: any) => `${s.kind}${s.hasAudio ? '+audio' : ' (silent by design)'}`),
        cardVisibleMs,
        readGateCountdownShown: gateShown,
        firstControlMs,
        readGateMs: firstControlMs - cardVisibleMs,
        clicks,
        steps,
        appTimeTotalMs: steps.reduce((s, x) => s + x.appMs, 0),
        slowestStepMs: Math.max(0, ...steps.map((s) => s.appMs)),
        startToFirstNarrationMs: firstNarration ? Math.round(firstNarration.t - t0) : null,
        handoffFromLastPressMs: steps.length ? steps[steps.length - 1].appMs : null,
        reachedNarration: !!firstNarration,
    }
    await shot(page, 'lytvsk-first-narration')
    record('lytvsk', measured)

    expect(measured.reachedNarration).toBe(true)
    // The app's own responsiveness: no single press may leave a class hanging.
    // The student sees the question long before the answers unlock — the gap is the read gate,
    // and it is announced on the card, not dead air.
    expect(measured.cardVisibleMs, 'the quiz card is up within a couple of seconds').toBeLessThan(6_000)
    expect(measured.readGateCountdownShown, 'the wait is announced, with a live countdown').toBe(true)
    // Each step is the read gate plus the feedback hold, both capped at 7s in QuizOverlay.
    expect(measured.slowestStepMs, 'no press costs more than the two capped pedagogy holds').toBeLessThan(15_000)
    // And the handoff out of the intro challenge into the narrator is immediate.
    expect(measured.handoffFromLastPressMs!, 'the narrator starts right after the last press').toBeLessThan(8_000)
})

// ─────────────────────────────────────────────────────────────────────────────
// 2. Anne Frank — the "wall" is optional: Chapters is on screen from scene 1
// ─────────────────────────────────────────────────────────────────────────────

test('student CF0AVG: a student can skip straight to the narrator with the Chapters list', async ({ page }) => {
    test.setTimeout(180_000)

    const t0 = await pressStart(page, 'CF0AVG')

    const state = await playerState(page)
    expect(state, 'the player exposes its scene list').not.toBeNull()
    const scenes = state!.scenes
    // Scenes 1–4 are interactive map slides — audio-less by design ("Map block — render the
    // historical atlas as a slide (no audio)"), paced by the class, not by a clock.
    expect(scenes.slice(0, 4).every((s: any) => s.kind === 'map' && !s.hasAudio)).toBe(true)
    const firstNarrated = scenes.findIndex((s: any) => s.hasAudio)
    expect(firstNarrated).toBeGreaterThan(0)

    // The transport deck carries the segmented chapter bar — one button per chapter, "click to
    // jump" — and it is on screen from the very first slide because this scene is not a game.
    // Nudging the pointer is what a student does; the deck auto-hides when idle.
    await page.mouse.move(640, 760)

    // The segment buttons are the only tooltip-bearing buttons with no aria-label — the deck's
    // own controls (play, mute, subtitles, chapters) all carry one.
    const chapterButtons = page.locator('button[data-tooltip]:not([aria-label])')
    await expect(chapterButtons.first(), 'the chapter bar is offered on the very first slide')
        .toBeVisible({ timeout: 30_000 })
    const chapterBarVisibleMs = Math.round((await page.evaluate(() => performance.now())) - t0)

    // Which segment belongs to the first narrated scene. `chapters` is in DOM order, so its
    // position in the list is the position of the button.
    const chapterList: { index: number; name: string }[] = await page.evaluate(() =>
        ((document.querySelector('[x-data*="lessonGame"]') as any)._x_dataStack[0].chapters ?? [])
            .map((c: any) => ({ index: c.index, name: c.name })))
    const seat = chapterList.findIndex((c) => c.index === firstNarrated)
    expect(seat, 'the first narrated scene has its own chapter segment').toBeGreaterThanOrEqual(0)
    expect(await chapterButtons.count(), 'one button per chapter').toBe(chapterList.length)

    const jumpedAt = await page.evaluate(() => performance.now())
    await chapterButtons.nth(seat).click()

    await page.waitForFunction(() => ((window as any).__audioMarks ?? []).some((m: any) =>
        /\/storage\/lessons\/.*\.(mp3|m4a|wav|ogg)/.test(m.src)), undefined, { timeout: 30_000 })

    const marks = await marksOf(page)
    const firstNarration = marks.find((m: any) => isNarration(m.src))

    const measured = {
        lesson: 'CF0AVG',
        openingScenes: scenes.slice(0, 5).map((s: any) => `${s.kind}${s.hasAudio ? '+audio' : ' (silent by design)'}${s.playback ? ` [${s.playback}]` : ''}`),
        firstNarratedSceneIndex: firstNarrated,
        chapterName: chapterList[seat]?.name ?? null,
        chapterBarVisibleMs,
        clicksToNarration: 2,                                  // Start, then one chapter segment
        jumpToNarrationMs: Math.round(firstNarration.t - jumpedAt),
        startToFirstNarrationMs: Math.round(firstNarration.t - t0),
        firstNarrationSrc: firstNarration.src.split('/').slice(-2).join('/'),
    }
    await shot(page, 'cf0avg-chapter-jump')
    record('cf0avg-chapter-jump', measured)

    // Reaching a narrated scene costs one jump, and the narrator starts on top of it.
    expect(measured.jumpToNarrationMs, 'the narrator starts as soon as a narrated scene is reached').toBeLessThan(8_000)
    expect(measured.startToFirstNarrationMs, 'a student who wants the story gets it quickly').toBeLessThan(20_000)
})
