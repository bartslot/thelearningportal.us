/**
 * /try — the anonymous guest sandbox, driven as a stranger would drive it.
 *
 * WHY THIS SPEC NEEDS ITS OWN SERVER
 * ---------------------------------
 * `App\Http\Middleware\AutoLoginDev` is appended to the whole `web` stack and signs in
 * teacher@example.com on the first request of any session where `app()->isLocal()` and
 * `config('app.auto_login')` are both true. A "fresh browser context" does NOT defeat it —
 * a brand new context is exactly the case it fires on. Worse, it fires BEFORE the /try
 * controller runs, so `GuestDemoController` sees an authenticated non-guest and does what it
 * is supposed to do for a real teacher: redirects to `teacher.lessons.create`. On the normal
 * dev server the guest path is therefore unreachable, and a spec pointed at :8000 would
 * "pass" while never once creating a guest.
 *
 * So this spec talks to a second dev server started with APP_AUTO_LOGIN=false:
 *
 *     APP_AUTO_LOGIN=false php artisan serve --port=8011 --no-reload
 *
 * `--no-reload` is load-bearing. ServeCommand::$passthroughVariables whitelists the env vars
 * it forwards to the PHP server process (APP_ENV, PATH, XDEBUG_*, …) and blanks every other
 * one — unless --no-reload is passed, which forwards $_ENV verbatim. Without the flag the
 * override is silently dropped and auto-login is still on. Point the spec elsewhere with
 * GUEST_BASE_URL if you serve it on another port.
 *
 * ONE GUEST, ONE SANDBOX
 * ----------------------
 * Each visit to /try writes a user row plus a full clone of the demo lesson (18 scenes for
 * Abel Tasman). The tests therefore share a single context, created once — a per-test context
 * would mean a per-test guest account. Revisiting /try inside that session is free:
 * GuestDemoSession::current() hands back the sandbox this browser already owns.
 *
 * Run it with --workers=1 so the tests share that one context in order.
 */
import { test, expect, type BrowserContext, type Page } from '@playwright/test';

const GUEST_BASE = process.env.GUEST_BASE_URL ?? 'http://127.0.0.1:8011';

/** Routes a guest must NOT reach. Each is a real teacher surface behind RestrictGuestDemo. */
const FENCED = [
  ['the dashboard', '/teacher'],
  ['the lessons list', '/teacher/lessons'],
  ['account settings', '/settings'],
  ['the help centre', '/help'],
  ['the time map', '/teacher/timemap'],
  ['the lesson builder', '/teacher/lessons/create'],
] as const;

test.describe('/try — anonymous guest sandbox', () => {
  let ctx: BrowserContext;
  let page: Page;
  let wizardUrl: string;
  let lessonId: string;

  test.beforeAll(async ({ browser }) => {
    // Guard the harness assumption out loud. If auto-login is still on, /try lands on the
    // lesson builder and every assertion below would be testing a signed-in teacher.
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
      test.skip(true, `No server at ${GUEST_BASE}. Start one: APP_AUTO_LOGIN=false php artisan serve --port=8011 --no-reload`);
    }
    if (/\/teacher\/lessons\/create/.test(landed)) {
      test.skip(true, `${GUEST_BASE} still auto-logs in a teacher (/try → ${landed}). Restart it with APP_AUTO_LOGIN=false … --no-reload`);
    }

    // One guest account for the whole file. Claim the sandbox here so no later test depends on
    // an earlier one having run (or passed) — each can be read on its own.
    ctx = await browser.newContext({ baseURL: GUEST_BASE });
    page = await ctx.newPage();
    await page.goto('/try');
    await page.waitForURL(/\/teacher\/lessons\/\d+\/wizard/);
    wizardUrl = page.url();
    lessonId = wizardUrl.match(/lessons\/(\d+)\/wizard/)![1];
  });

  test.afterAll(async () => {
    await ctx?.close();
  });

  /**
   * The landing page opens on a full-screen intro that types a learning goal and flies through a
   * ring of paintings before the hero settles. A visitor either sits through it or presses Skip,
   * bottom right — Configure is not reachable until one of those happens.
   */
  async function skipHeroIntro() {
    const skip = page.getByRole('button', { name: /^skip$/i });
    // Skip does not jump to the end — it winds the GSAP timeline up to TIMESCALE_SKIP, so the
    // button stays on screen and the reveal is what to wait for, not the button disappearing.
    if (await skip.isVisible().catch(() => false)) await skip.click();
    await expect(page.locator('html')).toHaveClass(/hero-revealed/, { timeout: 20_000 });
  }

  test('a stranger presses Configure on the landing page and lands in a real wizard', async () => {
    await page.goto('/');
    await skipHeroIntro();

    const configure = page.getByRole('link', { name: /configure/i }).first();
    await expect(configure).toBeVisible();
    await expect(configure).toHaveAttribute('href', /\/try$/);

    await configure.click();
    await page.waitForURL(/\/teacher\/lessons\/\d+\/wizard/);

    // Same sandbox they were already given, not a second throwaway account.
    expect(page.url()).toBe(wizardUrl);

    // The real editor, not a cut-down preview: the scene rail and the canvas are both there.
    await expect(page.getByRole('button', { name: /^Format$/ })).toBeVisible();
    await expect(page.locator('#wizard-canvas, [x-ref="stage"], canvas').first()).toBeVisible();

    await page.screenshot({ path: 'tests/playwright/results/guest-sandbox/01-wizard.png', fullPage: false });
  });

  test('the sandbox lesson belongs to the guest, not to the teacher it was cloned from', async () => {
    // The demo lesson the landing page advertises is published under some teacher's account.
    // The guest must be looking at a different row — their own copy.
    const manifest = await page.request.get('/api/v1/catalog/manifest');
    const demoIds: number[] = ((await manifest.json()).lessons ?? []).map((l: { id: number }) => l.id);

    expect(demoIds).not.toContain(Number(lessonId));
  });

  for (const [label, path] of FENCED) {
    test(`${label} refuses the guest (${path})`, async () => {
      const res = await page.request.get(path, { maxRedirects: 0 });
      expect(res.status(), `${path} should be forbidden for a guest`).toBe(403);
    });
  }

  test('another teacher\'s lesson refuses the guest even though the route is allowed', async () => {
    // teacher.lessons.wizard is on RestrictGuestDemo's allow-list, so ownership is the only
    // thing standing between a guest and every lesson in the database. Walk the ids around
    // the sandbox: any of them that exists and is not theirs must be refused.
    const mine = Number(lessonId);
    const neighbours = [mine - 1, mine - 2, 3, 41].filter((id) => id > 0 && id !== mine);

    let checked = 0;
    for (const id of neighbours) {
      const res = await page.request.get(`/teacher/lessons/${id}/wizard?step=4`, { maxRedirects: 0 });
      if (res.status() === 404) continue;   // no such lesson — nothing to prove here
      expect(res.status(), `lesson ${id} must not open for a guest`).toBe(403);
      checked++;
    }
    expect(checked, 'no neighbouring lesson existed to test ownership against').toBeGreaterThan(0);
  });

  test('Publish is replaced by Sign up', async () => {
    await page.goto(wizardUrl);

    const signUp = page.getByRole('link', { name: /create an account/i });
    await expect(signUp).toBeVisible();
    await expect(signUp).toHaveAttribute('href', /\/login$/);
    await expect(page.getByRole('button', { name: /^Publish$/ })).toHaveCount(0);

    await page.screenshot({ path: 'tests/playwright/results/guest-sandbox/02-signup-not-publish.png' });
  });

  test('a hand-typed earlier step is clamped to the editor, not shown', async () => {
    // LessonWizard::clampForGuestDemo pins a guest to steps 4-5. Step 1-3 are the settings and
    // Generate screens; the Generate step alone would let an anonymous visitor spend AI credits.
    await page.goto(`/teacher/lessons/${lessonId}/wizard?step=1`);

    // What renders is the Configure canvas — scene rail, Format panel, Sign up — not the
    // step-1 settings form. The step indicator (Settings / Story / Generate / …) only exists
    // on steps 1-3, so its absence is the clamp's own signature.
    await expect(page.getByRole('button', { name: /^Format$/ })).toBeVisible();
    await expect(page.getByText(/SCENES\s*·/i)).toBeVisible();
    await expect(page.getByRole('link', { name: /^Generate$/ })).toHaveCount(0);
    await expect(page.locator('a[href*="wizard?step=1"], a[href*="wizard?step=2"], a[href*="wizard?step=3"]')).toHaveCount(0);

    await page.screenshot({ path: 'tests/playwright/results/guest-sandbox/03-step1-clamped.png' });
  });

  test('an AI-spend button explains itself rather than failing silently', async () => {
    // The editor still shows "Regenerate all" on a quiz scene. BlocksGuestDemoSpending refuses
    // the call server-side, and the refusal has to reach the visitor as words — a button that
    // appears to do nothing is the exact failure this fence is supposed to avoid.
    await page.goto(wizardUrl);

    const regenerate = page.getByRole('button', { name: /regenerate all/i }).first();
    if (await regenerate.count() === 0) {
      test.skip(true, 'the sandbox did not open on a quiz scene — no AI-spend button on screen');
    }
    await regenerate.click();

    await expect(page.getByText(/needs an account/i)).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: 'tests/playwright/results/guest-sandbox/04-ai-spend-refused.png' });
  });

  test('no control is offered that would bounce the guest back to this same screen', async () => {
    await page.goto(wizardUrl);

    // "Edit story" links to step 2, which the clamp sends straight back here.
    await expect(page.getByRole('link', { name: /edit story/i })).toHaveCount(0);

    // Every link a guest can actually SEE must lead somewhere they may go: the wizard itself,
    // the public site, or the sign-up page. Measured, not read off the source — a link inside a
    // closed panel has a 0x0 box and is not on offer.
    //
    // [data-dev-panel] is excluded on purpose: the local Test-tools flask is not shipped chrome
    // (it renders on APP_ENV=local only), and its Lessons / New lesson / Results hub links are
    // collapsed inside it. Judging the fence on those would be judging a dev widget.
    const offered = await page.locator('a[href]:not([data-dev-panel] a)').evaluateAll(
      (as, forbidden) =>
        as
          .map((a) => {
            const el = a as HTMLAnchorElement;
            const box = el.getBoundingClientRect();
            return {
              path: new URL(el.getAttribute('href') ?? '', location.origin).pathname,
              label: (el.innerText || el.getAttribute('aria-label') || '').trim(),
              seen: box.width > 0 && box.height > 0 && getComputedStyle(el).visibility !== 'hidden',
            };
          })
          .filter((l) => l.seen && forbidden.some((f) => l.path === f || l.path.startsWith(`${f}/`)))
          .map((l) => `${l.label || '(no label)'} → ${l.path}`),
      FENCED.map(([, p]) => p) as unknown as string[],
    );

    expect(offered, `the wizard offers a guest links they get a 403 from: ${offered.join(', ')}`).toEqual([]);
  });

  test('the Help control does not dead-end on a 403', async () => {
    // The Help icon sits in the top-right toolbar right beside Sign up, and it is rendered
    // unconditionally (step3-scene-configurator.blade.php). It targets help.index, which
    // RestrictGuestDemo forbids — and it opens in a NEW TAB, so the guest is handed a bare
    // Laravel 403 page with no way back to their lesson except closing the tab.
    await page.goto(wizardUrl);

    const help = page.getByRole('link', { name: /^help$/i }).first();
    await expect(help).toBeVisible();

    const opened = ctx.waitForEvent('page');
    await help.click();
    const helpPage = await opened;
    await helpPage.waitForLoadState('domcontentloaded');

    await helpPage.screenshot({ path: 'tests/playwright/results/guest-sandbox/05-help-403.png' });
    const body = await helpPage.locator('body').innerText();
    await helpPage.close();

    expect(body, 'Help must not hand a guest a 403 error page').not.toMatch(/403|FORBIDDEN/i);
  });

  test('the way out of the wizard is the public site, not a lessons list they cannot open', async () => {
    await page.goto(wizardUrl);

    // A guest has no lesson list to go back to, so this arrow points at the site they came from.
    const exit = page.getByRole('link', { name: /back to lessons/i });
    await expect(exit).toBeVisible();

    const href = new URL((await exit.getAttribute('href'))!, GUEST_BASE);
    expect(href.pathname, 'the wizard exit must not aim at a route the guest gets a 403 from').toBe('/');

    // And it works: following it leaves the editor for the public landing page, not a 403.
    await exit.click();
    await page.waitForURL(new RegExp(`^${GUEST_BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/?$`));
    await expect(page.locator('body')).not.toContainText('403');
  });

  test('a signed-in teacher is never handed a throwaway account', async ({ browser }) => {
    // The other half of the contract, on the ordinary dev server where auto-login signs in a
    // real teacher: pressing Configure must NOT sign them out into a guest sandbox.
    const teacherCtx = await browser.newContext();   // baseURL from the config = the :8000 server
    const teacherPage = await teacherCtx.newPage();

    await teacherPage.goto('/try');
    await expect(teacherPage).toHaveURL(/\/teacher\/lessons\/create/);

    await teacherCtx.close();
  });
});
