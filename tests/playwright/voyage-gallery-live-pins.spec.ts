import { test, expect } from '@playwright/test';

/**
 * Editing the gallery (reorder/add/remove images) updates the map pins LIVE — no tour rebuild.
 * Uses a reorder (no network) and restores it, asserting the map instance is never rebuilt.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const SCENE = 39; // Hispaniola / La Navidad — several gallery images

const pinSrcs = () =>
  [...document.querySelectorAll('#lesson-map-preview img')]
    .filter((i) => (i as HTMLElement).style.width === '96px')
    .map((i) => (i as HTMLImageElement).src);

test('gallery edits update pins live (no re-mount)', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !!(window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator'));
  await page.evaluate(() => {
    const w = window as any; w.__origRVT = w.renderVoyageTour; w.__mounts = 0;
    w.renderVoyageTour = function (...a: any[]) { w.__mounts++; const i = w.__origRVT.apply(this, a); w.__cap = i; return i; };
  });

  const call = (m: string, ...args: any[]) => page.evaluate(({ m, args }) =>
    (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire[m](...args), { m, args });

  await call('selectScene', SCENE);
  await page.waitForFunction(() =>
    [...document.querySelectorAll('#lesson-map-preview img')].filter((i) => (i as HTMLElement).style.width === '96px').length > 1,
    { timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(2000);

  const before = await page.evaluate(pinSrcs);
  expect(before.length, 'has >1 pin to reorder').toBeGreaterThan(1);
  const mounts = await page.evaluate(() => (window as any).__mounts);
  await page.evaluate(() => { (window as any).__cap.map.__tag = 'kept'; });

  // Reorder the gallery: image 0 → end. Pins must reflect it, on the SAME map.
  await call('moveGalleryImage', 0, before.length - 1);
  await expect.poll(async () => (await page.evaluate(pinSrcs))[0], { timeout: 8000 }).not.toBe(before[0]);

  const afterMounts = await page.evaluate(() => (window as any).__mounts);
  const sameMap = await page.evaluate(() => (window as any).__cap?.map?.__tag);
  expect(afterMounts, 'gallery edit did NOT rebuild the map').toBe(mounts);
  expect(sameMap, 'same map instance kept').toBe('kept');

  const after = await page.evaluate(pinSrcs);
  expect(after.length, 'pin count preserved').toBe(before.length);
  expect([...after].sort()).toEqual([...before].sort()); // same set, reordered

  await call('moveGalleryImage', before.length - 1, 0); // restore original order
  expect(errors, errors.join('\n')).toHaveLength(0);
});
