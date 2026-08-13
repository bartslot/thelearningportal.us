import { test, expect } from '@playwright/test';

/**
 * "Play advance" must actually put pixels on the map.
 *
 * The metaball front is built and painted into a 2D canvas, then handed to MapLibre as a source. If
 * MapLibre refuses the source, every piece still runs — the field builds, the frames paint, the
 * timeline advances — and nothing is ever drawn. So this asserts the LAYER EXISTS and the canvas
 * has ink in it, not that the animation code ran.
 */
test('Play advance draws the front onto the map', async ({ page }) => {
  test.setTimeout(180_000);
  const errs: string[] = [];
  page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message.slice(0, 200)));
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errs.push(m.type().toUpperCase() + ' ' + m.text().slice(0, 200)); });

  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });
  await page.waitForTimeout(10_000);

  await page.evaluate(() => (window as any).__tune.set('Advance', 'play', true));
  await page.waitForTimeout(9_000);

  const state = await page.evaluate(() => {
    const map: any = (window as any).__tmMap;
    const adv: any = (window as any).__tmAdvance;
    const ids = map.getStyle().layers.map((l: any) => l.id);
    return {
      createdAtAll: !!adv,
      layer: ids.filter((i: string) => /advance/i.test(i)),
      sources: Object.keys(map.getStyle().sources).filter((s) => /advance/i.test(s)),
      allSourceTypes: Object.entries(map.getStyle().sources).map(([k, v]: any) => `${k}:${v.type}`).slice(0, 20),
    };
  });

  console.log('STATE:', JSON.stringify(state, null, 2));
  console.log('ERRORS:', [...new Set(errs)].slice(0, 8).join('\n  ') || '(none)');

  expect(state.createdAtAll, 'createAdvance never ran — the target country was not on screen').toBe(true);
  expect(state.sources.length, 'the advance source never made it into the style').toBeGreaterThan(0);
  expect(state.layer.length, 'the advance layer never made it into the style').toBeGreaterThan(0);
});
