import { test, expect } from '@playwright/test';

/**
 * Editing a leg's destination (a voyage_def waypoint) must reshape the route IN PLACE — the ship /
 * landfall / handles follow the edited geometry on the SAME map, with NO re-mount and NO camera jump.
 * (Regression guard for "the map refreshes every time I edit the route.") Mutates the fixture's
 * waypoint then restores it.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const SCENE = 37; // San Salvador leg

test('editing the destination reshapes the route in place — no re-mount, no camera jump', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !!(window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator'));
  // Count every renderVoyageTour call — a re-mount would bump it.
  await page.evaluate(() => {
    const w = window as any; w.__o = w.renderVoyageTour; w.__mounts = 0;
    w.renderVoyageTour = function (...a: any[]) { w.__mounts++; const i = w.__o.apply(this, a); w.__cap = i; return i; };
  });

  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), SCENE);
  await page.waitForFunction(() => (window as any).__cap?.map?.isStyleLoaded?.(), { timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(3000);
  await page.waitForFunction(() => document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="to"]').length >= 1, { timeout: 30_000 });

  const snapshot = () => page.evaluate(() => {
    const w = window as any;
    const el = document.querySelector('#lesson-map-preview [data-voyage-endpoint="to"]') as HTMLElement;
    const r = el.getBoundingClientRect();
    return { mounts: w.__mounts, lng: w.__cap.map.getCenter().lng, lat: w.__cap.map.getCenter().lat, destX: r.left + r.width / 2, destY: r.top + r.height / 2 };
  });

  // Tag the live map object so we can prove it is never swapped for a rebuilt one.
  await page.evaluate(() => { (window as any).__cap.map.__tag = 'kept'; });
  const before = await snapshot();

  try {
    // Move the destination ~8° west; the route must reshape without a re-mount or a camera jump.
    await page.evaluate((sid) => (window as any).Livewire.dispatch('voyageLegEndpointMoved', { sceneId: sid, which: 'to', lng: -66.5, lat: 24.1 }), SCENE);
    await page.waitForTimeout(2800);
    const after = await snapshot();

    expect(after.mounts, 'the tour did NOT re-mount').toBe(before.mounts);
    expect(await page.evaluate(() => (window as any).__cap?.map?.__tag), 'same map object, not rebuilt').toBe('kept');
    expect(Math.abs(after.lng - before.lng), 'the camera did NOT jump').toBeLessThan(2);
    const moved = Math.abs(after.destX - before.destX) + Math.abs(after.destY - before.destY);
    expect(moved, 'the destination handle moved with the edited geometry').toBeGreaterThan(20);
  } finally {
    // Restore the catalog San Salvador waypoint so the fixture is left unchanged.
    await page.evaluate((sid) => (window as any).Livewire.dispatch('voyageLegEndpointMoved', { sceneId: sid, which: 'to', lng: -74.5, lat: 24.1 }), SCENE);
    await page.waitForTimeout(1500);
  }
});
