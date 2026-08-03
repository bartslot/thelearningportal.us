import { test, expect } from '@playwright/test';

/**
 * The voyage map must be pan/zoomable IN THE EDITOR (to place a leg's destination dot) — but not
 * rotatable, and a real drag on the map must actually move the camera.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;
const SCENE = 37;

test('editor voyage map is pan/zoomable (rotate disabled), drag moves the camera', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !!(window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator'));
  await page.evaluate(() => {
    const w = window as any; w.__o = w.renderVoyageTour;
    w.renderVoyageTour = function (...a: any[]) { const i = w.__o.apply(this, a); w.__cap = i; return i; };
  });
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), SCENE);
  await page.waitForFunction(() => (window as any).__cap?.map?.isStyleLoaded?.(), { timeout: 30_000 }).catch(() => {});
  await page.waitForTimeout(2500);

  const state = await page.evaluate(() => {
    const m = (window as any).__cap.map;
    return {
      drag: m.dragPan.isEnabled(),
      scroll: m.scrollZoom.isEnabled(),
      rotate: m.dragRotate.isEnabled(),
    };
  });
  expect(state.drag, 'drag-pan enabled in editor').toBe(true);
  expect(state.scroll, 'scroll-zoom enabled in editor').toBe(true);
  expect(state.rotate, 'rotate disabled').toBe(false);

  // A real drag on empty map (away from the landfall HUD) moves the centre.
  const before = await page.evaluate(() => { const c = (window as any).__cap.map.getCenter(); return [c.lng, c.lat]; });
  const box = (await page.locator('#lesson-map-preview canvas').first().boundingBox())!;
  const sx = box.x + box.width * 0.28; const sy = box.y + box.height * 0.2;
  await page.mouse.move(sx, sy);
  await page.mouse.down();
  await page.mouse.move(sx - 240, sy + 40, { steps: 12 });
  await page.mouse.up();
  await page.waitForTimeout(600);
  const after = await page.evaluate(() => { const c = (window as any).__cap.map.getCenter(); return [c.lng, c.lat]; });
  expect(Math.abs(after[0] - before[0]) > 1, 'dragging panned the map').toBe(true);
});
