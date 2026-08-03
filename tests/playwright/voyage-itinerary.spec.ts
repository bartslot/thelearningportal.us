import { test, expect, Page } from '@playwright/test';

/**
 * The voyage overview's itinerary list — the whole trip as numbered stops, in sailing order,
 * reorderable under Format → Route.
 *
 * Guards the two things that made the overview scene useless before it existed: the scene showed
 * no list of where the voyage actually goes, and there was no way to change the order of calls.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;

async function openOverviewScene(page: Page): Promise<void> {
  // :visible matters — the rail's markup includes rows that are not on screen, and clicking one
  // of those just times out with "element is not visible" instead of selecting a scene.
  const scenes = page.locator('[wire\\:click^="selectScene"]:visible');
  await expect(scenes.first()).toBeVisible({ timeout: 30_000 });
  const count = await scenes.count();
  for (let i = 0; i < count; i++) {
    await scenes.nth(i).click();
    try {
      await expect(page.getByText('Route overview')).toBeVisible({ timeout: 3_000 });
      return;
    } catch { /* not the overview — try the next scene */ }
  }
  throw new Error('No voyage overview scene in the rail');
}

test('voyage overview: the itinerary lists every stop in sailing order', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await openOverviewScene(page);

  await page.getByRole('tab', { name: 'Route' }).click();
  const stops = page.locator('[data-stop-leg], [data-bg]:has-text("Start")');
  await expect(stops.first()).toBeVisible();

  // Numbered from 1, and every leg of the route has a row.
  const legRows = page.locator('[data-stop-leg]');
  const legCount = await legRows.count();
  expect(legCount).toBeGreaterThan(1);
  await expect(page.locator('[data-bg]').filter({ hasText: 'Start' })).toHaveCount(1);

  // The stops carry their leg index, in sailing order, as the reorder payload relies on.
  const legs = await legRows.evaluateAll((els) => els.map((e) => Number((e as HTMLElement).dataset.stopLeg)));
  expect(legs).toEqual([...legs].sort((a, b) => a - b));

  expect(errors).toEqual([]);
});

test('voyage overview: dragging a stop re-sails the route in that order', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const failedUpdates: string[] = [];
  page.on('response', (r) => {
    if (r.url().includes('/livewire/update') && r.status() !== 200) failedUpdates.push(`${r.status()}`);
  });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await openOverviewScene(page);
  await page.getByRole('tab', { name: 'Route' }).click();

  const names = () => page.locator('[data-stop-leg] span.truncate').allTextContents();
  const before = await names();
  expect(before.length).toBeGreaterThan(1);

  // Drop the second leg above the first — the itinerary's own order, not the map's.
  const rows = page.locator('[data-stop-leg]');
  await rows.nth(1).hover();
  await page.mouse.down();
  const target = await rows.nth(0).boundingBox();
  await page.mouse.move(target!.x + target!.width / 2, target!.y + 2, { steps: 12 });
  await page.mouse.up();

  await expect
    .poll(async () => (await names())[0], { timeout: 15_000 })
    .toBe(before[1].trim());

  expect(failedUpdates).toEqual([]);
});
