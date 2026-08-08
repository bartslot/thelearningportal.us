import { test, expect } from '@playwright/test';

// Map block in the lesson composer (Step 3): add from the timeline, live MapLibre preview,
// inspector settings, reorder, delete. Lesson 1 is the seeded demo lesson owned by the
// auto-login teacher with a polity topic (set up in the test DB seed).
test.describe('Lesson map block (composer)', () => {
  // step=4 — the Configure editor (the Story step's arrival renumbered the wizard;
  // step=3 is now Generate and has no timeline).
  const url = `${process.env.WIZARD_URL}?step=4`;

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

  test('picking a territory updates in place — no remount, no camera jump', async ({ page }) => {
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    // Fresh map block (auto-selected).
    await page.evaluate(() => (document.querySelector('button[wire\\:click="addScene(\'map\')"]') as HTMLButtonElement)?.click());
    await page.waitForTimeout(4000); // add + select + mount + tiles

    // A year must already be set: the FIRST pick on a yearless block seeds the year and
    // legitimately remounts. With a year present, picks are in-place (this test's subject).
    const yearInput = page.getByPlaceholder('e.g. 1600');
    await yearInput.fill('1700');
    await yearInput.blur();
    await page.waitForTimeout(3500); // year change may remount — fine, it's before we tag

    // Tag the live instance, record the camera, and grab a polity that's actually on screen.
    const setup = await page.evaluate(() => {
      const m = (window as any).__lessonMap;
      if (!m || !m.getLayer('boundaries-line')) return null;
      m.__persist = 'tag-' + Math.random().toString(36).slice(2);
      const qid = m.queryRenderedFeatures({ layers: ['boundaries-line'] })
        .map((f: any) => f.properties?.Wikidata).find(Boolean);
      return { tag: m.__persist, center: m.getCenter(), qid: qid || null };
    });
    test.skip(!setup || !setup.qid, 'no polity rendered to pick');

    // Fire the exact event a map click fires (onPolityClick → mapTerritoryClicked).
    await page.evaluate((qid) => {
      (window as any).Livewire.dispatch('mapTerritoryClicked', { sceneId: null, qid, name: 'Test polity' });
    }, setup!.qid);
    await page.waitForTimeout(3000); // server round-trip + scene:load

    const result = await page.evaluate((before) => {
      const m = (window as any).__lessonMap;
      return {
        remounted: m.__persist !== before.tag,        // a rebuilt instance loses __persist (the blink)
        driftLng: Math.abs(m.getCenter().lng - before.center.lng),
        driftLat: Math.abs(m.getCenter().lat - before.center.lat),
      };
    }, setup!);

    expect(result.remounted).toBe(false); // no tear-down + rebuild
    expect(result.driftLng).toBeLessThan(1); // camera didn't jump east
    expect(result.driftLat).toBeLessThan(1);
  });

  test('a map opens on photographed ground, raised and tilted', async ({ page }) => {
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    await page.evaluate(() => (document.querySelector('button[wire\\:click="addScene(\'map\')"]') as HTMLButtonElement)?.click());
    await page.waitForTimeout(4000); // add + select + mount

    const ground = await page.evaluate(() => {
      const m = (window as any).__lessonMap;
      return {
        satellite: m.getLayer('satellite') ? m.getLayoutProperty('satellite', 'visibility') ?? 'visible' : null,
        // The drawn atlas is not hidden, it is not built at all.
        drawn: ['land', 'lakes', 'graticule', 'coast-shadow'].filter((l) => !!m.getLayer(l)),
        exaggeration: m.getTerrain()?.exaggeration ?? 0,
        pitch: m.getPitch(),
      };
    });

    expect(ground.satellite).toBe('visible');
    expect(ground.drawn).toEqual([]);
    // Height alone is invisible from straight above — the tilt is what makes a mountain read.
    expect(ground.exaggeration).toBeGreaterThan(1);
    expect(ground.pitch).toBeGreaterThan(20);
  });

  test('no map-style option is offered to the teacher', async ({ page }) => {
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    await page.evaluate(() => (document.querySelector('button[wire\\:click="addScene(\'map\')"]') as HTMLButtonElement)?.click());
    await page.waitForTimeout(4000);

    await expect(page.locator('text=Map style')).toHaveCount(0);
    await expect(page.locator('[wire\\:click^="setLessonMapStyle"]')).toHaveCount(0);
  });
});
