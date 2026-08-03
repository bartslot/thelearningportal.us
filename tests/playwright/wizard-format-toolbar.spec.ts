import { test, expect, Page } from '@playwright/test';

/**
 * The toolbar "Format" button must mirror + toggle the docked Format panel: reflect the OPEN state
 * with a background on load, close the panel when pressed while open, and reopen it when pressed
 * again. Regression: the panel's open state never reached the (sibling, wire:ignore) toolbar, so the
 * button showed no active state and always re-opened instead of toggling.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;

async function fmtState(page: Page) {
  return page.evaluate(() => {
    const A = (window as any).Alpine;
    const toolbar = [...document.querySelectorAll('[x-data]')].find((n) => (n as any)._x_dataStack?.some((s: any) => 'fmtOpen' in s));
    const aside = document.querySelector('[x-ref="inspectorPanel"]');
    return {
      fmtOpen: toolbar ? A.$data(toolbar).fmtOpen : null,
      inspectorOpen: aside ? A.$data(aside).inspectorOpen : null,
    };
  });
}

test('Format button reflects and toggles the panel', async ({ page }) => {
  test.setTimeout(60_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1200);

  const fmtBtn = page.getByRole('button', { name: 'Format', exact: true });
  await expect(fmtBtn).toBeVisible();

  // 1) On load the panel is open → toolbar knows it (synced) and shows the active background.
  let s = await fmtState(page);
  expect(s.inspectorOpen, 'panel starts open').toBe(true);
  expect(s.fmtOpen, 'toolbar mirrors the open panel').toBe(true);
  await expect(fmtBtn).toHaveClass(/bg-sky-500\/15/);

  // 2) Press Format while open → it closes.
  await fmtBtn.click();
  await page.waitForTimeout(300);
  s = await fmtState(page);
  expect(s.inspectorOpen, 'clicking Format closes the panel').toBe(false);
  expect(s.fmtOpen, 'toolbar reflects the closed panel').toBe(false);
  await expect(fmtBtn).not.toHaveClass(/bg-sky-500\/15/);

  // 3) Press Format again → it reopens.
  await fmtBtn.click();
  await page.waitForTimeout(300);
  s = await fmtState(page);
  expect(s.inspectorOpen, 'clicking Format reopens the panel').toBe(true);
  expect(s.fmtOpen, 'toolbar reflects the reopened panel').toBe(true);
  await expect(fmtBtn).toHaveClass(/bg-sky-500\/15/);
});
