/**
 * Five-language UI, driven through the STUDENT PLAYER.
 *
 * The interface ships in en/nl/de/fr/it and `php artisan lang:audit` reports every language at
 * 100%. That audit only sees `__()` in PHP and Blade, so two whole classes of string are invisible
 * to it:
 *
 *   1. text a Blade template prints without `__()` at all, and
 *   2. text the player's JavaScript builds by hand.
 *
 * (2) has a mechanism now — App\Support\JsTranslations publishes a dictionary into
 * `window.__lpLang` and `t()` reads it — but only the strings LISTED there are translated.
 * Anything else is still hardcoded English, in every language, with nothing failing.
 *
 * So this spec does what the audit cannot: it puts the teacher's account into French and into
 * Dutch through the real settings screen, opens a real published lesson in that language, walks
 * the title screen → transport deck → chapter list → quiz card, and reads the text that is
 * actually painted on the glass. Every English word it finds is reported with a screenshot.
 *
 * Each test sets the language it needs, so one failing check never hides the next one. Screenshots
 * land in tests/playwright/results/five-language-ui/ and are the evidence, not the assertions.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { waitForAnswers } from './support/quiz';

const SHOTS = path.resolve('tests/playwright/results/five-language-ui');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** The two published lessons this spec drives, and the language each one is written in. */
const FRENCH = { code: 'V6OYLL', locale: 'Français' };
const DUTCH = { code: 'WMDIF8', locale: 'Nederlands' };

/**
 * English phrases that must never survive into a translated page.
 *
 * Whole phrases a person would read, not fragments: "Quiz" alone appears inside the French lesson's
 * own content, and matching it would report the lesson's words as a bug.
 */
const ENGLISH_NEEDLES = [
  'Start lesson',
  'Audio generating',
  'Narrated by',
  'Scan to join',
  'Join the leaderboard',
  'Lesson starts in',
  'Skip ›',
  'Chapter 1',
  'Chapter 2',
  'Your Challenge',
  'Begin the challenge',
  'Continue the lesson',
  'New Intelligence',
];

/**
 * Every English needle currently painted on screen, with the element that paints it.
 *
 * Walks visible text nodes rather than page.content(): the player keeps several phases in the DOM
 * at once (title screen, game brief, intel drop) and only one of them is on the glass. A leak
 * nobody can see is not worth reporting, and reporting it would bury the real ones.
 */
async function visibleEnglish(page: Page, needles: string[]) {
  return page.evaluate((list) => {
    const seen: Array<{ text: string; needle: string; where: string }> = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      const text = (node.textContent ?? '').trim();
      if (!text) continue;
      const el = node.parentElement;
      if (!el) continue;
      const style = getComputedStyle(el);
      if (style.visibility === 'hidden' || style.display === 'none' || Number(style.opacity) === 0) continue;
      const box = el.getBoundingClientRect();
      if (box.width === 0 || box.height === 0) continue;
      // Off the glass entirely (a phase parked outside the viewport) counts as not visible.
      if (box.bottom < 0 || box.top > innerHeight || box.right < 0 || box.left > innerWidth) continue;

      for (const needle of list) {
        if (!text.includes(needle)) continue;
        const cls = (el.className || '').toString().split(' ')[0];
        seen.push({ text: text.slice(0, 120), needle, where: `${el.tagName.toLowerCase()}${cls ? '.' + cls : ''}` });
      }
    }
    return seen;
  }, needles);
}

const report = (leaks: Array<{ needle: string; where: string }>) =>
  leaks.map((l) => `"${l.needle}"  ← ${l.where}`).join('\n');

/**
 * Set the signed-in teacher's interface language through the account settings screen — the same
 * three clicks a teacher makes. Not a database write: the switcher is what drives the player, so
 * the switcher is what the test uses.
 */
async function setInterfaceLanguage(page: Page, languageName: string) {
  await page.goto('/settings');
  // Two identical pickers on this page (interface language, then teaching language). The first is
  // the interface one; no accessible name here survives being translated.
  const picker = page.locator('[aria-haspopup="listbox"]').first();
  await picker.waitFor({ state: 'visible' });

  // Picking writes the property through $wire.set, and the Livewire round-trip re-renders the
  // whole list — so the <li> Playwright is about to click detaches under it mid-click. Retry
  // against the visible state (the button now names the language) instead of against a clock.
  for (let attempt = 0; attempt < 4; attempt++) {
    if ((await picker.innerText()).includes(languageName)) break;
    await picker.click().catch(() => {});
    await page.getByRole('option', { name: languageName, exact: true }).first()
      .click({ force: true, timeout: 5_000 })
      .catch(() => {});
    await expect(picker).toContainText(languageName, { timeout: 5_000 }).catch(() => {});
  }
  await expect(picker, `the language picker never settled on ${languageName}`).toContainText(languageName);

  // The Save button is itself translated, so target the wire action rather than an English label.
  await page.locator('button[wire\\:click="save"]').click();
  await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 15_000 });
}

/** Open the player and wait for the title screen to have finished loading. */
async function openPlayer(page: Page, code: string) {
  await page.goto(`/lesson/${code}`);
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const phase = root?._x_dataStack?.[0]?.phase;
    return Boolean(phase) && phase !== 'LOADING';
  }, undefined, { timeout: 45_000 });
}

/** The Alpine player state, for waiting on phase/scene rather than on a clock. */
const playerState = (page: Page) =>
  page.evaluate(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const s = root?._x_dataStack?.[0];
    return s ? { phase: s.phase, chapters: (s.chapters ?? []).map((c: any) => c.name), canStart: s.canStart } : null;
  });

/** Press the title-screen CTA, whatever language it ended up in, and wait for the story to run. */
async function startLesson(page: Page) {
  await page.locator('button:has(svg)').filter({ hasText: /lesson|leçon|les|Lektion|lezione/i }).first()
    .click({ timeout: 15_000 })
    .catch(async () => { await page.getByText('Start lesson', { exact: false }).first().click(); });
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as any;
    return ['INTRO', 'WELCOME_VIDEO', 'GAME_BRIEF', 'GAME_ACTIVE'].includes(root?._x_dataStack?.[0]?.phase);
  }, undefined, { timeout: 60_000 });
}

/** Reveal the auto-hiding Netflix-style transport deck the way a viewer does: move the pointer. */
async function wakeDeck(page: Page) {
  const size = page.viewportSize() ?? { width: 1280, height: 720 };
  await page.mouse.move(size.width / 2, size.height - 40);
  await page.mouse.move(size.width / 2, size.height - 50);
  await page.mouse.move(size.width / 2, size.height - 60);
}

test.afterAll(async ({ browser }) => {
  // Leave the dev teacher's account the way it was found.
  const page = await browser.newPage();
  await setInterfaceLanguage(page, 'English');
  await page.close();
});

test.describe('five-language UI — the player dictionary', () => {
  // "Nice!" was the probe here until the quiz card stopped praising in words — see
  // resources/js/scene/QuizOverlay.js. "Quiz paused" is the same kind of string: built in
  // JavaScript, invisible to a Blade-only audit, and the reason JsTranslations exists.
  for (const [name, key, notEnglish] of [
    ['Français', 'Quiz paused', 'Quiz en pause'],
    ['Nederlands', 'Quiz paused', null],
    ['Deutsch', 'Quiz paused', null],
    ['Italiano', 'Quiz paused', null],
  ] as Array<[string, string, string | null]>) {
    test(`window.__lpLang is translated for ${name}`, async ({ page }) => {
      await setInterfaceLanguage(page, name);
      await openPlayer(page, FRENCH.code);
      const dict = await page.evaluate(() => (window as any).__lpLang);
      expect(dict, 'the player page should publish a JS dictionary').toBeTruthy();
      if (notEnglish) expect(dict[key]).toBe(notEnglish);
      // Whatever the wording, it must not still be the English source string.
      expect(dict[key], `${name}: "${key}" is still English`).not.toBe(key);
      expect(dict['Submit'], `${name}: "Submit" is still English`).not.toBe('Submit');
      expect(dict['Quiz paused'], `${name}: "Quiz paused" is still English`).not.toBe('Quiz paused');
    });
  }
});

test.describe('five-language UI — French lesson, French teacher', () => {
  test('the title screen has no English on it', async ({ page }) => {
    await setInterfaceLanguage(page, FRENCH.locale);
    await openPlayer(page, FRENCH.code);
    await page.screenshot({ path: shot('fr-01-title-screen') });

    const leaks = await visibleEnglish(page, ENGLISH_NEEDLES);
    expect(report(leaks), `English on a French title screen — see ${shot('fr-01-title-screen')}`).toBe('');
  });

  test('the transport deck is French', async ({ page }) => {
    await setInterfaceLanguage(page, FRENCH.locale);
    await openPlayer(page, FRENCH.code);
    await startLesson(page);
    await wakeDeck(page);
    // Wait for the deck itself, not for a moment in time: it fades in and auto-hides again.
    await page.locator('button[aria-expanded]').first().waitFor({ state: 'visible', timeout: 30_000 })
      .catch(() => {});
    await wakeDeck(page);
    await page.screenshot({ path: shot('fr-02-transport-deck') });

    const labels = (await page.locator('[aria-label]').evaluateAll((els) =>
      els.map((e) => e.getAttribute('aria-label')).filter(Boolean) as string[],
    )).join(' | ');

    // These come from Blade __() and lang/fr.json, so they should already be translated.
    expect(labels, 'the deck should offer French controls').toMatch(/Chapitres/);
    expect(labels).toMatch(/Lecture|Pause/);
    expect(labels, 'an English aria-label on a French deck').not.toMatch(/\bChapters\b|\bSubtitles\b|\bPrevious\b|\bNext\b/);
  });

  test('the quiz card reads in French, gate and all', async ({ page }) => {
    // A full quiz walk is a long test and got longer when the read-gate became animation-led:
    // the answers now arrive one bar at a time, so every question costs a few seconds more
    // (resources/js/scene/QuizOverlay.js). Without this the run dies on the 60s default and
    // reports a timeout instead of whatever it actually found.
    test.slow();
    await setInterfaceLanguage(page, FRENCH.locale);
    await openPlayer(page, FRENCH.code);
    await startLesson(page);

    // This lesson opens on a "what do you already know?" quiz scene, so the card is the first
    // thing a class sees. Wait for the overlay itself, never for a duration.
    const overlay = page.locator('#lesson-game-overlay');
    await overlay.locator('button').first().waitFor({ state: 'visible', timeout: 90_000 });
    await page.screenshot({ path: shot('fr-03-quiz-gate') });

    // The gate used to be a sentence ("Lis la question…") and is now a counting number, so there
    // is no wording left on it to get wrong in any language. What must hold is that the gate is
    // language-free: the only text on the card is the question and its answers, plus a digit.
    const gateSecs = overlay.locator('[data-gate-secs]');
    if (await gateSecs.count()) {
      expect((await gateSecs.innerText()).trim(), 'the gate countdown is a bare number').toMatch(/^\d+$/);
    }

    // Answer through to the score screen, where the untranslated strings live.
    for (let i = 0; i < 14; i++) {
      if (await page.locator('[data-final-score]').count()) break;
      const answers = await waitForAnswers(overlay);
      if (!answers) break;
      await answers.first().click({ timeout: 5_000 }).catch(() => {});
      await page.waitForTimeout(1400);
    }

    await page.screenshot({ path: shot('fr-04-quiz-score-screen') });
    const scoreText = await overlay.innerText();
    expect(
      scoreText,
      `English on the French quiz end screen — see ${shot('fr-04-quiz-score-screen')}`,
    ).not.toMatch(/Join the leaderboard|Lesson starts in|Skip ›|\bcorrect\b/);
  });
});

test.describe('five-language UI — Dutch lesson, Dutch teacher', () => {
  test('the title screen has no English on it', async ({ page }) => {
    await setInterfaceLanguage(page, DUTCH.locale);
    await openPlayer(page, DUTCH.code);
    await page.screenshot({ path: shot('nl-01-title-screen') });

    const leaks = await visibleEnglish(page, ENGLISH_NEEDLES);
    expect(report(leaks), `English on a Dutch title screen — see ${shot('nl-01-title-screen')}`).toBe('');
  });

  test('scenes with no chapter name of their own get a Dutch one', async ({ page }) => {
    await setInterfaceLanguage(page, DUTCH.locale);
    await openPlayer(page, DUTCH.code);

    // Six of this lesson's seven scenes carry no chapter_name, so the player invents one. That
    // invented name is what the chapter list and every progress-bar tooltip show a Dutch class.
    const state = await playerState(page);
    expect(
      (state?.chapters ?? []).join(' | '),
      'the player invented English chapter names inside a Dutch lesson',
    ).not.toMatch(/Chapter \d/);
  });

  test('the chapter list is Dutch', async ({ page }) => {
    await setInterfaceLanguage(page, DUTCH.locale);
    await openPlayer(page, DUTCH.code);
    await startLesson(page);

    // The panel must actually be OPEN before anything is scanned. It opens off an auto-hiding
    // deck, so a click that lands while the deck is fading reads as "no English found" and the
    // test goes green having looked at nothing.
    const toggle = page.locator('button[aria-expanded]').first();
    let open = false;
    for (let attempt = 0; attempt < 4 && !open; attempt++) {
      await wakeDeck(page);
      await toggle.click({ timeout: 10_000 }).catch(() => {});
      open = await page.evaluate(() => {
        const root = document.querySelector('[x-data]') as any;
        return Boolean(root?._x_dataStack?.[0]?.chaptersOpen);
      });
    }
    expect(open, 'the chapter list never opened — nothing was scanned').toBe(true);
    await page.screenshot({ path: shot('nl-02-chapter-list') });

    const onScreen = await visibleEnglish(page, ['Chapter 1', 'Chapter 2', 'Chapter 3']);
    expect(
      report(onScreen),
      `an English chapter name inside a Dutch lesson — see ${shot('nl-02-chapter-list')}`,
    ).toBe('');
  });
});
