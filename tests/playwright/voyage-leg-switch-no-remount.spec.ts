import { test, expect, Page } from '@playwright/test';

/**
 * Switching between legs of the SAME voyage must NOT rebuild the map — the tour plays the new leg on
 * the existing instance, preserving zoom / centre / globe-flat projection and the loaded tiles.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';

async function voyageSceneClicks(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    [...document.querySelectorAll('[wire\\:click^="selectScene"]')].map((e) => e.getAttribute('wire:click') || ''));
}

test('switching voyage legs plays live on the same map (no re-mount)', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });

  await page.goto(WIZARD_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window as any).renderVoyageTour && !!(window as any).Livewire);
  await page.evaluate(() => {
    const w = window as any;
    w.__origRVT = w.renderVoyageTour; w.__mounts = 0;
    w.renderVoyageTour = function (...a: any[]) { w.__mounts++; const i = w.__origRVT.apply(this, a); w.__cap = i; return i; };
  });

  // Find the voyage scenes (ids in wire:click). Columbus: 36..39 are the four legs.
  const clicks = await voyageSceneClicks(page);
  const sceneIds = clicks.map((c) => Number(c.match(/selectScene\((\d+)\)/)?.[1])).filter(Boolean);
  const legA = sceneIds[1]; // 2nd scene = first voyage leg
  const legB = sceneIds[2]; // 3rd scene = second voyage leg

  // Land on the first leg (this mount is expected).
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), legA);
  await page.waitForTimeout(2500);
  const mountsAfterFirst = await page.evaluate(() => (window as any).__mounts);
  expect(mountsAfterFirst, 'first voyage leg mounted once').toBeGreaterThanOrEqual(1);
  // Tag the current map object — a rebuild would create a fresh (untagged) one.
  await page.evaluate(() => { (window as any).__cap.map.__legTag = 'kept'; });

  // Switch to the adjacent leg — must NOT re-mount (keeps the loaded map/tiles; each leg still
  // applies its OWN view, so projection may legitimately differ between legs).
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), legB);
  await page.waitForTimeout(2500);

  expect(await page.evaluate(() => (window as any).__mounts), 'leg switch did not rebuild the map').toBe(mountsAfterFirst);
  expect(await page.evaluate(() => (window as any).__cap?.map?.__legTag), 'it is the SAME map object, not a rebuilt one').toBe('kept');

  expect(errors, errors.join('\n')).toHaveLength(0);
});
