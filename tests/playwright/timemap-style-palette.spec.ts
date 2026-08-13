import { test, expect } from '@playwright/test';

/**
 * A teacher can still change the map style.
 *
 * This exists because the palette vanished in a merge and nothing noticed. polishing-1 removed the
 * UI in a fewer-choices pass; that deletion auto-merged cleanly, while the `x-data` holding
 * `paletteOpen`, `style` and `items` sat inside a conflict and kept the other side. The result was
 * state with no interface: three styles defined, a variable to hold the choice, and no control on
 * screen that could make one. Every unit test still passed, because every piece still existed.
 *
 * So this drives the real chain with a real pointer — cog, palette, pick — and asserts the MAP
 * changed, not that the markup is present. Markup being present is exactly what was true while it
 * was broken.
 */
test('the style palette opens and switches the map', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message.slice(0, 200)));

  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__portal?.ready === true, null, { timeout: 60_000 });
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });
  await page.waitForTimeout(8_000);

  // The palette hides behind the cog, so the cog has to work first.
  await page.getByRole('button', { name: 'Settings' }).click();
  const palette = page.getByRole('button', { name: 'Map style' });
  await expect(palette, 'no Map style button — the palette is gone again').toBeVisible();

  await palette.click();
  await expect(page.getByText('Soft Atlas')).toBeVisible();

  // Earth is the one that carries the globe layers, and the one Bart could not reach.
  await page.getByText('Earth', { exact: true }).click();
  await page.waitForTimeout(6_000);

  // THE MAP CHANGED, not merely the variable. tm-ocean only exists on a style that draws the globe.
  const hasGlobe = await page.evaluate(() => !!(window as any).__tmMap.getLayer('tm-ocean'));
  expect(hasGlobe, 'picking Earth did not bring the globe layers in').toBe(true);

  const stored = await page.evaluate(() => localStorage.getItem('tm-style'));
  expect(stored, 'the choice was not persisted').toBe('earth');

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
});
