import { test, expect, Page } from '@playwright/test';

/**
 * Jumping out of a game scene must not leave its overlay over the rest of the lesson.
 *
 * A `game` scene puts the player into the GAME_BRIEF phase, which shows a full-bleed strategy
 * briefing — backdrop, blur and all — and deliberately waits there until the teacher taps "Begin
 * the challenge". Nothing else cleared it. So a teacher who opened the chapter list and jumped
 * from the opening quiz to a later chapter got that briefing card painted over every scene after
 * it: the Silk Road's map scene rendered perfectly, ran its camera tour, and was invisible under a
 * Julius Caesar challenge.
 *
 * The quiz card one line above it in _playScene had exactly this bug and exactly this fix, which
 * is why this is worth a test rather than a comment.
 */

/** A published lesson that opens on a game scene and has a non-game scene after it. */
async function findLessonWithGameThenScene(page: Page) {
  const res = await page.request.get('/api/v1/catalog/manifest');
  if (!res.ok()) return null;
  const lessons: Array<{ code: string; status: string }> = (await res.json()).lessons ?? [];

  for (const l of lessons.filter((x) => x.status === 'published')) {
    const html = await (await page.request.get(`/lesson/${l.code}`)).text();
    const start = html.indexOf('"scenes":[');
    if (start === -1) continue;
    let depth = 0;
    let end = -1;
    for (let i = html.indexOf('[', start); i < html.length; i++) {
      if (html[i] === '[') depth++;
      else if (html[i] === ']' && --depth === 0) { end = i + 1; break; }
    }
    if (end === -1) continue;

    let scenes: Array<{ kind: string }>;
    try { scenes = JSON.parse(html.slice(html.indexOf('[', start), end)); } catch { continue; }

    const game = scenes.findIndex((s) => s.kind === 'game');
    if (game === -1) continue;
    const after = scenes.findIndex((s, i) => i > game && s.kind !== 'game');
    if (after !== -1) return { code: l.code, game, after };
  }
  return null;
}

test('a chapter jump out of the strategy challenge takes its briefing card with it', async ({ page }) => {
  const fx = await findLessonWithGameThenScene(page);
  test.skip(!fx, 'no published lesson opens on a game scene with a later non-game scene');

  await page.goto(`/lesson/${fx!.code}`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    const patch = () => document.querySelectorAll('audio,video').forEach((m) => {
      (m as HTMLMediaElement).muted = true; (m as HTMLMediaElement).volume = 0;
    });
    patch(); setInterval(patch, 200);
  });
  await page.waitForTimeout(1200);

  const phase = () => page.evaluate(() =>
    (document.querySelector('[x-data]') as any)._x_dataStack[0].phase as string);

  // Land on the game scene: the briefing is up and waiting, as designed.
  await page.evaluate(async (i) => {
    const c = (document.querySelector('[x-data]') as any)._x_dataStack[0];
    await c.startLesson();
    await new Promise((r) => setTimeout(r, 400));
    c.goToChapter(i);
  }, fx!.game);
  await page.waitForFunction(() =>
    (document.querySelector('[x-data]') as any)._x_dataStack[0].phase === 'GAME_BRIEF',
    { timeout: 20_000 });

  // Now jump away, the way a teacher does from the chapter list.
  await page.evaluate((i) => {
    (document.querySelector('[x-data]') as any)._x_dataStack[0].goToChapter(i);
  }, fx!.after);
  await page.waitForTimeout(1500);

  expect(await phase()).not.toBe('GAME_BRIEF');

  // And the card itself is gone from the screen, not merely un-phased.
  const briefVisible = await page.evaluate(() => {
    const card = [...document.querySelectorAll('div')].find(
      (d) => d.className.includes('backdrop-blur-md') && d.textContent?.includes('Challenge'));
    if (!card) return false;
    const s = getComputedStyle(card);
    return s.display !== 'none' && s.visibility !== 'hidden' && Number(s.opacity) > 0.01;
  });
  expect(briefVisible).toBe(false);
});
