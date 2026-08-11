import { test, expect, Page } from '@playwright/test';

/**
 * Extensive editor interaction suite — the "does clicking/dragging actually do the thing"
 * tests that demos keep tripping over. Runs against the local dev app (auto-login as the
 * dev teacher). Lesson 2 (Eighty Years' War) is the fully-generated fixture lesson.
 *
 * Every test fails on ANY console error — silent Livewire/Alpine breakage is the target.
 */

const LESSON = process.env.PW_LESSON_ID!;

function watchConsole(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });
  page.on('pageerror', (err) => errors.push(String(err)));
  return errors;
}

/** Livewire round trips finish fast locally; a generous settle keeps polls from racing us. */
async function settle(page: Page, ms = 1200) {
  await page.waitForTimeout(ms);
}

// openEditor() alone allows 90s for the canvas bridge, which the config's 60s default cannot
// contain — several of these were dying on the clock rather than on an assertion.
test.beforeEach(({}, testInfo) => testInfo.setTimeout(180_000));

async function openEditor(page: Page) {
  await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`);
  // The canvas bridge mounts AFTER three.js boots — under Playwright's SwiftShader
  // (software GL) that takes tens of seconds, not the ~2s a real GPU needs.
  await page.waitForFunction(() => (window as any).__lessonTextLayer !== undefined, null, { timeout: 90_000 });
  await settle(page, 2500);
}

/** The year field is the narration inspector's, and nothing else in the editor renders it. */
const yearField = (page: Page) => page.locator('input[wire\\:model\\.blur="selectedScene.year"]');

/**
 * Land on a narrated scene, counting from the top of the rail.
 *
 * The year field, the Scene details disclosure and the painting picker's match targets all belong to
 * the narration inspector, and the fixture lesson stopped opening on one: its first five scenes are
 * maps now, added long after this suite was written. Landing on a map scene is why the picker
 * offered 5 tiles instead of the 29 a narrated scene gets, and why the caption toggle was nowhere.
 *
 * So ask the editor which scene it is on rather than assuming scene 1 is a plain narrated one.
 */
async function selectNarrationScene(page: Page, skip = 0): Promise<string> {
  const thumbs = page.locator('[data-scene-id]:visible');
  const ids = await thumbs.evaluateAll((els) => els.map((e) => (e as HTMLElement).dataset.sceneId!));
  let found = 0;
  for (const id of ids) {
    await page.locator(`[data-scene-id="${id}"]`).click();
    // Wait for the RAIL to agree it switched. Checking the year field alone races the morph: the
    // previous scene's field is still in the DOM for a moment, so every thumb after the first
    // narrated one looked narrated and the walk stopped on the wrong scene.
    await expect(page.locator('[data-thumb-selected]')).toHaveAttribute('data-scene-id', id, { timeout: 15_000 });
    await settle(page, 900);
    if (await yearField(page).count() === 0) continue;
    if (found === skip) return id;
    found++;
  }
  throw new Error(`fixture lesson ${LESSON} has fewer than ${skip + 1} narration scenes`);
}

/**
 * Insert a layer from the toolbar's Add menu.
 *
 * The insert tools used to be top-level buttons ("Add text", "Add panel"). They are one Keynote-style
 * Add menu now, and its items carry role="menuitem", so getByRole('button') does not see them.
 */
async function addLayer(page: Page, item: string) {
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await settle(page, 500);
  await page.getByRole('menuitem', { name: item, exact: true }).click();
}

test.describe('wizard editor — text boxes', () => {
  test('add text spawns visible (top-left, NOT the corner) and drag moves + persists it', async ({ page }) => {
    const errors = watchConsole(page);
    await openEditor(page);

    // Clean slate: remove leftover boxes from previous runs via the layer API.
    await page.evaluate(() => {
      const l = (window as any).__lessonTextLayer;
      l._texts.length = 0; l._render();
    });

    // Add via the real toolbar, not the layer API — the point is that the control still works.
    await addLayer(page, 'Text');
    await settle(page);

    const spawn = await page.evaluate(() => {
      const t = (window as any).__lessonTextLayer._texts.at(-1);
      return t ? { x: t.x, y: t.y, id: t.id } : null;
    });
    expect(spawn, 'text box must be created').not.toBeNull();
    // Spawn must be in the visible top-left region — the corner-spawn regression class.
    expect(spawn!.x).toBeLessThan(30);
    expect(spawn!.y).toBeLessThan(30);

    // Box must actually be visible on screen.
    const node = page.locator(`[data-text-id="${spawn!.id}"]`);
    await expect(node).toBeVisible();

    // Drag with TRUSTED mouse events to the canvas centre.
    const host = page.locator('#lesson-text-overlay');
    const hb = (await host.boundingBox())!;
    const nb = (await node.boundingBox())!;
    await page.mouse.move(nb.x + nb.width / 2, nb.y + nb.height / 2);
    await page.mouse.down();
    // pass the drag threshold, then travel in steps like a human drag
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2, { steps: 12 });
    await page.mouse.up();
    await settle(page);

    const moved = await page.evaluate(() => {
      const t = (window as any).__lessonTextLayer._texts.at(-1);
      return { x: t.x, y: t.y };
    });

    // Measure where the box ENDED UP ON SCREEN, not what its anchor says.
    //
    // x/y are the box's own corner, so "is the anchor near 50,50?" has to leave slack for the
    // box's width and height — and that slack is a number nobody can justify. It was 20, the box
    // grew, and a correct drag started reporting 21. Comparing rendered centres instead is
    // independent of the box's size, which lets the tolerance be 4 instead of 20.
    const offCentre = await page.evaluate((id: string) => {
      const host = document.querySelector('#lesson-text-overlay')!.getBoundingClientRect();
      const box = document.querySelector(`[data-text-id="${id}"]`)!.getBoundingClientRect();
      return {
        dx: ((box.left + box.width / 2) - (host.left + host.width / 2)) / host.width * 100,
        dy: ((box.top + box.height / 2) - (host.top + host.height / 2)) / host.height * 100,
      };
    }, spawn!.id);
    expect(Math.abs(offCentre.dx), 'dropped box must land where the pointer released it').toBeLessThan(4);
    expect(Math.abs(offCentre.dy), 'dropped box must land where the pointer released it').toBeLessThan(4);

    // Persistence: the batched save round-trips; reload and the box must still be there.
    await settle(page, 3000);
    await page.reload();
    await page.waitForFunction(() => (window as any).__lessonTextLayer !== undefined, null, { timeout: 20_000 });
    await settle(page, 2500);
    const persisted = await page.evaluate(() => {
      const t = ((window as any).__lessonTextLayer._texts as any[]).at(-1);
      return t ? { x: t.x, y: t.y } : null;
    });
    expect(persisted, 'dragged box must survive a reload').not.toBeNull();
    expect(Math.abs(persisted!.x - moved.x)).toBeLessThan(2);
    expect(Math.abs(persisted!.y - moved.y)).toBeLessThan(2);

    expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([]);
  });

  test('add panel renders a half-screen backing rect', async ({ page }) => {
    const errors = watchConsole(page);
    await openEditor(page);

    // Panel is a row inside the Add menu with its own Left/Right buttons, so it is reached
    // through the menu but is not itself a menuitem.
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await settle(page, 600);
    await page.getByRole('group', { name: 'Add panel' })
      .getByRole('button', { name: 'Left', exact: true }).click();
    await settle(page);

    const rect = await page.evaluate(() =>
      ((window as any).__lessonTextLayer._texts as any[]).find((t) => t.kind === 'rect'));
    expect(rect, 'a rect panel must exist in the layer').toBeTruthy();

    expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([]);
  });
});

test.describe('wizard editor — object list & caption', () => {
  test('object list opens, rows match layers, caption toggle hides the on-canvas caption', async ({ page }) => {
    const errors = watchConsole(page);
    await openEditor(page);
    // The caption toggle lives in the narration inspector; a map scene has no caption to hide.
    await selectNarrationScene(page);

    // Open View menu → Object list.
    await page.getByRole('button', { name: 'View' }).click();
    await settle(page, 500);
    await page.getByRole('button', { name: 'Object list' }).click();
    await settle(page);

    const rows = page.locator('[x-ref="list"] > div');
    await expect(rows.last()).toContainText('Background'); // background always pinned last

    // Caption toggle: the identity block on the canvas must hide, then show again.
    const captionVisible = () =>
      page.evaluate(() => {
        const overlay = document.querySelector('#lesson-overlay');
        const el = overlay && ([...overlay.querySelectorAll('*')] as HTMLElement[])
          .find((n) => /\d{3,4}/.test(n.textContent || '') && n.offsetParent !== null);
        return !!el;
      });
    const before = await captionVisible();
    // The caption toggle moved into the collapsed-by-default "Scene details" disclosure
    // in the inspector — open it first or the toggle is display:none.
    await page.locator('summary:has-text("Scene details")').first().click();
    await settle(page, 300);
    const toggle = page.locator('label:has-text("Caption") input[type="checkbox"]').first();
    await toggle.click();
    // The toggle round-trips through scene:load before the canvas overlay updates — poll,
    // don't snapshot (a fixed 2s window flakes on slow runs).
    await expect
      .poll(async () => captionVisible(), { timeout: 12_000, intervals: [1000] })
      .not.toBe(before);
    await toggle.click(); // restore
    await expect
      .poll(async () => captionVisible(), { timeout: 12_000, intervals: [1000] })
      .toBe(before);

    expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([]);
  });
});

test.describe('wizard editor — painting picker (corpus-first)', () => {
  test('picker opens, loads tiles, search is lesson-ranked, apply swaps the background', async ({ page }) => {
    const errors = watchConsole(page);
    await openEditor(page);
    // Ranking is derived from the SCENE's match targets, so this has to run on a narrated scene:
    // on one of the fixture's leading map scenes the picker offers 5 tiles rather than 29, and the
    // count below fails while the picker itself is working perfectly.
    await selectNarrationScene(page);

    // Background → Paintings tab → Browse.
    await page.getByRole('button', { name: 'Paintings', exact: true }).click();
    await settle(page, 800);
    await page.getByRole('button', { name: /Browse paintings/i }).click();

    // Tiles arrive after the lazy match-target derivation and stream in progressively —
    // wait for the grid to STABILIZE before counting (counting at first paint undercounts).
    // Scope to the OPEN modal: an unscoped button:has(img) also matches the scene-rail
    // thumbnails behind it (click then dies against the modal overlay).
    const tile = page.locator('.modal-open .modal-box button').filter({ has: page.locator('img') });
    await expect(tile.first()).toBeVisible({ timeout: 30_000 });
    await expect
      .poll(async () => tile.count(), { timeout: 20_000, intervals: [1000] })
      .toBeGreaterThan(6);

    // Region chips render (the corpus-first UI).
    await expect(page.getByRole('button', { name: 'Everything' })).toBeVisible();

    // Apply the first tile: background credit must be recorded server-side and the modal closes.
    // DaisyUI "closed" modals keep opacity/layout (pointer-events:none) — visibility assertions
    // never resolve; the reliable signal is the wrapper losing its open state.
    await tile.first().click();
    await expect
      .poll(async () => page.evaluate(() => {
        const wrap = [...document.querySelectorAll('.modal')]
          .find(m => (m.textContent || '').includes('Everything'));
        return wrap
          ? (wrap.classList.contains('modal-open') || getComputedStyle(wrap).pointerEvents !== 'none')
          : false;
      }), { timeout: 30_000, intervals: [1500] })
      .toBe(false);

    expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([]);
  });
});

test.describe('wizard editor — scenes & quiz', () => {
  test('switching scenes updates the inspector (no stale Alpine state)', async ({ page }) => {
    const errors = watchConsole(page);
    await openEditor(page);

    // Two NARRATED scenes: the year field only exists on those, and the fixture's first five
    // scenes are maps, so "the second thumb in the rail" read an inspector that has no year at all.
    const first = await selectNarrationScene(page, 0);
    const y1 = await yearField(page).inputValue();
    const second = await selectNarrationScene(page, 1);
    await settle(page, 1500);
    const y2 = await yearField(page).inputValue();

    // Prove the reads came from two different scenes before believing what they say — a helper
    // that quietly stayed put would otherwise report "the year did not change" as a product bug.
    expect(second, 'the walk must land on a different scene').not.toBe(first);
    expect(y2, 'inspector year must change with the scene').not.toBe(y1);

    expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([]);
  });

  test('quiz scene exists in the rail for a generated lesson', async ({ page }) => {
    await openEditor(page);
    await expect(page.locator('text=QUIZ').first()).toBeVisible({ timeout: 10_000 });
  });
});
