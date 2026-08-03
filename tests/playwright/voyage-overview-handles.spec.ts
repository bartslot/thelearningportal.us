import { test, expect, Page } from '@playwright/test';

/**
 * The itinerary is not a leg, so it carries none of a leg's editing furniture.
 *
 * The regression this locks in: going overview → route scene → overview left the previous leg's
 * amber Destination X sitting on the whole-trip map. Two paths could rebuild it after the overview
 * had cleared it (a pending leg flushing once the tour became ready, and the wizard's 3s poll), so
 * this walks the round trip the teacher actually walks rather than only mounting the overview.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;

const destinationHandles = (page: Page) => page.locator('[data-voyage-endpoint]');

async function selectSceneByLabel(page: Page, label: RegExp): Promise<void> {
  const rail = page.locator('[wire\\:click^="selectScene"]:visible');
  await expect(rail.first()).toBeVisible({ timeout: 30_000 });
  await rail.filter({ hasText: label }).first().click();
}

test('voyage overview: no leg handles, on arrival or on the way back', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});

  // 1) The overview itself: the whole trip has no single landfall to drag.
  await selectSceneByLabel(page, /Overview/i);
  await expect(page.getByText('The whole trip on one screen', { exact: false })).toBeVisible({ timeout: 15_000 });
  await expect(destinationHandles(page)).toHaveCount(0, { timeout: 15_000 });

  // 2) A route scene: its landfall IS draggable, so the X comes back.
  await selectSceneByLabel(page, /Mauritius/i);
  // The tour loads its map + 3D chunk on demand; wait for it rather than for a timeout, or this
  // step reads an empty HUD on a slow machine and blames the fix.
  await page.waitForFunction(() => !!(window as any).__voyageTour, null, { timeout: 30_000 });
  await expect(destinationHandles(page)).not.toHaveCount(0, { timeout: 20_000 });

  // 3) Back to the overview — the X must go again, and stay gone across the component's poll.
  await selectSceneByLabel(page, /Overview/i);
  await expect(destinationHandles(page)).toHaveCount(0, { timeout: 20_000 });
  await page.waitForTimeout(5_000);   // outlives the 3s wire:poll
  await expect(destinationHandles(page)).toHaveCount(0);
});

test('voyage itinerary: the first landfall is stop 1', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});

  await selectSceneByLabel(page, /Overview/i);
  await page.getByRole('tab', { name: 'Route' }).click();

  // The port the voyage leaves from is not a stop of its own — the first place it ARRIVES at is 1.
  const numbers = page.locator('[data-stop-leg] [data-stop-number]');
  await expect(numbers.first()).toHaveText('1', { timeout: 15_000 });
  const all = await numbers.allTextContents();
  expect(all).toEqual(all.map((_, i) => String(i + 1)));
});
