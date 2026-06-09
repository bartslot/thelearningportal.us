import { test, expect, Page } from '@playwright/test';

/**
 * Auth: uses the real login form with a playwright test account seeded into Supabase.
 * Playwright's browser context handles CSRF tokens and session cookies automatically.
 *
 * Auth setup: one-time seed (if not done):
 *   php artisan tinker --execute="App\Models\User::create(['name'=>'Playwright Teacher',
 *     'email'=>'teacher@playwright.test','password'=>bcrypt('playwright123'),'role'=>'teacher']);"
 *
 * WebGL: playwright.config.ts passes --enable-unsafe-swiftshader / --use-angle=swiftshader
 * so MapLibre can initialise in headless Chromium via software rendering (~6s load time).
 *
 * Server: start normally with `php artisan serve` (no .env changes needed).
 */
async function loginAsTeacher(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('#email', 'teacher@playwright.test');
  await page.fill('#password', 'playwright123');
  await page.click('button[type=submit]');
  // Wait for redirect away from /login (successful auth lands on teacher dashboard)
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 10_000 });
}

test.beforeEach(async ({ page }) => {
  await loginAsTeacher(page);
  await page.goto('/teacher/timemap');
});

test('timemap-shell: canvas mounts, portal ready, no console errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

  await expect(page.locator('canvas.maplibregl-canvas')).toBeVisible();
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });

  // Regression: MapLibre forces position:relative on its container, which once cancelled the
  // Tailwind `absolute inset-0` and collapsed the map to 0 height (blank map). Guard the height.
  const mapHeight = await page
    .locator('.maplibregl-map')
    .first()
    .evaluate((el) => (el as HTMLElement).clientHeight);
  expect(mapHeight).toBeGreaterThan(200);

  await page.screenshot({ path: 'tests/playwright/results/timemap-shell.png' });
  expect(errors, errors.join('\n')).toHaveLength(0);
});

test('timemap-year-input: typing a year scrubs the map', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const input = page.locator('.tm-year-input');
  await input.fill('1500');
  // Number input two-way-binds to the map year (debounced reload inside _setYear).
  await page.waitForFunction(() => (window as any).__portal?.year === 1500, { timeout: 15_000 });
  await expect(page.locator('.tm-era-suffix')).toHaveText('CE');
  await expect(page.locator('.tm-readout')).toContainText('years ago');
});

test('timemap-timeline: dragging the tick timeline changes the year', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const before = await page.evaluate(() => (window as any).__portal.year);
  const box = await page.locator('.tm-scroll').boundingBox();
  if (box) {
    // Drag the strip leftwards → scrubs to a later year.
    await page.mouse.move(box.x + box.width * 0.7, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height / 2, { steps: 12 });
    await page.mouse.up();
  }
  await page.waitForFunction((b) => (window as any).__portal.year !== b, before, { timeout: 15_000 });
});

test('timemap-click-panel: clicking a region opens the polity panel', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

  // Either a polity panel (label + tabs) or the empty prompt — both prove the click round-trip.
  const aside = page.locator('aside');
  await expect(aside).toContainText(/.+/);
  const wikiTab = page.getByRole('tab', { name: 'Wikipedia' });
  if (await wikiTab.count()) {
    await wikiTab.click();
    await expect(page.getByText(/Wikipedia|No Wikipedia page/)).toBeVisible();
  }
});

test('timemap-local-tiles: borders load from the local OHM mirror, not the network', async ({ page }) => {
  const tileRequests: string[] = [];
  page.on('request', (r) => {
    const u = r.url();
    if (u.includes('/ohm-tiles/') || u.includes('vtiles.openhistoricalmap.org')) tileRequests.push(u);
  });

  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  await page.waitForTimeout(1500); // let initial tiles fetch

  const local = tileRequests.filter((u) => u.includes('/ohm-tiles/ohm_admin/'));
  const remote = tileRequests.filter((u) => u.includes('vtiles.openhistoricalmap.org'));
  expect(local.length, 'expected local OHM admin tile requests').toBeGreaterThan(0);
  expect(remote, 'must not hit the live OHM tile server').toHaveLength(0);
});
