/**
 * Does jumping out of a MAP scene by the chapter list leave the atlas painted over the lesson?
 *
 * Written to REFUTE a report from the student-player lane. Everything here is driven the way a
 * class drives it: open the real Anne Frank lesson (CF0AVG), which genuinely opens on a map slide,
 * press Start, open the chapter list, and click the chapter by its own printed name
 * ("A family in Frankfurt", scene 4, a plain narrated scene).
 *
 * The assertion is on what the eye gets, not on internals: is #lesson-map-stage still painted, is
 * the amber Continue button still on the deck, and does pressing it move the student.
 *
 * Harness notes borrowed from student-transport-deck.spec.ts: HMR `full-reload` is filtered out
 * (another session editing CSS would snap the player back to its title screen and read as a bug),
 * and the pointer is parked deliberately — the deck only shows while it is in the bottom band.
 * Nothing here asserts on a CSS transition mid-flight, so the default chromium project is fine;
 * the map needs WebGL anyway.
 */
import { test, expect, type Page } from '@playwright/test';

const CODE = process.env.STALE_STAGE_LESSON_CODE ?? 'CF0AVG';   // Anne Frank
const TARGET_CHAPTER = 'A family in Frankfurt';
const SHOTS = 'tests/playwright/report/stale-stage';

const BOTTOM_EDGE = { x: 640, y: 700 };
const CHAPTERS = '#lesson-stage button[aria-expanded]';
const CHAPTER_ITEMS = '#lesson-stage div.mb-3.overflow-y-auto button';

type Snapshot = {
  index: number;
  kind: string | null;
  chapter: string;
  audioPlaying: boolean;
  showMapContinue: boolean;
  stageDisplay: string;
  stageHasCanvas: boolean;
  stageRect: { w: number; h: number } | null;
};

async function read(page: Page): Promise<Snapshot> {
  return page.evaluate(() => {
    const d = (document.getElementById('lesson-stage') as any)._x_dataStack[0];
    const stage = document.getElementById('lesson-map-stage');
    const r = stage?.getBoundingClientRect();
    return {
      index: d._sceneIndex,
      kind: d.lesson?.scenes?.[d._sceneIndex]?.kind ?? null,
      chapter: d.currentChapterName ?? '',
      audioPlaying: d.audioPlaying,
      showMapContinue: d.showMapContinue,
      stageDisplay: stage ? getComputedStyle(stage).display : 'missing',
      stageHasCanvas: !!stage?.querySelector('canvas'),
      stageRect: r ? { w: Math.round(r.width), h: Math.round(r.height) } : null,
    };
  });
}

async function openPlayer(page: Page, code: string) {
  await page.routeWebSocket(/:5173\//, (ws) => {
    const server = ws.connectToServer();
    ws.onMessage((m) => server.send(m as any));
    server.onMessage((m) => {
      if (typeof m === 'string' && m.includes('full-reload')) return;
      ws.send(m as any);
    });
  });
  await page.goto(`/lesson/${code}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () => (document.getElementById('lesson-stage') as any)?._x_dataStack?.[0]?.phase === 'TITLE_SCREEN',
    null,
    { timeout: 45_000 },
  );
}

test.describe('Student: leaving a map scene by the chapter list', () => {
  test.slow();

  test('the atlas is gone once a narrated chapter is playing', async ({ page }) => {
    await openPlayer(page, CODE);

    // The lesson really does open on a map — otherwise there is nothing to test.
    await page.getByRole('button', { name: /Start lesson/i }).click();
    await expect.poll(async () => (await read(page)).kind, { timeout: 45_000 }).toBe('map');
    await expect(page.locator('#lesson-map-stage canvas')).toBeVisible({ timeout: 90_000 });
    await page.screenshot({ path: `${SHOTS}/01-opening-map.png` });

    // Open the chapter list and click the chapter by the name a student reads on it.
    await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);
    const chapters = page.locator(CHAPTERS);
    await expect(chapters).toBeVisible({ timeout: 45_000 });
    await chapters.click();
    const entry = page.locator(CHAPTER_ITEMS).filter({ hasText: TARGET_CHAPTER }).first();
    await expect(entry).toBeVisible({ timeout: 15_000 });
    await entry.click();

    // The player says it is on that chapter, and it is speaking.
    await expect.poll(async () => (await read(page)).chapter, { timeout: 45_000 }).toBe(TARGET_CHAPTER);
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 45_000 }).toBe(true);

    const after = await read(page);
    await page.screenshot({ path: `${SHOTS}/02-after-jump.png` });
    console.log('state after the jump:', JSON.stringify(after));
    expect(after.kind, 'the scene it landed on is a plain narrated one').toBe('narration');

    // What the eye gets: a narrated scene owns the whole stage, so the atlas and the Continue
    // button that drives a map must both be gone.
    expect(after.stageDisplay, '#lesson-map-stage should not be painted on a narrated scene').toBe('none');
    expect(after.showMapContinue, 'the map Continue button should not survive the jump').toBe(false);
  });

  test('the stray Continue does not drive a scene that is not a map', async ({ page }) => {
    await openPlayer(page, CODE);
    await page.getByRole('button', { name: /Start lesson/i }).click();
    await expect.poll(async () => (await read(page)).kind, { timeout: 45_000 }).toBe('map');
    await expect(page.locator('#lesson-map-stage canvas')).toBeVisible({ timeout: 90_000 });

    await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);
    await page.locator(CHAPTERS).click();
    await page.locator(CHAPTER_ITEMS).filter({ hasText: TARGET_CHAPTER }).first().click();
    await expect.poll(async () => (await read(page)).chapter, { timeout: 45_000 }).toBe(TARGET_CHAPTER);

    // If any Continue is still on screen it belongs to the map the student left. Pressing it is
    // something a child WILL do — it is the biggest button on the deck.
    const cont = page.locator('#lesson-stage button').filter({ hasText: /Continue|Verder|Weiter|Continuer|Continua/i });
    const visible = await cont.count() > 0 && await cont.first().isVisible();
    console.log('stray Continue visible after the jump:', visible);
    await page.screenshot({ path: `${SHOTS}/03-stray-continue.png` });
    expect(visible, 'no map Continue button on a narrated scene').toBe(false);
  });
});
