/**
 * Chapter names a student actually reads, in a lesson written in Dutch.
 *
 * A scene may carry no chapter_name of its own. Something still has to be shown in the chapter
 * list, on the chapter line above the deck and in every segmented-progress tooltip, so the app
 * falls back: Scene::chapterName() (app/Models/Scene.php) tries the scene's `location` and then
 * returns 'Chapter '.$order, and the player's JS keeps the same last resort at
 * resources/js/lesson-player.js:987. Neither goes through __() or the JS dictionary.
 *
 * The question is not "is there a fallback" but "does a Dutch class read an English word because
 * of it". So this spec puts the interface into Dutch through the real settings screen, opens the
 * real published lesson WMDIF8 (De reis van Abel Tasman — 7 scenes, only one of which has a
 * chapter_name), starts it, pauses so the deck stays on the glass, and reads the text painted in
 * all three places at once.
 *
 * Harness notes: nothing here asserts on motion, so the default chromium project is right
 * (SwiftShader only freezes compositor transitions). Audio is muted by the project's --mute-audio
 * flag and again by hand right after start, so a suite run stays silent. Locale cannot come from
 * Accept-Language locally: AutoLoginDev signs every request in as a teacher, and SetLocale then
 * prefers that user's stored locale — hence the settings screen, which is also what a teacher does.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results/chapter-name-fallback');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

const DUTCH_LESSON = 'WMDIF8'; // De reis van Abel Tasman — 6 voyage legs + 1 narration
const ENGLISH_FALLBACK = /^Chapter \d+$/;

/**
 * The player's Alpine state. Alpine ships inside the Livewire bundle and never lands on `window`,
 * so the component is read off the stage element's data stack — the same object the template's
 * expressions see.
 */
async function playerState(page: Page) {
  return page.evaluate(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const d = root?._x_dataStack?.[0];
    if (!d) return { phase: 'LOADING', chapters: [] as string[], currentChapterName: '', paused: false };
    return {
      phase: d.phase as string,
      chapters: (d.chapters ?? []).map((c: any) => String(c.name)),
      currentChapterName: String(d.currentChapterName ?? ''),
      paused: Boolean(d.playbackPaused),
    };
  });
}

/** Switch the interface language the way a teacher does — the picker on /settings, then Save. */
async function setInterfaceLanguage(page: Page, languageName: string) {
  await page.goto('/settings');
  const picker = page.locator('[aria-haspopup="listbox"]').first();
  await picker.waitFor({ state: 'visible' });
  for (let attempt = 0; attempt < 4; attempt++) {
    if ((await picker.innerText()).includes(languageName)) break;
    await picker.click().catch(() => {});
    await page.getByRole('option', { name: languageName, exact: true }).first()
      .click({ force: true, timeout: 5_000 }).catch(() => {});
    await expect(picker).toContainText(languageName, { timeout: 5_000 }).catch(() => {});
  }
  await expect(picker, `the language picker never settled on ${languageName}`).toContainText(languageName);
  await page.locator('button[wire\\:click="save"]').click();
  await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 15_000 });
}

/** Open the lesson, press play, mute, then pause so the deck and its controls stay on screen. */
async function openAndPause(page: Page, code: string) {
  await page.goto(`/lesson/${code}`);
  await expect.poll(async () => (await playerState(page)).phase, { timeout: 45_000 }).not.toBe('LOADING');

  await page.locator('button:has-text("Start lesson"), button:has-text("Start de les")').first().click();
  await page.evaluate(() => {
    document.querySelectorAll('audio,video').forEach((m: any) => { m.muted = true; m.volume = 0; });
  });
  await expect.poll(async () => (await playerState(page)).phase, { timeout: 45_000 }).toBe('INTRO');

  // Pausing is what a teacher does to look around, and it is what keeps the idle-hiding deck up.
  await page.mouse.move(640, 360);
  await page.keyboard.press('k');
  await expect.poll(async () => (await playerState(page)).paused, { timeout: 10_000 }).toBe(true);
}

test('a Dutch class never reads an English chapter name', async ({ page }) => {
  await setInterfaceLanguage(page, 'Nederlands');
  await openAndPause(page, DUTCH_LESSON);

  const state = await playerState(page);
  expect(state.chapters.length, 'this lesson must really be the multi-chapter case').toBeGreaterThan(1);

  // The UI around it is genuinely Dutch — otherwise an English name proves nothing.
  const toggle = page.getByRole('button', { name: 'Hoofdstukken' });
  await expect(toggle, 'the interface never switched to Dutch').toBeVisible({ timeout: 10_000 });

  // 1. The chapter LIST.
  await toggle.click();
  const listEntries = page.locator('button:has(span.font-mono) span.text-sm');
  await expect(listEntries.first()).toBeVisible();
  const listNames = (await listEntries.allInnerTexts()).map((s) => s.trim());
  await page.screenshot({ path: shot('nl-chapter-list') });

  // 2. The chapter LINE above the deck.
  const lineText = (await page.locator('.lp-chapter-line span.truncate').first().innerText()).trim();

  // 3. Every segmented-progress TOOLTIP.
  const tooltips = await page.locator('.flex.items-center.gap-1 > button[data-tooltip]')
    .evaluateAll((els) => els.map((e) => e.getAttribute('data-tooltip') ?? '').filter(Boolean));

  const offenders = {
    alpine: state.chapters.filter((n) => ENGLISH_FALLBACK.test(n)),
    list: listNames.filter((n) => ENGLISH_FALLBACK.test(n)),
    line: ENGLISH_FALLBACK.test(lineText) ? [lineText] : [],
    tooltips: tooltips.filter((n) => ENGLISH_FALLBACK.test(n)),
  };

  // eslint-disable-next-line no-console
  console.log('chapter names as painted:\n' + JSON.stringify({ listNames, lineText, tooltips, offenders }, null, 2));

  expect(offenders, `English "Chapter N" on a Dutch stage: ${JSON.stringify(offenders)}`)
    .toEqual({ alpine: [], list: [], line: [], tooltips: [] });
});
