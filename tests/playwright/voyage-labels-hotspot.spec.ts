import { test, expect, Page } from '@playwright/test';

/**
 * Place-name labels must draw ABOVE the 3D ship and FADE IN on arrival; the info hotspot must be
 * draggable to reposition it. Runs against the Columbus fixture (lesson 3) in the wizard.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;

async function openFirstVoyageScene(page: Page): Promise<void> {
  const rail = page.locator('[wire\\:click^="selectScene"]');
  const count = await rail.count();
  for (let i = 0; i < count; i++) {
    await rail.nth(i).click();
    // Auto-waiting assertion, not isVisible(): selecting a scene is a Livewire round-trip, and
    // a bare isVisible() reads the inspector before it has swapped, so every scene looked wrong.
    try {
      await expect(page.getByRole('tab', { name: 'Route' })).toBeVisible({ timeout: 3_000 });
      return;
    } catch { /* not a voyage scene — try the next one */ }
  }
  throw new Error('No voyage scene found in the rail');
}

test('voyage: labels sit above the ship, fade in on arrival, and the hotspot is draggable', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});

  // Capture the tour instance (exposes .map) + count mounts.
  await page.evaluate(() => {
    const w = window as any;
    w.__origRVT = w.renderVoyageTour;
    w.__voyageMounts = 0;
    w.renderVoyageTour = function (...a: any[]) { w.__voyageMounts++; const i = w.__origRVT.apply(this, a); w.__cap = i; return i; };
  });

  await openFirstVoyageScene(page);
  await page.waitForTimeout(2500);   // let the map + labels finish loading and the arrival fire

  // 1) Layer order: lesson-labels must be ABOVE voyage-ships. Use getLayersOrder() — getStyle()
  //    omits the ship (a non-serializable type:'custom' three.js layer).
  const order = await page.evaluate(() => {
    const m = (window as any).__cap?.map;
    if (!m) return null;
    const ids = typeof m.getLayersOrder === 'function' ? m.getLayersOrder() : (m.style?._order || []);
    return { labels: ids.indexOf('lesson-labels'), ships: ids.indexOf('voyage-ships') };
  });
  expect(order, 'both layers present').not.toBeNull();
  expect(order!.labels, 'lesson-labels layer exists').toBeGreaterThanOrEqual(0);
  expect(order!.ships, 'voyage-ships layer exists').toBeGreaterThanOrEqual(0);
  expect(order!.labels, 'label must render above the ship').toBeGreaterThan(order!.ships);

  // 2) The arrived landfall's label has been revealed (feature-state shown=true → fades in).
  const revealed = await page.evaluate(() => {
    const m = (window as any).__cap?.map;
    const src = m?.getStyle()?.sources?.['lesson-labels'];
    const feats = (src && src.data && src.data.features) || [];
    return feats.some((f: any) => m.getFeatureState({ source: 'lesson-labels', id: f.id }).shown === true);
  });
  expect(revealed, 'at least one landfall label revealed on arrival').toBe(true);

  // 3) The info hotspot is draggable — drag it and confirm it moved + persisted, no re-mount.
  const mountsBefore = await page.evaluate(() => (window as any).__voyageMounts);
  const hotspot = page.locator('#lesson-map-preview button', { hasText: '▸' }).first();
  await expect(hotspot).toBeVisible();
  const box = (await hotspot.boundingBox())!;
  const updates: number[] = [];
  page.on('response', (r) => { if (r.url().includes('/livewire/update')) updates.push(r.status()); });

  try {
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + 180, box.y + 120, { steps: 8 });   // clearly a drag, not a click
    await page.mouse.up();

    await expect.poll(() => updates, 'hotspot move persisted via livewire').toContain(200);
    const movedBox = (await hotspot.boundingBox())!;
    expect(Math.abs(movedBox.x - box.x) + Math.abs(movedBox.y - box.y), 'hotspot visibly moved').toBeGreaterThan(40);
    expect(await page.evaluate(() => (window as any).__voyageMounts), 'dragging must not re-mount the map').toBe(mountsBefore);

    await page.screenshot({ path: 'tests/playwright/results/voyage-labels-hotspot.png' });
    expect(errors, 'page/console errors:\n' + errors.join('\n')).toHaveLength(0);
  } finally {
    // Restore the hotspot to the current landfall (on-screen) so the persisted config.hotspot never
    // drifts off the map across runs — that stale position made the drag start off-map and fail.
    await page.evaluate(() => {
      const w = window as any;
      const c = w.__cap?.map?.getCenter?.();
      const comp = w.Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator');
      const sid = comp?.$wire?.get?.('selectedSceneId');
      if (c && sid) w.Livewire.dispatch('voyageHotspotMoved', { sceneId: Number(sid), lng: c.lng, lat: c.lat });
    }).catch(() => {});
    await page.waitForTimeout(800);
  }
});
