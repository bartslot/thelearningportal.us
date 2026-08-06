import { test, expect, type APIRequestContext, type Locator, type Page } from '@playwright/test'

/**
 * A film is a SCENE, not a background.
 *
 * Video shipped this cycle as its own scene kind — its own rail tile, its own inspector, its own
 * branch in the player — and nothing had ever watched it work in a browser. This spec plays the two
 * people who meet it: the teacher who opens the scene to set the film up, and the class that
 * watches it and moves on. It also guards the half of the same change that did NOT move: a 3D
 * model is still a BACKGROUND, and embed backgrounds now paint on the wizard stage through the
 * shared scene/embed-bg.js, so the editor shows what the class will see.
 *
 * No lesson is named here. A lesson has no single type — the SCENE carries the kind — so the
 * fixture is resolved by asking the catalogue which published lesson actually CONTAINS a scene of
 * kind 'video', which is the question the spec is really asking.
 *
 * Nothing asserts on English. The UI language follows the signed-in teacher's `locale` column, and
 * the dev auto-login account is not guaranteed to be on 'en' — a run against a French account
 * failed on the word "Video" while the feature was perfectly fine. Text assertions therefore either
 * accept every shipped translation or hang off untranslatable hooks (a URL placeholder, a wire:click).
 */

const SHOTS = 'tests/playwright/results/video-scene'

/** "Video" in the five shipped UI languages (en/nl/de/it share the word; fr accents it). */
const VIDEO_WORD = /vid[ée]o/i
/** "Continue" in the five shipped UI languages. */
const CONTINUE = /^(continue|verder|weiter|continuer|continua)$/i

type Embed = { kind?: string; src?: string; fit?: string }
type Scene = { id: number; kind: string; config?: { bg_embed?: Embed | null } | null }

/**
 * The ordered scene payload the player page inlines — id, kind and config for every scene.
 *
 * Scanned to the matching bracket rather than regexed: scene configs contain nested arrays and a
 * lazy match stops at the first inner "]". Same reader the suite's global setup uses.
 */
async function scenesOf(api: APIRequestContext, code: string): Promise<Scene[]> {
    const html = await (await api.get(`/lesson/${code}`)).text()
    const start = html.indexOf('"scenes":[')
    if (start === -1) return []

    const open = html.indexOf('[', start)
    let depth = 0
    for (let i = open; i < html.length; i++) {
        if (html[i] === '[') depth++
        else if (html[i] === ']' && --depth === 0) return JSON.parse(html.slice(open, i + 1))
    }
    return []
}

type Fixture = {
    id: number
    code: string
    title: string
    scenes: Scene[]
    /** The first video scene, and its index in the play queue — the queue is the scene list, 1:1. */
    video: Scene
    videoIndex: number
    /** A scene carrying a 3D/embed BACKGROUND, if this lesson has one. */
    embedBg: Scene | null
}

let _fixture: Promise<Fixture> | null = null

function fixture(api: APIRequestContext): Promise<Fixture> {
    _fixture ??= (async () => {
        const res = await api.get('/api/v1/catalog/manifest')
        expect(res.ok(), 'the catalogue manifest must be reachable — is the dev server up?').toBeTruthy()
        const lessons = ((await res.json()).lessons ?? []) as Array<{
            id: number; code: string; title: string; status: string; counts?: { scenes?: number }
        }>

        for (const lesson of lessons.filter((l) => l.status === 'published' && (l.counts?.scenes ?? 0) > 0)) {
            const scenes = await scenesOf(api, lesson.code)
            const videoIndex = scenes.findIndex((s) => s.kind === 'video')
            if (videoIndex === -1) continue

            return {
                ...lesson,
                scenes,
                video: scenes[videoIndex],
                videoIndex,
                // A 3D model kept as a BACKGROUND on an ordinary scene — the half that did not move.
                embedBg: scenes.find((s) => s.kind !== 'video' && s.config?.bg_embed?.src) ?? null,
            }
        }

        throw new Error(
            'No published lesson contains a scene of kind "video" — nothing to exercise.\n' +
            'Add one in the wizard (Add scene → Video), paste a link, and publish the lesson.'
        )
    })()

    return _fixture
}

/** The embed iframe painted on the wizard stage (the canvas's positioned parent hosts it). */
const wizardStageEmbed = (page: Page) =>
    page.locator('#lesson-canvas').locator('xpath=..').locator('.lesson-embed-bg iframe')

/** The film painted behind the student player's scene overlay. */
const playerFilm = (page: Page) => page.locator('#background-layer .lesson-embed-bg iframe')

/** Everything before the query string — enough to identify a film without matching its options. */
const embedBase = (embed: Embed) => embed.src!.split('?')[0]

/** How much of `host`'s width the film covers, 0–1. 0 while either box is still unmeasurable. */
async function coverage(film: Locator, host: Locator): Promise<number> {
    const [a, b] = await Promise.all([film.boundingBox(), host.boundingBox()])
    if (!a || !b || b.width < 100) return 0
    return Number((a.width / b.width).toFixed(2))
}

test.describe('a film is a scene, not a background', () => {
    test('teacher: the rail tile says Video, and the inspector is a film inspector', async ({ page, request }) => {
        const f = await fixture(request)

        // Land on the scene editor with nothing chosen — the teacher has to FIND the film in the
        // rail, which is the whole reason the tile has its own look.
        await page.goto(`/teacher/lessons/${f.id}/wizard?step=4`, { waitUntil: 'domcontentloaded' })

        const tile = page.locator(`[data-thumb][data-scene-id="${f.video.id}"]`)
        await expect(tile, 'the video scene needs its own tile in the rail').toBeVisible()
        await expect(tile, 'the tile must name the kind, like the map and gallery tiles do').toContainText(VIDEO_WORD)
        // Every kind draws its own glyph; the film gets the play triangle.
        await expect(tile.locator('svg')).toHaveCount(1)

        await tile.click()

        // Anchor on the placeholder: it is a literal URL example, so it survives every translation.
        const link = page.locator('input[placeholder*="youtu.be"]')
        await expect(link, 'a film is authored by pasting a link').toBeVisible()

        const panel = link.locator('xpath=ancestor::div[contains(@class,"space-y-3")][1]')
        await expect(panel.locator('h3'), 'the inspector announces itself as Video').toContainText(VIDEO_WORD)

        // The playback settings a film has and a picture does not.
        await expect(panel.locator('input.toggle'), 'autoplay + show-controls').toHaveCount(2)
        await expect(panel.locator('input[type="number"]'), 'start + end seconds').toHaveCount(2)
        await expect(panel.locator('[wire\\:click="clearBgEmbed"]'), 'Remove video').toBeVisible()
        // The film itself previews in the panel, so the teacher can see they pasted the right link.
        await expect(panel.locator('iframe')).toHaveAttribute('src', new RegExp(escapeRe(embedBase(f.video.config!.bg_embed!))))

        // And it is NOT the background picker any more. These are the background chrome's own
        // hooks; a film that were still "a kind of background" would sit among them.
        for (const hook of ['openPaintingPicker', 'generateSkyboxCandidates']) {
            await expect(page.locator(`[wire\\:click^="${hook}"]`), `${hook} belongs to backgrounds, not films`).toHaveCount(0)
        }

        await page.screenshot({ path: `${SHOTS}/teacher-video-inspector.png` })
    })

    test('teacher: selecting the film in the rail plays it on the editor stage', async ({ page, request }) => {
        const f = await fixture(request)

        await page.goto(`/teacher/lessons/${f.id}/wizard?step=4`, { waitUntil: 'domcontentloaded' })
        // The stage bridge boots asynchronously (three.js chunk) — wait for it, not for a clock.
        await page.waitForFunction(() => !!(window as unknown as { __lessonStage?: unknown }).__lessonStage, null, { timeout: 60_000 })

        await page.locator(`[data-thumb][data-scene-id="${f.video.id}"]`).click()

        const stage = wizardStageEmbed(page)
        await expect(stage, 'the film must fill the editor stage, not leave it black').toBeVisible({ timeout: 30_000 })
        await expect(stage).toHaveAttribute('src', new RegExp(escapeRe(embedBase(f.video.config!.bg_embed!))))

        // The film IS the scene, so it fills the canvas rather than sitting in a corner of it.
        // Polled against the canvas rather than asserted once in pixels: the editor's panels settle
        // over a few frames after a scene switch, and a single measurement caught the stage
        // mid-reflow (336px of an 848px canvas) in a full-suite run.
        await expect
            .poll(() => coverage(stage, page.locator('#lesson-canvas')), { timeout: 15_000 })
            .toBeGreaterThan(0.95)

        await page.screenshot({ path: `${SHOTS}/teacher-video-stage.png` })
    })

    test('teacher: a 3D embed background still renders on the wizard stage', async ({ page, request }) => {
        const f = await fixture(request)
        test.skip(!f.embedBg, 'this lesson has no scene carrying a 3D/embed background')
        const embed = f.embedBg!.config!.bg_embed!

        await page.goto(`/teacher/lessons/${f.id}/wizard?step=4`, { waitUntil: 'domcontentloaded' })
        await page.waitForFunction(() => !!(window as unknown as { __lessonStage?: unknown }).__lessonStage, null, { timeout: 60_000 })

        await page.locator(`[data-thumb][data-scene-id="${f.embedBg!.id}"]`).click()

        const stage = wizardStageEmbed(page)
        await expect(stage, 'a 3D background must paint on the editor stage, not stay black').toBeVisible({ timeout: 30_000 })
        await expect(stage).toHaveAttribute('src', new RegExp(escapeRe(embedBase(embed))))

        // It is a BACKGROUND: it sits behind the map preview and the text/clipart overlays.
        const z = await stage.evaluate((el) => Number(getComputedStyle(el.parentElement!).zIndex))
        expect(z, 'the embed background must stay below the overlays').toBeLessThan(5)

        // And it survives the wizard's 3s status poll, which re-fires scene:load unchanged — the
        // iframe must be the SAME element, or the video would restart every three seconds.
        await stage.evaluate((el) => { (el as HTMLElement).dataset.pwMark = '1' })
        await page.waitForTimeout(7_000)
        await expect(wizardStageEmbed(page), 'the poll must not remount the embed').toHaveAttribute('data-pw-mark', '1')

        await page.screenshot({ path: `${SHOTS}/teacher-embed-background.png` })
    })

    /**
     * KNOWN DEFECT, kept executable rather than described in a document.
     *
     * scene:load is the only thing that paints an embed on the wizard stage, and the bridge is
     * still loading its three.js chunk when Livewire fires the mount-time one — so opening the
     * editor straight at a video (or 3D-background) scene shows a black stage until the teacher
     * clicks some other tile and comes back. The player's own "Edit scene" link deep-links exactly
     * like this, and so does a page refresh.
     *
     * test.fail keeps the suite honest in both directions: it records the bug today, and the day
     * someone fixes it this test goes red for passing unexpectedly and gets its marker removed.
     */
    test('teacher: deep-linking to the film shows it immediately', async ({ page, request }) => {
        test.fail(true, 'first paint never applies bg_embed — the stage stays black until another tile is clicked')
        const f = await fixture(request)

        await page.goto(`/teacher/lessons/${f.id}/wizard?step=4&scene=${f.video.id}`, { waitUntil: 'domcontentloaded' })
        await page.waitForFunction(() => !!(window as unknown as { __lessonStage?: unknown }).__lessonStage, null, { timeout: 60_000 })

        await expect(wizardStageEmbed(page)).toBeVisible({ timeout: 20_000 })
    })

    test('student: the film fills the stage and hands over to the next scene', async ({ page, request }) => {
        const f = await fixture(request)
        const next = f.scenes[f.videoIndex + 1]
        expect(next, 'the fixture needs a scene AFTER the film to hand over to').toBeTruthy()

        // ?scene= is the player's own start-at-scene parameter (the wizard's Play button uses it).
        // Sitting through nine scenes of narration to reach the film is not a test, it is a wait.
        await page.goto(`/lesson/${f.code}?scene=${f.videoIndex}`, { waitUntil: 'domcontentloaded' })

        const start = page.getByRole('button', { name: /start lesson/i })
        await expect(start, 'the title screen must offer Start lesson').toBeVisible({ timeout: 30_000 })
        await start.click()

        const film = playerFilm(page)
        await expect(film, 'the video scene must actually mount its film').toBeVisible({ timeout: 30_000 })
        await expect(film).toHaveAttribute('src', new RegExp(escapeRe(embedBase(f.video.config!.bg_embed!))))
        await expect.poll(() => sceneIndex(page), { timeout: 15_000 }).toBe(f.videoIndex)

        // Full-bleed: the film is the scene, so it covers the stage rather than sitting in a card.
        await expect
            .poll(() => coverage(film, page.locator('#background-layer')), { timeout: 10_000 })
            .toBeGreaterThan(0.95)

        // Nothing times a film out from under a class — they leave it with Continue.
        const cont = page.getByRole('button', { name: CONTINUE })
        await expect(cont, 'a film waits for the class; Continue is how they leave it').toBeVisible({ timeout: 20_000 })

        await page.screenshot({ path: `${SHOTS}/student-video-scene.png` })

        await cont.click()

        // Handover: the queue moves on, and the film is torn down rather than left playing on
        // under the next scene.
        await expect.poll(() => sceneIndex(page), { timeout: 15_000 }).toBe(f.videoIndex + 1)
        await expect(film, 'the film must not keep playing behind the next scene').toHaveCount(0)
        await expect(cont).toBeHidden()

        await page.screenshot({ path: `${SHOTS}/student-after-video.png` })
    })
})

/** Which scene the player is on, read off the live Alpine component — the queue's own truth. */
async function sceneIndex(page: Page): Promise<number | null> {
    return page.evaluate(() => {
        type Root = HTMLElement & { _x_dataStack?: Array<Record<string, unknown>> }
        const data = (document.querySelector('[x-data^="lessonGame"]') as Root | null)?._x_dataStack?.[0]
        return typeof data?._sceneIndex === 'number' ? (data._sceneIndex as number) : null
    })
}

const escapeRe = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
