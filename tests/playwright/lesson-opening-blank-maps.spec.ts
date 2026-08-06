/**
 * What a student gets in the first fifteen seconds of a published lesson.
 *
 * A report from the player lane claims the Anne Frank lesson (CF0AVG, published) opens on four
 * chapterless map slides — two of them with no country to draw at all, one carrying a text layer
 * whose content is the literal word "Title" — and that each one waits for a Continue click before
 * the story starts. This spec goes and looks, as a student would: load the lesson, press Start,
 * and record what is on the stage before "A family in Frankfurt" is reached.
 *
 * Harness notes borrowed from student-transport-deck.spec.ts, all of which apply here:
 *  - Alpine lives inside Livewire, so state is read off #lesson-stage's data stack.
 *  - HMR's `full-reload` snaps the player back to its title screen mid-run; filter it, but let the
 *    rest of the socket through or the dynamic imports 404.
 *  - MapLibre will not finish a style while the page is hidden — this run is headless-visible, and
 *    nothing below waits on the map being *drawn*, only on the player's own scene queue.
 *  - Nothing here asserts on a transition mid-flight, so the default chromium project (SwiftShader)
 *    is correct: the map block needs WebGL.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';

const CODE = process.env.BLANK_MAP_LESSON_CODE ?? 'CF0AVG'; // Anne Frank
const SHOTS = 'tests/playwright/report/blank-maps';
const CENTRE = { x: 640, y: 320 };
const BOTTOM_EDGE = { x: 640, y: 700 };
const CHAPTERS = '#lesson-stage button[aria-expanded]';
const CHAPTER_ITEMS = '#lesson-stage div.mb-3.overflow-y-auto button';

fs.mkdirSync(SHOTS, { recursive: true });

type Snapshot = {
  phase: string;
  index: number;
  kind: string | null;
  chapter: string;
  qid: string | null;
  mode: string | null;
  atLandfall: boolean;
  sceneId: number | null;
};

async function read(page: Page): Promise<Snapshot> {
  return page.evaluate(() => {
    const d = (document.getElementById('lesson-stage') as any)._x_dataStack[0];
    const s = d.lesson?.scenes?.[d._sceneIndex] ?? null;
    return {
      phase: d.phase,
      index: d._sceneIndex,
      kind: s?.kind ?? null,
      chapter: d.currentChapterName ?? '',
      qid: s?.config?.qid ?? null,
      mode: s?.config?.playback_mode ?? null,
      atLandfall: d.showMapContinue,
      sceneId: s?.id ?? null,
    };
  });
}

async function survivesHotReload(page: Page) {
  await page.routeWebSocket(/:5173\//, (ws) => {
    const server = ws.connectToServer();
    ws.onMessage((m) => server.send(m as any));
    server.onMessage((m) => {
      if (typeof m === 'string' && m.includes('full-reload')) return;
      ws.send(m as any);
    });
  });
}

async function openPlayer(page: Page, code: string) {
  await survivesHotReload(page);
  await page.goto(`/lesson/${code}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () => (document.getElementById('lesson-stage') as any)?._x_dataStack?.[0]?.phase === 'TITLE_SCREEN',
    null,
    { timeout: 45_000 },
  );
}

test.use({ viewport: { width: 1280, height: 720 } });
// Four map slides in a row, each mounting MapLibre from scratch, outlast the default 60s.
test.setTimeout(180_000);

test('a student pressing Start reaches the first story chapter without wading through blank maps', async ({ page }) => {
  await openPlayer(page, CODE);

  // The queue the player was handed, before a single click.
  const queue = await page.evaluate(() =>
    ((document.getElementById('lesson-stage') as any)._x_dataStack[0].lesson.scenes as any[]).map((s, i) => ({
      i,
      id: s.id,
      kind: s.kind,
      chapter: s.chapter_name ?? null,
      qid: s.config?.qid ?? null,
      mode: s.config?.playback_mode ?? null,
      layerText: JSON.stringify(s.config?.text_layers ?? s.config?.layers ?? s.config?.texts ?? null),
    })),
  );
  console.log('QUEUE:', JSON.stringify(queue.slice(0, 6), null, 1));
  console.log('TAIL:', JSON.stringify(queue.slice(-3), null, 1));

  await page.screenshot({ path: `${SHOTS}/01-title-screen.png` });

  await page.getByRole('button', { name: /Start lesson/i }).click();
  await page.waitForFunction(
    () => (document.getElementById('lesson-stage') as any)._x_dataStack[0].phase === 'INTRO',
    null,
    { timeout: 45_000 },
  );
  await page.mouse.move(CENTRE.x, CENTRE.y);

  // Give the first slide a moment to settle into whatever it is going to be.
  await page.waitForFunction(
    () => (document.getElementById('lesson-stage') as any)._x_dataStack[0]._sceneIndex >= 0,
    null,
    { timeout: 30_000 },
  );
  const first = await read(page);
  console.log('FIRST SLIDE AFTER START:', JSON.stringify(first));
  await page.screenshot({ path: `${SHOTS}/02-first-slide.png` });

  // Is the placeholder word "Title" painted on the stage?
  const titleWord = await page.evaluate(() => {
    const host = document.getElementById('lesson-text-overlay');
    return { overlayText: (host?.innerText ?? '').trim(), stageHasTitleWord: /(^|\s)Title(\s|$)/.test(document.getElementById('lesson-stage')?.innerText ?? '') };
  });
  console.log('TEXT OVERLAY:', JSON.stringify(titleWord));

  // How many Continue presses stand between Start and the first real chapter?
  const visited: Snapshot[] = [first];
  let clicks = 0;
  for (let guard = 0; guard < 12; guard++) {
    const now = await read(page);
    // "Chapter N" is the player's own fallback for a scene with no chapter_name, so a non-empty
    // chapter proves nothing. The story has begun when the queue leaves the map slides.
    if (now.kind && now.kind !== 'map') break;
    const cont = page.locator('#lesson-stage button', { hasText: /Continue|Verder|Weiter|Continuer|Continua/i }).first();
    if (!(await cont.isVisible().catch(() => false))) break;
    await cont.click();
    clicks++;
    await page.waitForTimeout(600);
    visited.push(await read(page));
  }
  console.log('CONTINUE CLICKS BEFORE A NAMED CHAPTER:', clicks);
  console.log('VISITED:', JSON.stringify(visited));
  await page.screenshot({ path: `${SHOTS}/03-after-continues.png` });

  // The chapter list is the student's own table of contents — what does it say?
  await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);
  const chapters = page.locator(CHAPTERS);
  await expect(chapters).toBeVisible({ timeout: 30_000 });
  await chapters.click();
  const items = await page.locator(CHAPTER_ITEMS).allInnerTexts();
  console.log('CHAPTER LIST:', JSON.stringify(items.slice(0, 8)));
  await page.screenshot({ path: `${SHOTS}/04-chapter-list.png` });

  // The claims, stated as the student's expectation.
  //
  // Note the payload's `chapter_name` is NOT a reliable witness: the player (and the payload)
  // substitute "Chapter N", or the country the qid names, for a scene that was never titled. The
  // honest measures are what the eye and the hand get — the word painted on the opening slide,
  // and the number of Continue presses standing between Start and the first spoken chapter.
  expect(titleWord.overlayText, 'opening slide should not display the placeholder word "Title"')
    .not.toMatch(/^Title$/);
  expect(clicks, 'a student should reach the story without clicking Continue through blank maps')
    .toBe(0);
});
