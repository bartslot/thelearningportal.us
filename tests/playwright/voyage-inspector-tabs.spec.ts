import { test, expect, Page } from '@playwright/test';

/**
 * Voyage scene inspector — the tabbed redesign (Leg / Map / Route).
 * Asserts: tabs render, switching shows the right panel, the active tab SURVIVES the component's
 * 3s wire:poll, and a Livewire action inside a tab round-trips 200 with no console/page errors.
 * Runs against the Columbus fixture (lesson 3) in the local wizard (AutoLoginDev signs us in).
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';

async function openFirstVoyageScene(page: Page): Promise<void> {
  // Select scenes down the rail until the voyage inspector (its tablist) appears.
  const selectors = page.locator('[wire\\:click^="selectScene"]');
  const count = await selectors.count();
  for (let i = 0; i < count; i++) {
    await selectors.nth(i).click();
    if (await page.getByRole('tab', { name: 'Route' }).isVisible().catch(() => false)) return;
  }
  throw new Error('No voyage scene found in the rail');
}

test('voyage inspector: tabs switch, survive the poll, and actions round-trip', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  const failedUpdates: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });
  page.on('response', (r) => {
    if (r.url().includes('/livewire/update') && r.status() !== 200) {
      failedUpdates.push(`${r.status()} ${r.url()}`);
    }
  });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await openFirstVoyageScene(page);

  // 1) All three tabs render.
  await expect(page.getByRole('tab', { name: 'Leg' })).toBeVisible();
  await expect(page.getByRole('tab', { name: 'Map' })).toBeVisible();
  await expect(page.getByRole('tab', { name: 'Route' })).toBeVisible();

  // 2) Default tab is Leg → its "Dates" group is visible, Route's "Route line" is not.
  await expect(page.getByText('Gallery / description')).toBeVisible();
  await expect(page.getByText('Route line')).toBeHidden();

  // 3) Switch to Map → "Visible detail" shows, the Leg panel hides.
  await page.getByRole('tab', { name: 'Map' }).click();
  await expect(page.getByText('Visible detail')).toBeVisible();
  await expect(page.getByText('Gallery / description')).toBeHidden();

  // 4) A Livewire action inside the Map tab round-trips (toggle "City names").
  const cityToggle = page.locator('label:has-text("City names") input[type="checkbox"]');
  const updates: number[] = [];
  page.on('response', (r) => { if (r.url().includes('/livewire/update')) updates.push(r.status()); });
  await cityToggle.click();
  await expect.poll(() => updates).toContain(200);

  // 5) Switch to Route → the Route line section shows.
  await page.getByRole('tab', { name: 'Route' }).click();
  await expect(page.getByText('Route line')).toBeVisible();
  await expect(page.getByText('Fleet')).toBeVisible();

  // 6) The active tab SURVIVES the 3s wire:poll (this was the design risk).
  await page.waitForTimeout(4_000);
  await expect(page.getByText('Route line')).toBeVisible();          // still on Route after a poll
  await expect(page.getByText('Gallery / description')).toBeHidden();  // did not snap back to Leg

  await page.screenshot({ path: 'tests/playwright/results/voyage-inspector-route-tab.png' });

  // 7) No swallowed failures.
  expect(failedUpdates, 'failed livewire/update calls:\n' + failedUpdates.join('\n')).toHaveLength(0);
  expect(errors, 'page/console errors:\n' + errors.join('\n')).toHaveLength(0);
});
