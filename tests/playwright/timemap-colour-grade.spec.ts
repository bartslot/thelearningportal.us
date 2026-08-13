import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';

/** Mean per-pixel chroma (max channel - min channel). 0 means the frame is grey. */
const meanChroma = (buf: Buffer) => {
  const png = PNG.sync.read(buf);
  let total = 0;
  let counted = 0;
  for (let i = 0; i < png.data.length; i += 4) {
    const r = png.data[i]; const g = png.data[i + 1]; const b = png.data[i + 2];
    if (png.data[i + 3] < 200) continue;              // skip the transparent surround
    if (r + g + b < 30) continue;                     // and near-black space, which is grey anyway
    total += Math.max(r, g, b) - Math.min(r, g, b);
    counted++;
  }
  return counted ? total / counted : 0;
};

/**
 * Saturation 0 has to actually produce a grey picture.
 *
 * Asserting the uniform was set would pass while the pass never ran — which is the failure mode
 * this whole file exists for, since the grade lives in a layer that early-returns when the glare
 * is off. So this reads the PIXELS: colourful before, grey after, same camera, same frame.
 */
test('saturation 0 drains the colour out of the map', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1100, height: 760 });
  await page.addInitScript(() => { try { localStorage.setItem('tm-style', 'earth'); } catch (e) {} });
  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });
  await page.waitForTimeout(12_000);

  const canvas = page.locator('.maplibregl-canvas').first();
  const colour = meanChroma(await canvas.screenshot());

  await page.evaluate(() => (window as any).__tune.set('Colour grade', 'saturation', 0));
  await page.waitForTimeout(3_000);
  const grey = meanChroma(await canvas.screenshot());

  console.log(`chroma: colour=${colour.toFixed(2)} grey=${grey.toFixed(2)}`);

  expect(colour, 'the map had no colour to begin with — nothing was proved').toBeGreaterThan(6);
  expect(grey, 'saturation 0 left the frame in colour: the grade pass never ran').toBeLessThan(colour * 0.25);

  // And back, so the control is a dial rather than a one-way switch.
  await page.evaluate(() => (window as any).__tune.set('Colour grade', 'saturation', 1));
  await page.waitForTimeout(3_000);
  const restored = meanChroma(await canvas.screenshot());
  expect(restored, 'the colour never came back').toBeGreaterThan(colour * 0.6);
});
