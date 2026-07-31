import { test, expect } from '@playwright/test';

/**
 * A landfall gallery freezes the voyage scene's auto-advance while it is open, via the GLOBAL
 * window.__voyageGalleryOpen. If a tour was torn down with its modal still up, nothing cleared the
 * flag and every later voyage scene sat on a frozen countdown — the lesson looked stuck on "The
 * desert that has a voice" with no modal visible to explain it.
 */
test('the gallery-open flag never survives a scene change', async ({ page }) => {
  await page.goto('/lesson/EWQ7RV');

  // Simulate the leak: a tour torn down while its modal was open.
  await page.evaluate(() => { (window as any).__voyageGalleryOpen = true; });
  expect(await page.evaluate(() => (window as any).__voyageGalleryOpen)).toBe(true);

  await page.getByRole('button', { name: /start lesson/i }).click();
  await page.waitForTimeout(3500);

  // Starting a scene must assert the flag down, or the auto-advance can never tick again.
  expect(
    await page.evaluate(() => (window as any).__voyageGalleryOpen),
    'a new scene must clear the stale gallery flag',
  ).toBeFalsy();
});

test('closing a landfall gallery leaves the countdown able to run', async ({ page }) => {
  await page.goto('/lesson/EWQ7RV');
  await page.getByRole('button', { name: /start lesson/i }).click();
  await page.waitForTimeout(3000);

  // Whatever the student does, the flag must be false whenever no overlay is on screen —
  // that pairing is the actual invariant the freeze depends on.
  const overlays = await page.locator('div[style*="z-index:40"], div[style*="z-index: 40"]').count();
  const flag = await page.evaluate(() => !!(window as any).__voyageGalleryOpen);
  expect(flag, `no modal visible (${overlays}) so the flag must be down`).toBe(overlays > 0);
});
