import { test, expect, type APIRequestContext, type Page } from '@playwright/test'

/**
 * Does a film swallow narration the class was supposed to hear?
 *
 * The claim under test: a video scene carries a narration track (script + audio + duration) that
 * the player never plays, so the teacher reviews eight seconds of voice-over nobody ever hears.
 *
 * This spec does not take that on trust. It measures the player's narration state on the film AND
 * on every other stage-owning scene kind in the SAME lesson (map, gallery), because the question
 * that decides whether this is a video defect or the house rule is: does a film behave differently
 * from the scene kinds that shipped long before it?
 *
 * No lesson is named. The fixture is the first published lesson that actually contains a scene of
 * kind 'video' — a lesson has no single type, the SCENE carries the kind.
 */

const SHOTS = 'tests/playwright/results-video-narration'

const CONTINUE = /^(continue|verder|weiter|continuer|continua)$/i

type Scene = {
    id: number
    kind: string
    script?: string | null
    audio_url?: string | null
    duration_seconds?: number | null
    config?: { bg_embed?: { src?: string } | null } | null
}

/** The ordered scene payload the player page inlines. Bracket-scanned: configs nest arrays. */
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

type Fixture = { id: number; code: string; title: string; scenes: Scene[]; video: Scene; videoIndex: number }

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
            const videoIndex = scenes.findIndex((s) => s.kind === 'video' && s.audio_url)
            if (videoIndex === -1) continue
            return { ...lesson, scenes, video: scenes[videoIndex], videoIndex }
        }
        throw new Error('No published lesson has a video scene carrying narration — nothing to measure.')
    })()
    return _fixture
}

/** The live player component's own state: which scene, and what its narration is doing. */
type Narration = { index: number | null; audioPlaying: boolean; src: string | null; paused: boolean | null; time: number | null }

async function narrationState(page: Page): Promise<Narration> {
    return page.evaluate(() => {
        type Root = HTMLElement & { _x_dataStack?: Array<Record<string, unknown>> }
        const d = (document.querySelector('[x-data^="lessonGame"]') as Root | null)?._x_dataStack?.[0] as
            | { _sceneIndex?: number; audioPlaying?: boolean; _audio?: HTMLAudioElement | null }
            | undefined
        const a = d?._audio ?? null
        return {
            index: typeof d?._sceneIndex === 'number' ? d._sceneIndex : null,
            audioPlaying: !!d?.audioPlaying,
            src: a ? a.src : null,
            paused: a ? a.paused : null,
            time: a ? a.currentTime : null,
        }
    })
}

/** Start the player at a given scene and settle on it. */
async function openAt(page: Page, code: string, index: number) {
    // The title screen's CTA is translated — every shipped wording is accepted.
    const start = page.getByRole('button', { name: /start lesson|start de les|unterricht starten|commencer|inizia/i })

    // One reload if the first paint never arrives. The dev server is shared with other lanes and a
    // cold Vite chunk can outlast any sane wait; a retry is honest about that without hiding a real
    // failure, because the second attempt is asserted normally.
    for (const attempt of [0, 1]) {
        await page.goto(`/lesson/${code}?scene=${index}`, { waitUntil: 'domcontentloaded' })
        try {
            await expect(start).toBeVisible({ timeout: 45_000 })
            break
        } catch (e) {
            if (attempt === 1) throw e
        }
    }
    await start.click()
    await expect.poll(() => narrationState(page).then((s) => s.index), { timeout: 30_000 }).toBe(index)
}

/** Watch a scene for a few seconds and report the loudest thing narration ever did. */
async function watchNarration(page: Page, ms = 6_000): Promise<Narration> {
    let loudest = await narrationState(page)
    const until = Date.now() + ms
    while (Date.now() < until) {
        const s = await narrationState(page)
        if (s.audioPlaying || (s.paused === false && (s.time ?? 0) > 0)) return s
        loudest = s
        await page.waitForTimeout(250)
    }
    return loudest
}

test.describe('a film and its narration track', () => {
    // The player pulls its chunks (and, on a map scene, MapLibre) on first paint, and this suite is
    // often not the only thing hitting the dev server. Give every test room rather than a fixed wait.
    test.setTimeout(180_000)

    test('the claim: the film scene ships narration the player never starts', async ({ page, request }) => {
        const f = await fixture(request)

        // What the teacher was told existed.
        expect(f.video.audio_url, 'the fixture video scene must carry a narration track').toBeTruthy()
        expect((f.video.script ?? '').length, 'and a script the teacher reviewed').toBeGreaterThan(20)

        await openAt(page, f.code, f.videoIndex)

        // The film itself mounts — this is a working scene, not a broken one.
        const film = page.locator('#background-layer .lesson-embed-bg iframe')
        await expect(film, 'the film must mount').toBeVisible({ timeout: 30_000 })

        const heard = await watchNarration(page)
        await page.screenshot({ path: `${SHOTS}/student-film-silent.png` })

        // Record the truth either way rather than asserting the outcome we expect.
        expect(
            heard.audioPlaying,
            `on the film, player narration state was ${JSON.stringify(heard)}`,
        ).toBe(false)

        // And the class is not stranded: Continue is there, so the scene is leavable.
        await expect(page.getByRole('button', { name: CONTINUE })).toBeVisible({ timeout: 20_000 })
    })

    test('control: a plain narration scene in the same lesson DOES play its track', async ({ page, request }) => {
        const f = await fixture(request)
        const i = f.scenes.findIndex((s) => s.kind === 'narration' && s.audio_url)
        test.skip(i === -1, 'this lesson has no narrated scene to compare against')

        await openAt(page, f.code, i)
        const heard = await watchNarration(page)
        await page.screenshot({ path: `${SHOTS}/student-narration-plays.png` })

        expect(heard.audioPlaying, `narration scene state was ${JSON.stringify(heard)}`).toBe(true)
        expect(heard.src, 'and it is THIS scene\'s track').toContain(f.scenes[i].audio_url!.split('/').pop()!)
    })

    /**
     * The test that was meant to refute the claim, and did the opposite.
     *
     * If every scene kind that owns the stage were silent, dropping the film's narration would be
     * the house rule and the claim would be noise. It is not the house rule. A MAP scene owns the
     * stage exactly as a film does, is paced by Continue exactly as a film is — and it plays its
     * narration as a voice-over. The player says so in as many words next to that call: "Without
     * this the script authored for a map scene was generated and stored but never heard."
     *
     * So the film is not following a convention. It is missing the fix the map already got.
     */
    test('a map scene owns the stage AND narrates — so silence is not the house rule', async ({ page, request }) => {
        const f = await fixture(request)
        const mapIndex = f.scenes.findIndex((s) => s.kind === 'map' && s.audio_url)
        test.skip(mapIndex === -1, 'this lesson has no narrated map scene to compare against')

        await openAt(page, f.code, mapIndex)
        const heard = await watchNarration(page, 8_000)
        await page.screenshot({ path: `${SHOTS}/student-map-narrates.png` })

        console.log('map scene narration state:', JSON.stringify(heard))

        expect((f.scenes[mapIndex].script ?? '').length, 'the map scene carries a script too').toBeGreaterThan(20)
        expect(heard.audioPlaying, 'the map plays its voice-over over a Continue-paced stage').toBe(true)
        // Same pacing model as the film: the class leaves it by hand, the audio does not drive it.
        await expect(page.getByRole('button', { name: CONTINUE })).toBeVisible({ timeout: 20_000 })
    })
})
