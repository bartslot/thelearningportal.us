import { test, expect } from '@playwright/test';

/**
 * Hover-to-bend: the teacher steers the route around land by hovering the drawn route line — a rounded
 * "bend" ghost handle appears under the cursor. This drives real mouse moves over the map canvas near
 * the leg's destination (which sits ON the line) and asserts the ghost reveals itself.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const SCENE = 39; // a voyage leg with an intermediate waypoint

test('hovering the route line reveals the bend ghost handle', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !!(window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator'));
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), SCENE);

  // Wait for the always-on destination X — it sits on the route line, so it anchors our search.
  await page.waitForFunction(() =>
    document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="to"]').length >= 1,
    { timeout: 30_000 });
  // Let the camera settle so projected coordinates are stable.
  await page.waitForTimeout(1500);

  const dest = await page.evaluate(() => {
    const el = document.querySelector('#lesson-map-preview [data-voyage-endpoint="to"]') as HTMLElement;
    const r = el.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
  });

  const ghostVisible = () => page.evaluate(() =>
    [...document.querySelectorAll('#lesson-map-preview [data-voyage-endpoint="bend"]')]
      .some((e) => (e as HTMLElement).style.display !== 'none'));

  // Sweep a small grid around the destination; the line runs through it, so some cursor point lands
  // within the grab radius and the ghost appears. (We approach from ~30px away to avoid the X itself.)
  let revealed = false;
  outer:
  for (let r = 24; r <= 120 && !revealed; r += 12) {
    for (let a = 0; a < 360; a += 20) {
      const x = dest.x + r * Math.cos((a * Math.PI) / 180);
      const y = dest.y + r * Math.sin((a * Math.PI) / 180);
      await page.mouse.move(x, y);
      if (await ghostVisible()) { revealed = true; break outer; }
    }
  }

  expect(revealed, 'the bend ghost appears while hovering the route line').toBe(true);
  expect(errors, errors.join('\n')).toHaveLength(0);
});
