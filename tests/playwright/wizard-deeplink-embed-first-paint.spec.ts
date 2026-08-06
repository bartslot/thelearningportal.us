import { test, expect, type Page } from '@playwright/test'

/**
 * REFUTATION RUN — "opening the editor straight at a video (or 3D-background) scene leaves the
 * stage black".
 *
 * The claim is that the wizard's scene deep-link (?step=4&scene={id}) — the exact URL the player
 * preview's "Edit scene" pill builds, and the URL a teacher's refresh keeps — paints nothing for an
 * embed-backed scene, and that the film only appears after a detour through another rail tile.
 *
 * This spec does NOT reuse the video-scene fixture. It names a real published lesson (Anne Frank,
 * #3 / CF0AVG) because that lesson carries BOTH halves of the claim: a scene of kind 'video'
 * (YouTube) and an ordinary narration scene carrying a Sketchfab 3D BACKGROUND. It also runs a
 * CONTROL — deep-linking to an ordinary picture scene — to separate "embeds never paint on first
 * load" from "the stage paints nothing at all on first load", which would be a different bug.
 */

const SHOTS = 'tests/playwright/results/deeplink-embed'
const LESSON = 3
const VIDEO_SCENE = 25          // kind=video, YouTube iIXx0rdRfPk
const EMBED_BG_SCENE = 22       // kind=narration, Sketchfab background
const PICTURE_SCENE_INDEX = 0   // control: an ordinary scene with an image background

const stageEmbed = (page: Page) =>
    page.locator('#lesson-canvas').locator('xpath=..').locator('.lesson-embed-bg iframe')

/** Wait for the wizard's 3D stage bridge to exist — never a fixed sleep. */
async function stageReady(page: Page) {
    await page.waitForFunction(
        () => !!(window as unknown as { __lessonStage?: unknown }).__lessonStage,
        null,
        { timeout: 60_000 },
    )
}

/** How many embed hosts are on the page right now. */
const embedCount = (page: Page) => page.locator('.lesson-embed-bg').count()

test.describe('deep-linking the wizard straight at an embed-backed scene', () => {
    test('teacher: the film paints on arrival, without a detour through another tile', async ({ page }) => {
        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4&scene=${VIDEO_SCENE}`, { waitUntil: 'domcontentloaded' })
        await stageReady(page)

        // The inspector proves the deep-link DID select the film — so a black stage would be the
        // stage disagreeing with the panel next to it, not the teacher landing somewhere else.
        await expect(
            page.locator('input[placeholder*="youtu.be"]'),
            'the deep-link must land on the film inspector',
        ).toBeVisible({ timeout: 30_000 })

        // Give first paint every chance: the bridge loads a three.js chunk before it can apply a
        // scene, so poll rather than assert once.
        const onArrival = await page
            .waitForFunction(() => document.querySelectorAll('.lesson-embed-bg').length > 0, null, { timeout: 25_000 })
            .then(() => true)
            .catch(() => false)

        await page.screenshot({ path: `${SHOTS}/arrival-video.png` })
        console.log(`[deep-link video] embed hosts on arrival: ${await embedCount(page)} (painted=${onArrival})`)

        // The claimed cure: click any other tile, then come back.
        if (!onArrival) {
            const other = page.locator(`[data-thumb][data-scene-id]:not([data-scene-id="${VIDEO_SCENE}"])`).first()
            await other.click()
            await page.locator(`[data-thumb][data-scene-id="${VIDEO_SCENE}"]`).click()
            await expect(stageEmbed(page), 'the detour is supposed to be what fixes it').toBeVisible({ timeout: 30_000 })
            await page.screenshot({ path: `${SHOTS}/after-detour-video.png` })
            console.log(`[deep-link video] embed hosts after detour: ${await embedCount(page)}`)
        }

        expect(onArrival, 'the film must be on the editor stage when the teacher arrives').toBeTruthy()
        await expect(stageEmbed(page)).toHaveAttribute('src', /youtube\.com\/embed\/iIXx0rdRfPk/)
    })

    test('teacher: a 3D background paints on arrival too', async ({ page }) => {
        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4&scene=${EMBED_BG_SCENE}`, { waitUntil: 'domcontentloaded' })
        await stageReady(page)

        const onArrival = await page
            .waitForFunction(() => document.querySelectorAll('.lesson-embed-bg').length > 0, null, { timeout: 25_000 })
            .then(() => true)
            .catch(() => false)

        await page.screenshot({ path: `${SHOTS}/arrival-sketchfab.png` })
        console.log(`[deep-link 3D] embed hosts on arrival: ${await embedCount(page)} (painted=${onArrival})`)

        expect(onArrival, 'a Sketchfab background must be on the stage when the teacher arrives').toBeTruthy()
        await expect(stageEmbed(page)).toHaveAttribute('src', /sketchfab\.com\/models\//)
    })

    test('control: deep-linking to an ordinary picture scene does paint the stage', async ({ page }) => {
        // Which scene id sits at PICTURE_SCENE_INDEX is read from the rail, not hard-coded.
        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`, { waitUntil: 'domcontentloaded' })
        await stageReady(page)
        const id = await page.locator('[data-thumb][data-scene-id]').nth(PICTURE_SCENE_INDEX).getAttribute('data-scene-id')
        expect(id, 'the rail must have tiles').toBeTruthy()

        await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4&scene=${id}`, { waitUntil: 'domcontentloaded' })
        await stageReady(page)

        // A picture background is a WebGL texture on the canvas, so it is measured by asking the
        // stage what it painted rather than by looking for a DOM node.
        const painted = await page
            .waitForFunction(() => {
                const c = document.getElementById('lesson-canvas') as HTMLCanvasElement | null
                if (!c) return false
                const gl = (c as unknown as { __lessonPlayer?: unknown }).__lessonPlayer
                return !!gl
            }, null, { timeout: 25_000 })
            .then(() => true)
            .catch(() => false)

        await page.screenshot({ path: `${SHOTS}/arrival-picture.png` })
        console.log(`[deep-link picture] player mounted on arrival: ${painted}`)
        expect(painted, 'the control scene must mount its player on a deep-link').toBeTruthy()
    })
})
