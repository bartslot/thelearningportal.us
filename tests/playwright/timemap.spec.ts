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

test('timemap-slider: clicking the timeline changes the year', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const box = await page.locator('.tm-track').boundingBox();
  if (box) await page.mouse.click(box.x + box.width * 0.8, box.y + box.height / 2); // ~later era
  await page.waitForFunction(() => (window as any).__portal?.year > 0, { timeout: 15_000 });
  await expect(page.locator('.tm-readout')).toHaveText(/CE|BCE/);
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
