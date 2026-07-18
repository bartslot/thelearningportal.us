import { test } from '@playwright/test';

test('linetest vite-dep maplibre', async ({ page }) => {
  await page.goto('/linetest2.html');
  await page.waitForFunction(() => (window as any).__testMap && (window as any).__testMap.loaded(), { timeout: 20_000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: 'tests/playwright/results/linetest-vitedep.png' });
});
