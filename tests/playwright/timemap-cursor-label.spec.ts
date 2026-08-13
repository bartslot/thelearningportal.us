import { test, expect } from '@playwright/test';

/**
 * The territory name follows the pointer, and the resting centroid labels stay out of the way.
 *
 * Driven with page.mouse rather than by calling the handler, because the whole feature is a
 * mousemove on the map: calling setCursorName directly would prove only that setCursorName works.
 */
test('the territory name appears where you point, not at the centroid', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1280, height: 820 });
  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });
  await page.waitForTimeout(10_000);

  const resting = await page.evaluate(() => {
    const map: any = (window as any).__tmMap;
    return map.getLayoutProperty('boundaries-label', 'visibility');
  });
  expect(resting, 'the centroid labels should be off by default').toBe('none');

  // Find a screen point that actually HAS a territory under it, rather than assuming the middle of
  // the view does. It often does not: the opening camera lands wherever the era's polities are not.
  const box = (await page.locator('.maplibregl-canvas').first().boundingBox())!;
  const hit = await page.evaluate(() => {
    const map: any = (window as any).__tmMap;
    const c = map.getCanvas();
    // Stay clear of the top and bottom fifths: the nav and the transport deck sit over the canvas
    // there, so a real pointer lands on THEM and the map never sees a mousemove at all.
    for (let gy = 2; gy <= 6; gy++) {
      for (let gx = 1; gx < 10; gx++) {
        const x = (c.clientWidth * gx) / 10;
        const y = (c.clientHeight * gy) / 8;
        const f = map.queryRenderedFeatures([x, y], { layers: ['boundaries-fill'] });
        if (!f.length) continue;
        const el = document.elementFromPoint(x, y);
        if (el && el.tagName !== 'CANVAS') continue;   // something is covering the map here
        return { x, y, name: f[0].properties?.Name };
      }
    }
    return null;
  });
  console.log('territory found at:', JSON.stringify(hit));
  expect(hit, 'no territory rendered anywhere on screen — nothing to hover').toBeTruthy();

  const readLabel = () => page.evaluate(() => {
    const map: any = (window as any).__tmMap;
    const raw: any = (map.getSource('boundaries-cursor-name') as any)?._data;
    const fc = raw?.geojson ?? raw;          // MapLibre wraps setData payloads under .geojson
    const f = fc?.features?.[0];
    return f ? { name: f.properties?.name, at: f.geometry?.coordinates } : null;
  });

  await page.mouse.move(box.x + hit!.x, box.y + hit!.y, { steps: 12 });
  await page.waitForTimeout(1200);
  const found = await readLabel();

  console.log('cursor label:', JSON.stringify(found));
  expect(found?.name, 'no name ever appeared under the pointer').toBeTruthy();

  // It has to MOVE with the pointer, not merely exist.
  const a = (await readLabel())?.at;
  await page.mouse.move(box.x + hit!.x + 14, box.y + hit!.y + 10, { steps: 6 });
  await page.waitForTimeout(900);
  const b = (await readLabel())?.at;
  if (a && b) expect(a, 'the label did not follow the pointer').not.toEqual(b);

  // And it clears when the pointer leaves the map entirely.
  await page.mouse.move(5, 5, { steps: 8 });
  await page.waitForTimeout(900);
  expect(await readLabel(), 'the name stayed behind after the pointer left').toBeNull();
});
