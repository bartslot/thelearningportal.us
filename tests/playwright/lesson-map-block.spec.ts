import { test, expect } from '@playwright/test';

// Map block in the lesson composer (Step 3): add from the timeline, live MapLibre preview,
// inspector settings, reorder, delete. Lesson 1 is the seeded demo lesson owned by the
// auto-login teacher with a polity topic (set up in the test DB seed).
test.describe('Lesson map block (composer)', () => {
  const url = 'http://localhost:8000/teacher/lessons/1/wizard?step=3';

  test('add → renders preview → inspector → delete', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('dialog', d => d.accept()); // wire:confirm on delete

    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    const before = await page.locator('[data-scene-id]').count();

    // The "Map" option must be wired into the timeline add-dropdown.
    await expect(page.locator('button[wire\\:click="addScene(\'map\')"]')).toHaveCount(1);

    // Add a map block (the daisyUI focus-dropdown stays hidden headless, click the real control).
    await page.evaluate(() => {
      (document.querySelector('button[wire\\:click="addScene(\'map\')"]') as HTMLButtonElement)?.click();
    });
    await page.waitForTimeout(3500); // Livewire add + select + map mount + tiles

    // One more scene, a Map thumb, and the inspector shows the map block.
    expect(await page.locator('[data-scene-id]').count()).toBe(before + 1);
    await expect(page.locator('[data-scene-id]:has-text("Map")').first()).toBeVisible();
    await expect(page.locator('text=Map block')).toBeVisible();

    // The MapLibre preview fills the viewport (regression: it collapsed to height 0 when the
    // map mounted directly on a relatively-positioned MapLibre container).
    const size = await page.evaluate(() => {
      const h = document.getElementById('lesson-map-preview')!;
      const r = h.getBoundingClientRect();
      return { h: r.height, display: getComputedStyle(h).display, canvas: h.querySelectorAll('canvas').length };
    });
    expect(size.display).toBe('block');
    expect(size.h).toBeGreaterThan(100);
    expect(size.canvas).toBeGreaterThan(0);

    // Delete the added block → back to the original count.
    await page.locator('button:has-text("Delete block")').click();
    await page.waitForTimeout(2500);
    expect(await page.locator('[data-scene-id]').count()).toBe(before);

    // Selecting a non-map scene hides the map preview.
    await page.locator('[data-scene-id]:not(:has-text("Map"))').first().click();
    await page.waitForTimeout(1500);
    const hidden = await page.evaluate(() => getComputedStyle(document.getElementById('lesson-map-preview')!).display === 'none');
    expect(hidden).toBe(true);

    expect(errors).toEqual([]);
  });

  test('blocks reorder (drag bridge)', async ({ page }) => {
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    const ids = await page.evaluate(() => [...document.querySelectorAll('[data-scene-id]')].map(e => Number((e as HTMLElement).dataset.sceneId)));
    test.skip(ids.length < 2, 'need at least 2 blocks to reorder');

    // Move the first block to the end via the SortableJS → reorder bridge.
    const reordered = [...ids.slice(1), ids[0]];
    await page.evaluate((r) => window.dispatchEvent(new CustomEvent('timeline:reordered', { detail: { ids: r } })), reordered);
    await page.waitForTimeout(2500);

    const after = await page.evaluate(() => [...document.querySelectorAll('[data-scene-id]')].map(e => Number((e as HTMLElement).dataset.sceneId)));
    expect(after[after.length - 1]).toBe(ids[0]);
  });
});
