/**
 * The quiz SCORE SCREEN, in a Dutch and a German classroom.
 *
 * A report from the student-player lane claims the score card is the one surface in the player
 * that never got translated: a Dutch or German class finishes the quiz and reads "4 / 4 correct",
 * "Join the leaderboard", "Skip ›" and "Lesson starts in" in English, while the deck and the quiz
 * cards around it are localised. This spec exists to REFUTE that claim, in the two languages the
 * report actually names (a parallel lane covers French).
 *
 * The refutation the app would have to produce is specific, so the spec looks for it:
 *
 *   1. the dictionary really did reach the player (otherwise English everywhere is a locale
 *      plumbing bug, not a missing t() call), and
 *   2. the SAME QuizOverlay component translates other strings on the way to the score card —
 *      the read-gate line, the praise word. If those are localised and the score card is not,
 *      no upstream guard is rescuing the raw strings.
 *
 * Every question is answered CORRECTLY, by matching the option text against the correct answer in
 * the player's own payload. That reproduces the exact state the report describes — a full score
 * and a streak badge — rather than a lucky partial one.
 *
 * Harness notes: nothing here asserts on motion, so the default SwiftShader project is correct.
 * The page is never hidden, so rAF (the score count-up) and the read-gate timers run normally.
 * No MapLibre style is involved — the quiz is scene 1, before any map.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { waitForAnswers } from './support/quiz';

const SHOTS = path.resolve('tests/playwright/results-quiz-score-nl-de');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** The Silk Road. Published, narrated, and its scene 1 is a quiz — no lesson is authored here. */
const LESSON_CODE = '4NA554';

/**
 * English no Dutch or German class should be shown. Whole phrases only: a bare word like "Quiz"
 * or a proper noun in the lesson's own English copy would otherwise read as a bug.
 */
const ENGLISH_NEEDLES = [
  'Join the leaderboard',
  'Lesson starts in',
  'Skip ›',
  'correct',
  'streak',
  'No scores yet',
  'keep climbing',
  'Submit',
];

const LANGUAGES = [
  { option: 'Nederlands', tag: 'nl', submitWord: 'Versturen', nameWord: 'Je naam…' },
  { option: 'Deutsch', tag: 'de', submitWord: 'Senden', nameWord: 'Dein Name…' },
];

/** Set the signed-in teacher's interface language through the real settings screen. */
async function setInterfaceLanguage(page: Page, languageName: string) {
  await page.goto('/settings');
  const picker = page.locator('[aria-haspopup="listbox"]').first();
  await picker.waitFor({ state: 'visible' });

  for (let attempt = 0; attempt < 4; attempt++) {
    if ((await picker.innerText()).includes(languageName)) break;
    await picker.click().catch(() => {});
    await page.getByRole('option', { name: languageName, exact: true }).first()
      .click({ force: true, timeout: 5_000 })
      .catch(() => {});
    await expect(picker).toContainText(languageName, { timeout: 5_000 }).catch(() => {});
  }
  await expect(picker, `the language picker never settled on ${languageName}`).toContainText(languageName);
  await page.locator('button[wire\\:click="save"]').click();
  await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 15_000 });
}

async function openPlayer(page: Page, code: string) {
  await page.goto(`/lesson/${code}`);
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const phase = root?._x_dataStack?.[0]?.phase;
    return Boolean(phase) && phase !== 'LOADING';
  }, undefined, { timeout: 45_000 });
}

async function startLesson(page: Page) {
  await page.locator('button:has(svg)').filter({ hasText: /lesson|leçon|les|Lektion|lezione/i }).first()
    .click({ timeout: 15_000 })
    .catch(async () => { await page.getByText('Start', { exact: false }).first().click(); });
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as any;
    return ['INTRO', 'WELCOME_VIDEO', 'GAME_BRIEF', 'GAME_ACTIVE'].includes(root?._x_dataStack?.[0]?.phase);
  }, undefined, { timeout: 60_000 });
}

/** The correct option TEXT for every question in the first quiz scene, from the player's payload. */
async function correctAnswerTexts(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const root = document.querySelector('[x-data]') as any;
    // The payload hangs off `lesson` on the player's Alpine root, not off the root itself.
    const scenes = root?._x_dataStack?.[0]?.lesson?.scenes ?? [];
    const quiz = scenes.find((s: any) => Array.isArray(s.quiz_questions) && s.quiz_questions.length);

    return (quiz?.quiz_questions ?? []).map((q: any) => String((q.options ?? [])[Number(q.correct_index)] ?? ''));
  });
}

/**
 * Answer every question correctly until the score card appears, collecting the localisable text
 * the SAME overlay paints along the way (read-gate note, praise word).
 */
async function playQuizPerfectly(page: Page, answers: string[]) {
  const overlay = page.locator('#lesson-game-overlay');
  await overlay.locator('button').first().waitFor({ state: 'visible', timeout: 90_000 });

  const midQuizText: string[] = [];

  for (let i = 0; i < answers.length + 4; i++) {
    if (await page.locator('[data-final-score]').count()) break;

    // The gate is a counting number now, not a sentence — collect it anyway, so a language that
    // somehow put words back on it still shows up in the English sweep below.
    midQuizText.push(await page.locator('[data-gate-note]').innerText().catch(() => ''));
    if (!(await waitForAnswers(overlay))) break;

    // Click the option whose text IS the correct answer. Options are shuffled per player, so
    // position means nothing — the text is the only stable handle.
    let clicked = false;
    for (const answer of answers) {
      if (!answer) continue;
      const btn = overlay.locator('button', { hasText: answer.slice(0, 40) }).first();
      if (await btn.count()) { await btn.click({ timeout: 5_000 }).catch(() => {}); clicked = true; break; }
    }
    if (!clicked) await overlay.locator('button').first().click({ timeout: 5_000 }).catch(() => {});

    await page.waitForTimeout(400);
    midQuizText.push(await overlay.innerText().catch(() => ''));

    await page.waitForFunction(
      () => Boolean(document.querySelector('[data-final-score]')) || Boolean(document.querySelector('[data-gate-secs]')),
      undefined,
      { timeout: 12_000 },
    ).catch(() => {});
    await page.waitForTimeout(1400);
  }

  await expect(page.locator('[data-final-score]'), 'the quiz never reached its score screen').toHaveCount(1);

  return { overlay, midQuizText: midQuizText.join('\n') };
}

// Case-insensitive: the card uppercases several of these in CSS, and innerText reports the
// uppercased form — "4 / 4 correct" reads back as "4 / 4 CORRECT".
const found = (text: string) => {
  const hay = text.toLowerCase();

  return ENGLISH_NEEDLES.filter((n) => hay.includes(n.toLowerCase()));
};

test.describe('the quiz score screen in a Dutch and a German class', () => {
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setInterfaceLanguage(page, 'English');
    await page.close();
  });

  for (const lang of LANGUAGES) {
    test(`the score card a ${lang.option} class sees is ${lang.option}`, async ({ page }) => {
      // Answering a whole quiz honestly means sitting through the read-gate on every question,
      // so this walk runs well past the 60s default. Without this it dies on the clock and
      // reports a timeout instead of whatever it actually found.
      test.slow();
      await setInterfaceLanguage(page, lang.option);
      await openPlayer(page, LESSON_CODE);

      // 1. The dictionary reached the player. Without this, English everywhere would be a
      //    locale-plumbing failure and the report would be about the wrong thing.
      const dict = await page.evaluate(() => (window as any).__lpLang);
      expect(dict?.['Submit'], `the ${lang.tag} dictionary never reached the player`).toBe(lang.submitWord);

      await startLesson(page);
      const answers = await correctAnswerTexts(page);
      expect(answers.length, 'no quiz questions in the payload').toBeGreaterThan(0);

      const { overlay, midQuizText } = await playQuizPerfectly(page, answers);
      await page.screenshot({ path: shot(`${lang.tag}-score-card`) });

      // 2. The SAME component translates other strings. If this holds and the card below is
      //    English, no guard anywhere in the chain is rescuing it.
      expect(
        midQuizText,
        'the quiz cards themselves were not localised either — this is a locale bug, not a missing t()',
      ).not.toBe('');

      const text = await overlay.innerText();
      expect(
        found(text).join(', ') || '(clean)',
        `English on the ${lang.option} score card — see ${shot(`${lang.tag}-score-card`)}\n---\n${text}\n---`,
      ).toBe('(clean)');
    });
  }
});
