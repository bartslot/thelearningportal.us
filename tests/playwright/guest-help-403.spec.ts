/**
 * REFUTATION SPEC — "the wizard Help icon hands a landing-page guest a bare 403 in a new tab".
 *
 * The claim under test is narrow and falsifiable, so this file tries to break it four ways
 * before agreeing with it:
 *
 *   1. Maybe a guest never actually sees the control (hidden, zero-sized, off-canvas, or
 *      swapped out the way Publish is swapped for "Sign up").
 *   2. Maybe the anchor is inert — a real click is intercepted by JS, or the toolbar row is
 *      overlaid so the click never lands on the link at all.
 *   3. Maybe a guard sits further down the chain: RestrictGuestDemo could redirect a guest to
 *      their wizard rather than abort, or the 403 view could be a styled page with a way back.
 *   4. Maybe it only reproduces for a signed-in teacher — i.e. a state no real visitor reaches.
 *      The same control is exercised as a normal teacher for contrast.
 *
 * Only the click path is used. Fetching /help directly proves nothing about the toolbar, and
 * the original report was challenged on exactly that point, so every 403 here comes from a
 * genuine mouse press on the rendered icon and the tab the browser itself opened.
 *
 * HARNESS — this needs its own server, for the reason guest-sandbox.spec.ts documents at length:
 * AutoLoginDev signs teacher@example.com in on the first request of ANY new context, before
 * /try runs, so GuestDemoController sees a real teacher and redirects to the lesson builder.
 * A fresh context does not defeat it. Start the second server first:
 *
 *     APP_AUTO_LOGIN=false php artisan serve --port=8012 --no-reload
 *
 * (--no-reload is load-bearing — without it ServeCommand blanks the env override.)
 * Override with GUEST_HELP_BASE_URL. The normal auto-login server on :8000 is used, unchanged,
 * for the teacher-contrast test.
 *
 * No motion is asserted anywhere here, so the default SwiftShader chromium project is fine.
 */
import { test, expect, type BrowserContext, type Page } from '@playwright/test';

const GUEST_BASE = process.env.GUEST_HELP_BASE_URL ?? 'http://127.0.0.1:8012';
const TEACHER_BASE = process.env.PW_BASE_URL ?? 'http://127.0.0.1:8000';
const SHOTS = 'tests/playwright/results/guest-help-403';

/** The toolbar control, addressed the way a screen reader names it — never by CSS class. */
const helpControl = (page: Page) => page.getByRole('link', { name: 'Help', exact: true });

test.describe.configure({ mode: 'serial', timeout: 180_000 });

test.describe('the wizard Help icon, pressed by a landing-page guest', () => {
  let ctx: BrowserContext;
  let page: Page;

  test.beforeAll(async ({ browser }) => {
    const probe = await browser.newContext({ baseURL: GUEST_BASE });
    const probePage = await probe.newPage();
    let reachable = true;
    try {
      await probePage.goto('/try', { waitUntil: 'commit' });
    } catch {
      reachable = false;
    }
    const landed = probePage.url();
    await probe.close();

    if (!reachable) {
      test.skip(true, `No server at ${GUEST_BASE}. Start: APP_AUTO_LOGIN=false php artisan serve --port=8012 --no-reload`);
    }
    if (/\/teacher\/lessons\/create/.test(landed)) {
      test.skip(true, `${GUEST_BASE} still auto-logs a teacher in (/try → ${landed}). Restart with APP_AUTO_LOGIN=false … --no-reload`);
    }

    // One guest for the file — every /try hit writes a user plus a full clone of the demo
    // lesson, and GuestDemoSession::current() hands this browser back the same sandbox.
    ctx = await browser.newContext({ baseURL: GUEST_BASE });
    page = await ctx.newPage();
    // The wizard is an 18-scene editor behind a single-process `artisan serve`; waiting for
    // full `load` here regularly outruns the budget while the page is already usable. Wait on the
    // URL landing and then on a real control, never on `load`.
    await page.goto('/try', { waitUntil: 'commit' });
    await page.waitForURL(/\/teacher\/lessons\/\d+\/wizard/, { waitUntil: 'commit' });
    // Wait on the toolbar having rendered at all — Format is always there, guest or not.
    await expect(page.getByRole('button', { name: 'Format' })).toBeVisible({ timeout: 30_000 });
  });

  test.afterAll(async () => { await ctx?.close(); });

  test('refutation 1 — the guest is a guest, and Publish really was swapped for Sign up', async () => {
    // If this fails, everything below is testing a signed-in teacher and means nothing.
    await expect(page.getByRole('link', { name: 'Create an account' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Publish' })).toHaveCount(0);
  });

  test('refutation 2 — the Help control is genuinely visible and hit-testable, not hidden', async () => {
    const help = helpControl(page);
    await expect(help).toBeVisible();

    const box = await help.boundingBox();
    expect(box, 'Help has no layout box').not.toBeNull();
    expect(box!.width).toBeGreaterThan(20);
    expect(box!.height).toBeGreaterThan(20);

    // Inside the viewport, and nothing painted on top of it: elementFromPoint at the icon's
    // centre must land on the anchor itself, or the "click" in the next test proves nothing.
    const vp = page.viewportSize()!;
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.y).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(vp.width);

    const topmostIsHelp = await page.evaluate(([x, y]) => {
      const el = document.elementFromPoint(x as number, y as number);
      return !!el?.closest('a[href*="/help"]');
    }, [box!.x + box!.width / 2, box!.y + box!.height / 2]);
    expect(topmostIsHelp, 'something is painted over the Help icon').toBe(true);

    await page.screenshot({ path: `${SHOTS}/01-guest-toolbar.png` });
  });

  test('refutation 3 — clicking it opens a new tab, and that tab is a bare 403', async () => {
    const help = helpControl(page);

    // A real press. Not page.goto, not a fetch — the browser opens the tab itself because the
    // anchor carries target="_blank".
    const [opened] = await Promise.all([
      ctx.waitForEvent('page'),
      help.click(),
    ]);
    await opened.waitForLoadState('domcontentloaded');

    // The tab that opened is the help centre URL, and it answered 403.
    expect(opened.url()).toContain('/help');

    const body = ((await opened.locator('body').innerText()) ?? '').trim();
    expect(body).toMatch(/403/);
    expect(body.toUpperCase()).toContain('THIS DEMO CAN ONLY OPEN THE LESSON IT WAS GIVEN');

    // A guard elsewhere in the chain would have shown up as a redirect back into the wizard,
    // or as a styled page carrying a way out. Neither is here: no nav, no link home.
    expect(opened.url()).not.toMatch(/\/wizard/);
    const escapeRoutes = await opened.locator('a[href]').count();
    expect(escapeRoutes, 'the 403 page offers no link back').toBe(0);

    // "Blinding white against the dark editor" — check the actual painted background rather
    // than trusting the screenshot.
    const bg = await opened.evaluate(() => getComputedStyle(document.body).backgroundColor);

    await opened.screenshot({ path: `${SHOTS}/02-help-403.png`, fullPage: true });
    // eslint-disable-next-line no-console
    console.log(`[guest-help-403] tab=${opened.url()} bodyBg=${bg} links=${escapeRoutes} text="${body.replace(/\s+/g, ' ').slice(0, 90)}"`);

    // The lesson survives in the original tab — the damage is a dead end, not data loss.
    await expect(helpControl(page)).toBeVisible();
    await opened.close();
  });

  test('refutation 4 — for a real teacher the same control works, so this is guest-only', async ({ browser }) => {
    const tctx = await browser.newContext({ baseURL: TEACHER_BASE });
    const tpage = await tctx.newPage();
    try {
      await tpage.goto('/try', { waitUntil: 'domcontentloaded' });
      // Auto-login makes /try bounce a teacher to the builder; go straight to a real lesson.
      await tpage.goto('/teacher/lessons/41/wizard?step=4', { waitUntil: 'domcontentloaded' });
      // The seeded dev teacher runs the UI in French, so the control is labelled "Aide". The
      // label is not what is under test — accept whichever language this account is in, but
      // still address it by its accessible name rather than a class.
      const help = tpage.getByRole('link', { name: /^(Help|Aide|Hilfe|Hulp|Aiuto)$/ });
      await expect(help).toBeVisible({ timeout: 30_000 });

      const [opened] = await Promise.all([
        tctx.waitForEvent('page'),
        help.click(),
      ]);
      await opened.waitForLoadState('domcontentloaded');
      const body = ((await opened.locator('body').innerText()) ?? '').trim();
      expect(body).not.toMatch(/403/);
      await opened.screenshot({ path: `${SHOTS}/03-teacher-help-ok.png` });
      await opened.close();
    } finally {
      await tctx.close();
    }
  });
});
