import { test, expect } from '@playwright/test';

/**
 * In the editor each voyage leg shows ONE always-visible draggable destination X (the FROM is always
 * the previous landfall). Bends (to route around land) are added by hovering the route line — a ghost
 * handle appears under the cursor — so no permanent bend dots clutter the map.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const SCENE = 39; // a voyage leg

test('voyage legs expose a draggable destination handle', async ({ page }) => {
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

  const dest = await page.evaluate(() =>
    [...document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="to"]')]
      .map((b) => ({ label: b.textContent, hasX: !!b.querySelector('svg path') })));
  expect(dest.length, 'exactly one destination marker').toBe(1);
  expect(/destination/i.test(dest[0].label || ''), 'labelled Destination').toBe(true);
  expect(dest[0].hasX, 'rendered as an X').toBe(true);

  // The hover-to-bend ghost handle exists for the leg, but stays HIDDEN until the teacher hovers the
  // route line (so the map isn't cluttered with permanent dots). One ghost per leg, display:none at rest.
  const ghost = await page.evaluate(() => {
    const els = [...document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="bend"]')] as HTMLElement[];
    return { count: els.length, hidden: els.every((e) => e.style.display === 'none') };
  });
  expect(ghost.count, 'exactly one hover-bend ghost handle').toBe(1);
  expect(ghost.hidden, 'ghost is hidden until the line is hovered').toBe(true);

  expect(errors, errors.join('\n')).toHaveLength(0);
});
