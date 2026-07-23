import { test, expect, Page } from '@playwright/test';

/**
 * "Visible detail" style controls (place-label / city / border colour + size/width/opacity) must
 * apply to the live map without re-mounting the tour.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';

async function openFirstVoyageScene(page: Page): Promise<void> {
  const rail = page.locator('[wire\\:click^="selectScene"]');
  const count = await rail.count();
  for (let i = 0; i < count; i++) {
    await rail.nth(i).click();
    if (await page.getByRole('tab', { name: 'Route' }).isVisible().catch(() => false)) return;
  }
  throw new Error('No voyage scene found');
}

test('voyage: label/border style controls repaint the live map, no re-mount', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.evaluate(() => {
    const w = window as any;
    w.__origRVT = w.renderVoyageTour; w.__mounts = 0;
    w.renderVoyageTour = function (...a: any[]) { w.__mounts++; const i = w.__origRVT.apply(this, a); w.__cap = i; return i; };
  });
  await openFirstVoyageScene(page);
  await page.waitForTimeout(2000);
  const mounts = await page.evaluate(() => (window as any).__mounts);

  // Drive the style via the component (equivalent to the inspector's wire:change).
  await page.evaluate(() => {
    const c = (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    c.$wire.setVoyageMap('border_color', '#ff0000');
    c.$wire.setVoyageMap('border_width', 3);
    c.$wire.setVoyageMap('label_color', '#00aa00');
  });
  // Poll the paint props — under parallel load the live re-fire + setDetailStyle can lag a fixed wait.
  await expect.poll(async () => page.evaluate(() => {
    const m = (window as any).__cap?.map;
    return m && [m.getPaintProperty('boundaries-line', 'line-color'), m.getPaintProperty('boundaries-line', 'line-width'), m.getPaintProperty('lesson-labels', 'text-color')].join('|');
  }), { timeout: 8000 }).toBe('#ff0000|3|#00aa00');

  expect(await page.evaluate(() => (window as any).__mounts), 'style change must not re-mount').toBe(mounts);

  expect(errors, errors.join('\n')).toHaveLength(0);
});
