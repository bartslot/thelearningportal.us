import { test, expect } from '@playwright/test';

// Map block playing in the lesson player. Uses the seeded demo lesson FRREV9 whose opening
// block is an interactive map (set up in the dev seed). Skips gracefully if absent.
test('map block plays as an interactive slide in the player', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

  await page.goto('http://localhost:8000/lesson/FRREV9', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);

  const start = page.locator('button:has-text("Start lesson")');
  test.skip(!(await start.isVisible().catch(() => false)), 'demo lesson has no playable opening');

  await start.click();

  // The map stage should become visible (mount can lag the INTRO startup).
  let appeared = false;
  for (let i = 0; i < 20; i++) {
    const d = await page.evaluate(() => getComputedStyle(document.getElementById('lesson-map-stage')!).display);
    if (d === 'block') { appeared = true; break; }
    await page.waitForTimeout(300);
  }
  expect(appeared).toBe(true);

  // Full-bleed render with a MapLibre canvas (regression: collapsed to height 0).
  const stage = await page.evaluate(() => {
    const s = document.getElementById('lesson-map-stage')!;
    return { h: s.getBoundingClientRect().height, canvas: s.querySelectorAll('canvas').length };
  });
  expect(stage.h).toBeGreaterThan(100);
  expect(stage.canvas).toBeGreaterThan(0);

  // The map slide itself must render cleanly (downstream scenes are out of scope here).
  expect(errors).toEqual([]);

  // Interactive mode → Continue advances and hides the map.
  const cont = page.locator('button:has-text("Continue")');
  await expect(cont).toBeVisible();
  await cont.click();
  await page.waitForTimeout(1500);
  const hidden = await page.evaluate(() => getComputedStyle(document.getElementById('lesson-map-stage')!).display === 'none');
  expect(hidden).toBe(true);
});
