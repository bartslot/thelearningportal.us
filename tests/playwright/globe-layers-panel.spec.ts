import { test, expect } from '@playwright/test';

/**
 * The Globe layers panel, opened the way a teacher opens it.
 *
 * Driven with a real pointer through the View menu rather than by setting `$store.view.layers` —
 * the store key going missing is exactly the bug this suite is being written after, and a test that
 * sets the key itself would have passed straight through it.
 */
const LESSON = process.env.PW_LESSON_ID ?? '3';

const openMapScene = async (page: import('@playwright/test').Page) => {
  await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`);
  const mapScene = page.locator('[data-scene-block]').filter({ hasText: /map/i }).first();
  await mapScene.waitFor({ state: 'visible', timeout: 60_000 });
  await mapScene.click();
  await page.waitForFunction(
    () => (window as any).__lessonMap?.isStyleLoaded?.() === true,
    null,
    { timeout: 120_000 },
  );
  // The globe chunk and its quality plan land after the style does.
  await page.waitForFunction(() => typeof (window as any).__globeAvailability === 'function', null, { timeout: 60_000 });
};

/** Open it through the View menu, with the mouse, as a teacher would. */
const openLayersPanel = async (page: import('@playwright/test').Page) => {
  await page.getByRole('button', { name: /^view$/i }).first().click();
  await page.getByRole('button', { name: /globe layers/i }).first().click();
};

test('a teacher can open the Globe layers panel from the View menu', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message.slice(0, 200)));

  await openMapScene(page);
  await openLayersPanel(page);

  const panel = page.locator('text=Globe layers').last();
  await expect(panel).toBeVisible();

  // Rows the surface DOES carry are offered, with a working toggle.
  await expect(page.getByRole('checkbox', { name: /^ocean$/i })).toBeEnabled();

  // Rows it does not carry are gone entirely — not greyed with a device excuse.
  await expect(page.getByRole('checkbox', { name: /^clouds$/i })).toHaveCount(0);

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);
  await page.screenshot({ path: 'tests/playwright/globe-layers-panel.png' });
});

/**
 * The regression that prompted this file: the panel forgot it was open.
 *
 * `layers` was missing from the view store, so toggle() created it (Alpine makes a new store key
 * reactive) but _save() never persisted it. The panel worked all session and reset on every reload,
 * which is the kind of fault that gets reported as "it keeps closing itself".
 */
test('the panel is still open after a reload', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await openMapScene(page);
  await openLayersPanel(page);
  await expect(page.locator('text=Globe layers').last()).toBeVisible();

  // Persisted to localStorage by _save(), which is the half that was missing.
  const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('wizard.view') || '{}'));
  expect(saved.layers, 'the view store did not persist `layers`').toBe(true);

  await openMapScene(page);
  await expect(page.locator('text=Globe layers').last()).toBeVisible();
});
