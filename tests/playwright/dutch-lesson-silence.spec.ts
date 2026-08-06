/**
 * Refutation pass: "the Dutch lesson WMDIF8 is published with seven silent scenes".
 *
 * The claim was made from the database (script_segment empty, audio_path null on all seven rows).
 * A row is not an experience, so this spec puts a Dutch class in front of the lesson and records
 * what they actually get: every audio track the player constructs and plays, and every word that
 * ends up on the screen.
 *
 * A control run against the French lesson proves the audio probe can SEE narration when there is
 * some, so "no tracks" here means silence rather than a broken probe.
 *
 *   npx playwright test dutch-lesson-silence --project=chromium \
 *     --output=tests/playwright/results-dutch-silence
 */
import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const SHOTS = 'tests/playwright/results-dutch-silence/shots';
mkdirSync(SHOTS, { recursive: true });
const shot = (n: string) => path.join(SHOTS, n);

const DUTCH = process.env.SILENCE_NL_CODE ?? 'WMDIF8';
const FRENCH = process.env.SILENCE_FR_CODE ?? 'V6OYLL';

/** Wrap `new Audio()` before any page script runs: the player never puts its tracks in the DOM. */
async function trackAudio(page: Page) {
  await page.addInitScript(() => {
    (window as any).__tracks = [];
    const Native = window.Audio;
    const Wrapped = function (src?: string) {
      const el = new Native(src);
      const rec = { src: src ?? '', played: false, el };
      (window as any).__tracks.push(rec);
      const play = el.play.bind(el);
      el.play = () => { rec.played = true; return play(); };
      return el;
    } as unknown as typeof Audio;
    Wrapped.prototype = Native.prototype;
    window.Audio = Wrapped;
  });
}

type Track = { src: string; played: boolean; t: number };
const tracks = (page: Page) =>
  page.evaluate(() =>
    (window as any).__tracks.map((r: any) => ({ src: r.src, played: r.played, t: r.el.currentTime })) as Track[]);

const isNarration = (t: Track) => /\/scenes\/\d+\/narration\./.test(t.src);

async function startLesson(page: Page, code: string, scene?: number) {
  await trackAudio(page);
  await page.goto(`/lesson/${code}${scene === undefined ? '' : `?scene=${scene}`}`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: /start lesson|commencer la leçon|start de les|lektion starten|inizia la lezione/i }).click();
}

test.describe.serial('what a Dutch class hears', () => {
  test('CONTROL — the French lesson does narrate, so the probe works', async ({ page }) => {
    await startLesson(page, FRENCH, 1);
    await expect
      .poll(async () => (await tracks(page)).filter((t) => t.played && isNarration(t)).length,
        { timeout: 30_000, intervals: [500], message: 'probe saw no narration in the control lesson' })
      .toBeGreaterThan(0);
    await page.screenshot({ path: shot('control-fr-playing.png'), fullPage: false });
  });

  test('the Dutch lesson plays through with no narration track at all', async ({ page }) => {
    test.setTimeout(180_000);

    await startLesson(page, DUTCH);

    // Give the lesson a real run: sit through the opening scene, then walk forward through every
    // scene the way a class would, watching for any narration the player might start late.
    await page.waitForTimeout(4000);
    await page.screenshot({ path: shot('nl-scene-1.png') });

    const seen: string[] = [];
    for (let i = 0; i < 7; i++) {
      const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
      seen.push(body.slice(0, 400));
      await page.keyboard.press('ArrowRight');
      await page.waitForTimeout(2500);
      await page.screenshot({ path: shot(`nl-scene-${i + 2}.png`) });
    }

    const all = await tracks(page);
    const narration = all.filter(isNarration);
    // eslint-disable-next-line no-console
    console.log('AUDIO TRACKS CONSTRUCTED:', JSON.stringify(all.map((t) => ({ src: t.src, played: t.played })), null, 1));
    // eslint-disable-next-line no-console
    console.log('ON-SCREEN TEXT PER SCENE:', JSON.stringify(seen, null, 1));

    // The finding, stated as an assertion: nobody speaks.
    expect(narration, 'a narration track appeared — the lesson is not silent').toHaveLength(0);
  });

  test('…but the student is not staring at a blank screen: the Dutch story text is there', async ({ page }) => {
    test.setTimeout(120_000);
    await startLesson(page, DUTCH);
    await page.waitForTimeout(3000);

    // Walk until a stop card with the authored Dutch prose shows up.
    let text = '';
    for (let i = 0; i < 8; i++) {
      text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
      if (/Verversingspost|Mauritius|VOC|Batavia|zuidland/i.test(text)) break;
      await page.keyboard.press('ArrowRight');
      await page.waitForTimeout(3000);
    }
    await page.screenshot({ path: shot('nl-story-text.png') });
    // eslint-disable-next-line no-console
    console.log('DUTCH TEXT FOUND:', text.slice(0, 600));
    expect(text, 'no authored Dutch text reached the screen either').toMatch(/Mauritius|VOC|Batavia|zuidland|Verversingspost/i);
  });
});
