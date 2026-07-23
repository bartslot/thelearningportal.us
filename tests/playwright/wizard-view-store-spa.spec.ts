import { test, expect } from '@playwright/test';

/**
 * Regression: the wizard's Alpine `view` store (panel visibility) must exist even when the wizard is
 * reached via a wire:navigate SPA visit from another page — where `alpine:init` has already fired and
 * won't fire again. Without registering on `livewire:navigated`, $store.view was undefined and the
 * panel x-effects threw ("Cannot read properties of undefined (reading 'objects'/'scenes'/'script')").
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';

test('wizard view store survives an SPA navigation into the wizard', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const storeErrors: string[] = [];
  page.on('console', (m) => {
    const t = m.text();
    if (m.type() === 'error' && /store\.view|reading '(objects|scenes|script|rulers)'/.test(t)) {
      storeErrors.push(t.slice(0, 160));
    }
  });

  // First load a NON-wizard page so alpine:init fires here (no view-store script on this page).
  await page.goto('/teacher');
  await page.waitForLoadState('networkidle').catch(() => {});
  expect(await page.evaluate(() => !!(window as any).Alpine), 'Alpine booted on the dashboard').toBe(true);

  // Now SPA-navigate INTO the wizard (as an "Edit" link would). alpine:init will NOT fire again.
  await page.evaluate((url) => (window as any).Livewire.navigate(url), WIZARD_URL);
  await page.waitForFunction(() => location.pathname.includes('/wizard'), { timeout: 15_000 });
  await page.waitForTimeout(1500);

  // The store must be registered (by the livewire:navigated hook) with its expected shape.
  const store = await page.evaluate(() => {
    const s = (window as any).Alpine?.store('view');
    return s ? { hasScenes: 'scenes' in s, hasObjects: 'objects' in s, hasScript: 'script' in s } : null;
  });
  expect(store, '$store.view must be defined after SPA nav').not.toBeNull();
  expect(store!.hasScenes && store!.hasObjects && store!.hasScript, 'store has its panel keys').toBe(true);

  // The View toolbar must be able to toggle a panel (proves the store is live, not just present).
  const toggled = await page.evaluate(() => {
    const s = (window as any).Alpine.store('view');
    const before = s.objects;
    s.toggle('objects');
    return { before, after: s.objects };
  });
  expect(toggled.after, 'toggling a panel flips its state').toBe(!toggled.before);

  expect(storeErrors, 'no $store.view errors:\n' + storeErrors.join('\n')).toHaveLength(0);
});
