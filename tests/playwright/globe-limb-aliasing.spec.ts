import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import { readFileSync } from 'node:fs';

/**
 * How hard the globe's edge is, as a number.
 *
 * Bart circled the lower-left limb: the dark disc against black space is stair-stepped. "It looks
 * jagged" is not something a change can be checked against, so this measures it instead — the width
 * in pixels of the transition from space to disc, averaged over scanlines that cross the limb.
 *
 * A hard edge steps from space to disc in one pixel and scores ~1. A properly resolved edge spreads
 * over two or three. The number is the point; the threshold below is deliberately loose because the
 * exact figure depends on where the limb falls between pixel centres.
 *
 * Measured on the NIGHT side on purpose. The day limb has the atmosphere's own glow across it,
 * which is a real gradient and would score well while the night edge — the one in the screenshot —
 * stayed hard.
 */
const SHOT = 'tests/playwright/results/globe-limb.png';

/** Below this, a pixel is space rather than planet. Both are dark; the disc is not black. */
const SPACE_MAX = 10;

test('the globe limb is not a hard step', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__portal?.ready === true, null, { timeout: 60_000 });
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });

  // A known camera, so the limb lands in the same place every run. Zoomed out far enough that the
  // whole disc is on screen, and at a longitude that puts the night side to the left.
  await page.evaluate(() => {
    const m = (window as any).__tmMap;
    m.jumpTo({ center: [-30, 10], zoom: 1.4, pitch: 0, bearing: 0 });
  });
  // The globe layers repaint on a settle, and the cloud/imagery tiles arrive after the camera move.
  await page.waitForTimeout(10_000);

  await page.screenshot({ path: SHOT });

  const png = PNG.sync.read(readFileSync(SHOT));
  const at = (x: number, y: number) => png.data[(png.width * y + x) * 4]; // red channel is enough

  // Walk in from the left on each scanline until the first pixel that is not space, then measure
  // how many pixels the climb took. Scanlines are spread over the disc's vertical middle, where the
  // limb is closest to vertical and a horizontal scan crosses it squarely.
  const widths: number[] = [];
  for (let y = Math.round(png.height * 0.35); y < Math.round(png.height * 0.65); y += 7) {
    let x = 0;
    while (x < png.width - 2 && at(x, y) <= SPACE_MAX) x += 1;
    if (x >= png.width - 2) continue;               // this scanline never reached the disc

    const plateau = at(Math.min(x + 12, png.width - 1), y);
    if (plateau <= SPACE_MAX) continue;             // grazed the very top or bottom of the disc

    // Pixels strictly between space and the plateau value are the transition.
    let width = 0;
    for (let i = 0; i < 12 && x + i < png.width; i += 1) {
      const v = at(x + i, y);
      if (v > SPACE_MAX && v < plateau - 1) width += 1;
      if (v >= plateau - 1) break;
    }
    widths.push(width + 1); // +1 for the first non-space pixel itself
  }

  expect(widths.length, 'no scanline crossed the limb — the camera or the disc moved').toBeGreaterThan(5);

  const mean = widths.reduce((a, b) => a + b, 0) / widths.length;
  const hardSteps = widths.filter((w) => w <= 1).length / widths.length;

  console.log(`limb edge width: mean ${mean.toFixed(2)}px over ${widths.length} scanlines, ${(hardSteps * 100).toFixed(0)}% hard steps`);

  // The bar: most crossings must resolve over more than a single pixel.
  expect(hardSteps, `${(hardSteps * 100).toFixed(0)}% of limb crossings are a one-pixel step`).toBeLessThan(0.5);
});
