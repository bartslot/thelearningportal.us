/**
 * The local "Test tools" panel, driven as a guest — a refutation spec.
 *
 * A finding from the guest-try lane claimed the pink flask widget "offers a guest three more
 * fenced routes". This spec puts a real guest in front of the real widget and asks the three
 * questions that decide whether that is a defect:
 *
 *   1. Does the widget exist for anyone outside local?  It is wrapped in
 *      `@if (app()->environment('local') && auth()->check())` in both layouts that render it
 *      (components/layouts/app.blade.php:43, components/layouts/landing.blade.php:38), and the
 *      blade itself carries a visible "local" badge. A browser cannot switch APP_ENV, so this
 *      spec asserts the half it can: the panel is a labelled, deliberately-local dev tool, and
 *      it does not render for a visitor who is not signed in at all.
 *   2. Can a guest hit the links by accident? No — they live in a collapsed `x-show` panel.
 *   3. If a guest does open the panel and click one, does anything leak? No —
 *      App\Http\Middleware\RestrictGuestDemo hard-allowlists two route names and aborts 403 on
 *      everything else. The links are dead ends, not doors.
 *
 * Needs the same auto-login-free server as guest-sandbox.spec.ts:
 *     APP_AUTO_LOGIN=false php artisan serve --port=8011 --no-reload
 * Run with --workers=1: every visit to /try mints a guest account and clones the demo lesson.
 */
import { test, expect, type BrowserContext, type Page } from '@playwright/test';

const GUEST_BASE = process.env.GUEST_BASE_URL ?? 'http://127.0.0.1:8011';

/** The three links the finding named, with the label a teacher would read on each. */
const PANEL_LINKS = [
  ['Results hub', '/teacher/results'],
  ['Lessons', '/teacher'],
  ['New lesson', '/teacher/lessons/create'],
] as const;

test.describe('dev Test-tools panel — guest fence', () => {
  let ctx: BrowserContext;
  let page: Page;

  test.beforeAll(async ({ browser }) => {
    ctx = await browser.newContext({ baseURL: GUEST_BASE });
    page = await ctx.newPage();

    await page.goto('/try', { waitUntil: 'domcontentloaded' });
    // /try hands the visitor a sandbox lesson and drops them straight in the wizard.
    await page.waitForURL(/\/teacher\/lessons\/\d+/, { timeout: 30_000 });
  });

  test.afterAll(async () => {
    await ctx?.close();
  });

  test('a signed-out visitor never sees the panel at all', async ({ browser }) => {
    const anon = await browser.newContext({ baseURL: GUEST_BASE });
    const anonPage = await anon.newPage();
    await anonPage.goto('/', { waitUntil: 'domcontentloaded' });

    await expect(anonPage.locator('[data-dev-panel]')).toHaveCount(0);
    await anon.close();
  });

  test('the links sit in a collapsed panel, so a guest cannot reach them by accident', async () => {
    const panel = page.locator('[data-dev-panel]');
    await expect(panel).toHaveCount(1);

    // Closed by default: every one of the three links is hidden, not merely off-screen.
    for (const [label] of PANEL_LINKS) {
      await expect(panel.getByRole('link', { name: label, exact: true })).toBeHidden();
    }

    await page.screenshot({ path: 'tests/playwright/results/dev-panel-guest-closed.png' });

    // Only a deliberate click on the flask opens it — and it announces itself as local-only.
    await panel.getByRole('button').first().click();
    await expect(panel.getByText('Test tools')).toBeVisible();
    await expect(panel.getByText('local', { exact: true })).toBeVisible();

    for (const [label] of PANEL_LINKS) {
      await expect(panel.getByRole('link', { name: label, exact: true })).toBeVisible();
    }

    await page.screenshot({ path: 'tests/playwright/results/dev-panel-guest-open.png' });
  });

  test('opening one anyway lands on 403 — the fence holds, nothing leaks', async () => {
    for (const [label, path] of PANEL_LINKS) {
      const res = await page.request.get(path, { maxRedirects: 0 });
      expect(res.status(), `${label} → ${path}`).toBe(403);
      expect(await res.text()).toContain('demo can only open the lesson it was given');
    }

    // And the same through a real click, not just a fetch.
    const panel = page.locator('[data-dev-panel]');
    if (await panel.getByRole('link', { name: 'Lessons', exact: true }).isHidden()) {
      await panel.getByRole('button').first().click();
    }
    await panel.getByRole('link', { name: 'Lessons', exact: true }).click();
    await expect(page.getByText(/403|Forbidden|demo can only open/i).first()).toBeVisible();
    await page.screenshot({ path: 'tests/playwright/results/dev-panel-guest-403.png' });
  });
});
