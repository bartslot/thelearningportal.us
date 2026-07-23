import { test, expect, Page } from '@playwright/test';

/**
 * Map-presentation settings must apply LIVE — no full re-mount of the voyage tour (which glitches the
 * whole world). We wrap window.renderVoyageTour to count mounts, then toggle Space intro, restyle the
 * route line, and switch projection; the mount count must not increase.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';

async function openFirstVoyageScene(page: Page): Promise<void> {
  const rail = page.locator('[wire\\:click^="selectScene"]');
  const count = await rail.count();
  for (let i = 0; i < count; i++) {
    await rail.nth(i).click();
    if (await page.getByRole('tab', { name: 'Route' }).isVisible().catch(() => false)) return;
  }
  throw new Error('No voyage scene found in the rail');
}

async function mountCount(page: Page): Promise<number> {
  return page.evaluate(() => (window as any).__voyageMounts || 0);
}

test('voyage: map settings apply live without re-mounting the tour', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});

  // Wrap renderVoyageTour BEFORE selecting a scene so every mount is counted.
  await page.evaluate(() => {
    const w = window as any;
    if (!w.__origRVT) {
      w.__origRVT = w.renderVoyageTour;
      w.__voyageMounts = 0;
      w.renderVoyageTour = function (...a: any[]) { w.__voyageMounts++; return w.__origRVT.apply(this, a); };
    }
  });

  await openFirstVoyageScene(page);
  await page.waitForTimeout(1500);           // let the tour finish its first (expected) mount
  const baseline = await mountCount(page);
  expect(baseline, 'the scene should have mounted once').toBeGreaterThanOrEqual(1);

  // 1) Space intro toggle (Map tab) — must NOT re-mount.
  await page.getByRole('tab', { name: 'Map' }).click();
  await page.locator('label:has-text("Space intro") input[type="checkbox"]').click();
  await page.waitForTimeout(1200);
  expect(await mountCount(page), 'Space intro must not re-mount the map').toBe(baseline);

  // 2) Route line restyle (Route tab) — colour + thickness — must NOT re-mount.
  await page.getByRole('tab', { name: 'Route' }).click();
  await page.locator('input[type="range"]').first().evaluate((el: HTMLInputElement) => {
    el.value = '0.5';
    el.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(1200);
  expect(await mountCount(page), 'route-line restyle must not re-mount the map').toBe(baseline);

  // 3) Projection switch Flat → Globe (Map tab) — must NOT re-mount.
  await page.getByRole('tab', { name: 'Map' }).click();
  await page.getByRole('button', { name: 'Globe', exact: true }).click();
  await page.waitForTimeout(1500);
  expect(await mountCount(page), 'projection switch must not re-mount the map').toBe(baseline);

  expect(errors, 'page/console errors:\n' + errors.join('\n')).toHaveLength(0);
});
