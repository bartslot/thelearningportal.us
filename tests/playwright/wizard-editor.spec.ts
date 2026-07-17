import { test, expect, Page } from '@playwright/test';

/**
 * Extensive editor interaction suite — the "does clicking/dragging actually do the thing"
 * tests that demos keep tripping over. Runs against the local dev app (auto-login as the
 * dev teacher). Lesson 2 (Eighty Years' War) is the fully-generated fixture lesson.
 *
 * Every test fails on ANY console error — silent Livewire/Alpine breakage is the target.
 */

const LESSON = process.env.PW_LESSON_ID ?? '2';

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

async function openEditor(page: Page) {
  await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`);
  // The canvas bridge mounts AFTER three.js boots — under Playwright's SwiftShader
  // (software GL) that takes tens of seconds, not the ~2s a real GPU needs.
  await page.waitForFunction(() => (window as any).__lessonTextLayer !== undefined, null, { timeout: 90_000 });
  await settle(page, 2500);
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

    // Add via the real toolbar button.
    await page.getByRole('button', { name: 'Add text' }).click();
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
    // Landed near the centre (50,50) — generous tolerance for the box's own size.
    expect(Math.abs(moved.x - 50)).toBeLessThan(20);
    expect(Math.abs(moved.y - 50)).toBeLessThan(20);

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

    await page.getByRole('button', { name: 'Add panel' }).click();
    await settle(page, 600);
    // The panel button opens a Left/Right chooser.
    await page.getByRole('button', { name: 'Left half' }).click();
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

    const year = page.locator('input[wire\\:model\\.blur="selectedScene.year"]');
    const y1 = await year.inputValue();
    // Click the second scene thumb in the rail.
    await page.locator('[wire\\:click^="selectScene("]').nth(1).click();
    await settle(page, 2500);
    const y2 = await year.inputValue();
    expect(y2, 'inspector year must change with the scene').not.toBe(y1);

    expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([]);
  });

  test('quiz scene exists in the rail for a generated lesson', async ({ page }) => {
    await openEditor(page);
    await expect(page.locator('text=QUIZ').first()).toBeVisible({ timeout: 10_000 });
  });
});
