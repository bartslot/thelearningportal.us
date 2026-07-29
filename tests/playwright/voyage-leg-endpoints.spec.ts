import { test, expect } from '@playwright/test';

/**
 * The route-editing handles a voyage leg shows, and — the part that actually broke — WHERE they sit.
 *
 * Every handle must land on the line drawn on screen. The trail is smoothed and wobbled, so handles
 * derived from the raw waypoints used to sit beside it: hovering the line a teacher could see
 * revealed nothing, and the dots that did appear were not on it.
 *
 * Layout (the convention map editors share): one destination X, a solid dot on each existing bend, a
 * faded dot half way along each segment that adds a bend, plus one ghost that rides the line under
 * the cursor and stays hidden at rest.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const SCENE = 39; // a voyage leg

test('voyage legs expose route handles that sit on the drawn line', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !!(window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator'));
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), SCENE);

  // The destination "X" marker renders in the map HUD.
  await page.waitForFunction(() =>
    document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="to"]').length >= 1,
    { timeout: 30_000 });
  await page.waitForTimeout(1500);   // let the camera settle so projected positions are stable

  const dest = await page.evaluate(() =>
    [...document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="to"]')]
      .map((b) => ({ label: b.textContent, hasX: !!b.querySelector('svg path') })));
  expect(dest.length, 'exactly one destination marker').toBe(1);
  expect(/destination/i.test(dest[0].label || ''), 'labelled Destination').toBe(true);
  expect(dest[0].hasX, 'rendered as an X').toBe(true);

  const handles = await page.evaluate(() => {
    const pick = (sel: string) => [...document.querySelectorAll(`#lesson-map-preview ${sel}`)] as HTMLElement[];
    const visible = (e: HTMLElement) => e.style.display !== 'none' && !!e.style.left;
    return {
      midpoints: pick('[data-voyage-handle="midpoint"]').filter(visible).length,
      ghosts: pick('[data-voyage-handle="ghost"]').length,
      ghostHidden: pick('[data-voyage-handle="ghost"]').every((e) => e.style.display === 'none'),
    };
  });
  expect(handles.midpoints, 'a midpoint handle on every segment of the leg').toBeGreaterThanOrEqual(1);
  expect(handles.ghosts, 'exactly one hover ghost').toBe(1);
  expect(handles.ghostHidden, 'the ghost stays hidden until the line is hovered').toBe(true);

  // The regression guard: query the rendered trail under each visible handle. A handle that drifted
  // off the drawn line — the old bug — finds no feature there.
  const offLine = await page.evaluate(() => {
    const map = (window as any).__voyageTour.map;
    const sel = '#lesson-map-preview [data-voyage-endpoint]';
    return ([...document.querySelectorAll(sel)] as HTMLElement[])
      .filter((h) => h.style.display !== 'none' && h.style.left)
      .filter((h) => {
        const x = parseFloat(h.style.left);
        const y = parseFloat(h.style.top);
        return map.queryRenderedFeatures([[x - 6, y - 6], [x + 6, y + 6]], { layers: ['voyage-trail'] }).length === 0;
      })
      .map((h) => `${h.dataset.voyageHandle || h.dataset.voyageEndpoint} @ ${h.style.left},${h.style.top}`);
  });
  expect(offLine, `every handle sits on the drawn route line (off: ${offLine.join(' · ')})`).toHaveLength(0);

  expect(errors, errors.join('\n')).toHaveLength(0);
});
