import { test, expect, Page } from '@playwright/test'

/**
 * The help centre as a teacher actually uses it: search narrows the page, a screenshot enlarges
 * into a lightbox that fits on screen, and every way of closing it works.
 *
 * The signed-in account can be in any of the five shipped languages, so nothing here matches on
 * English words — the search term is taken from the page itself.
 *
 * Runs against the local dev server, where AutoLoginDev supplies the session.
 */

const helpFigure = (page: Page) => page.locator('[data-help-figure] img').first()

test.describe('help centre', () => {
    test.use({ viewport: { width: 1280, height: 720 } })

    test.beforeEach(async ({ page }) => {
        await page.goto('/help', { waitUntil: 'networkidle' })
    })

    test('illustrates its sections with screenshots of the app', async ({ page }) => {
        const figure = helpFigure(page)

        // Screenshots are lazy-loaded: bring it into view before asking whether it rendered.
        await figure.scrollIntoViewIfNeeded()
        await expect(figure).toBeVisible()
        await expect(figure).toHaveAttribute('src', /\/help-media\/[a-z]{2}\//)

        // A broken <img> reports naturalWidth 0 — this proves the file really loaded.
        expect(await figure.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(100)
    })

    test('enlarging a screenshot opens a lightbox that fits on screen', async ({ page }) => {
        await page.locator('[data-help-figure] button').first().click()

        const dialog = page.locator('[role="dialog"][aria-modal="true"]')
        await expect(dialog).toBeVisible()

        const image = dialog.locator('img')
        await expect(image).toBeVisible()

        const box = (await image.boundingBox())!
        const viewport = page.viewportSize()!

        expect(box.y).toBeGreaterThanOrEqual(0)
        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height)
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width)

        // The enlarged picture must actually be bigger than the thumbnail it came from.
        const thumb = (await helpFigure(page).boundingBox())!
        expect(box.width).toBeGreaterThan(thumb.width)

        // The page behind must not scroll while the lightbox is open.
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden')
    })

    test('the lightbox closes on Escape, the X and the backdrop', async ({ page }) => {
        const dialog = page.locator('[role="dialog"][aria-modal="true"]')
        const open = async () => {
            await page.locator('[data-help-figure] button').first().click()
            await expect(dialog).toBeVisible()
        }

        await open()
        await page.keyboard.press('Escape')
        await expect(dialog).toBeHidden()

        await open()
        await dialog.getByRole('button').first().click()
        await expect(dialog).toBeHidden()

        await open()
        await page.mouse.click(20, 20) // backdrop, well clear of the image
        await expect(dialog).toBeHidden()

        expect(await page.evaluate(() => document.body.style.overflow)).toBe('')
    })

    test('search narrows the page and offers the matching part of the app', async ({ page }) => {
        // Take the search term from the page so this works whatever language the account is in.
        const classesTitle = (await page.locator('section#classes h2').first().innerText()).trim()
        const term = classesTitle.split(/\s+/)[0]

        await page.getByRole('searchbox').fill(term)

        await expect(page.locator('section#classes')).toBeVisible()
        await expect(page.locator('section#timemap')).toBeHidden()

        // "Go straight there" shortcut into the app itself — scoped to the shortcuts block, so a
        // hidden link in the collapsed mobile nav cannot satisfy it.
        const shortcuts = page.locator('section[aria-labelledby="help-shortcuts-heading"]')
        await expect(shortcuts).toBeVisible()
        await expect(shortcuts.locator('a[href$="/teacher/classes"]')).toBeVisible()
    })

    test('a search with no hits explains itself and can be cleared', async ({ page }) => {
        await page.getByRole('searchbox').fill('zzzznothinghere')
        await expect(page.locator('section#overview')).toBeHidden()

        // The "show everything again" button is the only button in the empty state.
        await page.locator('.border-dashed button').first().click()
        await expect(page.locator('section#overview')).toBeVisible()
    })
})

test.describe('language picker', () => {
    test('switching language with the flag picker changes the whole interface', async ({ page }) => {
        await page.goto('/settings', { waitUntil: 'networkidle' })

        const picker = page.getByRole('button', { expanded: false }).filter({ has: page.locator('svg') }).first()
        await picker.click()

        const options = page.getByRole('option')
        await expect(options).toHaveCount(5)

        // Every option carries a drawn flag, not an emoji: emoji flags render as letters on Windows.
        expect(await options.first().locator('svg').count()).toBeGreaterThan(0)

        await page.getByRole('option', { name: /Deutsch/ }).click()
        await page.getByRole('button', { name: /Speichern|Save|Opslaan|Enregistrer|Salva/ }).click()
        await page.waitForLoadState('networkidle')

        // The nav is outside the component that changed the setting: if it is German, the whole
        // request re-rendered in German.
        await expect(page.locator('nav')).toContainText('Übersicht')

        // Put it back so the next run starts from a known place.
        await page.goto('/settings', { waitUntil: 'networkidle' })
        await page.getByRole('button', { expanded: false }).filter({ has: page.locator('svg') }).first().click()
        await page.getByRole('option', { name: /English/ }).click()
        await page.getByRole('button', { name: /Speichern|Save/ }).click()
        await page.waitForLoadState('networkidle')
        await expect(page.locator('nav')).toContainText('Dashboard')
    })
})
