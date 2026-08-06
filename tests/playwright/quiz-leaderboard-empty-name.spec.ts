/**
 * THE LEADERBOARD JOIN FORM, PRESSED WITH AN EMPTY NAME.
 *
 * A report from the performance lane claims the join card at the end of the quiz is a dead end:
 *
 *   "Pressing Submit with the field empty is accepted: the card does not advance, no validation
 *    message appears, nothing changes. A student who does not notice the small 'Skip ›' underneath
 *    is stuck on a screen that responds to every press by doing nothing."
 *
 * Its evidence is a gateTrail of 222 consecutive "Versturen" presses over 150 s with no state
 * change, and a screenshot of the card with an empty "Ton nom…" field — so the run was in a
 * non-English interface. This spec exists to REFUTE the claim, and it reproduces that same state
 * deliberately: the interface language is set to French through the real settings screen, so the
 * card under test is the exact "Ton nom… / Envoyer" card in the report's screenshot.
 *
 * What a refutation has to show, beyond "it did not crash":
 *
 *   1. an empty press produces a VISIBLE message in the student's own language — not silence;
 *   2. the message survives repeated pressing (the report's 222 presses), i.e. it is not a flash
 *      that a rapid presser would never see;
 *   3. a one-letter name is refused with the same message (the rule is "at least 2 letters",
 *      so the boundary matters);
 *   4. the form is not a dead end — a real name submits and the leaderboard replaces the card.
 *
 * Only (4) proves the student is not stuck. Without it, a validation message would still leave
 * the report half right.
 *
 * Harness notes: nothing here asserts on MOTION, so the default SwiftShader project is correct
 * (the score count-up is rAF but its value is never asserted). The page is never hidden, so rAF
 * and the read-gate timers run normally. No MapLibre style is involved — the quiz is scene 1 of
 * the Silk Road, before any map. No lesson is authored: 4NA554 is published and narrated already.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { waitForAnswers } from './support/quiz';

const SHOTS = path.resolve('tests/playwright/results-quiz-leaderboard-empty-name');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** The Silk Road. Published, narrated, scene 1 is a quiz. */
const LESSON_CODE = '4NA554';

/** The language the report's screenshot was taken in. */
const LANGUAGE = { option: 'Français', tag: 'fr', submitWord: 'Envoyer' };

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
    const scenes = root?._x_dataStack?.[0]?.lesson?.scenes ?? [];
    const quiz = scenes.find((s: any) => Array.isArray(s.quiz_questions) && s.quiz_questions.length);

    return (quiz?.quiz_questions ?? []).map((q: any) => String((q.options ?? [])[Number(q.correct_index)] ?? ''));
  });
}

/** Answer every question, correctly where the payload lets us, until the score card appears. */
async function playQuizToScoreCard(page: Page, answers: string[]) {
  const overlay = page.locator('#lesson-game-overlay');
  await overlay.locator('button').first().waitFor({ state: 'visible', timeout: 90_000 });

  for (let i = 0; i < answers.length + 4; i++) {
    if (await page.locator('[data-final-score]').count()) break;

    if (!(await waitForAnswers(overlay))) break;

    // Options are shuffled per player, so text is the only stable handle.
    let clicked = false;
    for (const answer of answers) {
      if (!answer) continue;
      const btn = overlay.locator('button', { hasText: answer.slice(0, 40) }).first();
      if (await btn.count()) { await btn.click({ timeout: 5_000 }).catch(() => {}); clicked = true; break; }
    }
    if (!clicked) await overlay.locator('button').first().click({ timeout: 5_000 }).catch(() => {});

    await page.waitForFunction(
      () => Boolean(document.querySelector('[data-final-score]')) || Boolean(document.querySelector('[data-gate-secs]')),
      undefined,
      { timeout: 12_000 },
    ).catch(() => {});
    await page.waitForTimeout(1400);
  }

  await expect(page.locator('[data-final-score]'), 'the quiz never reached its score screen').toHaveCount(1);

  return overlay;
}

test.describe('the leaderboard join form at the end of the quiz', () => {
  test.slow();

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setInterfaceLanguage(page, 'English');
    await page.close();
  });

  test('an empty name is refused out loud, and a real name still gets in', async ({ page }) => {
    await setInterfaceLanguage(page, LANGUAGE.option);
    await openPlayer(page, LESSON_CODE);

    // The dictionary really reached the player — otherwise this is a locale bug, not this bug.
    const dict = await page.evaluate(() => (window as any).__lpLang);
    expect(dict?.['Submit'], `the ${LANGUAGE.tag} dictionary never reached the player`).toBe(LANGUAGE.submitWord);

    // A remembered nickname would pre-fill the field and make an "empty" press impossible.
    await page.evaluate(() => { try { localStorage.removeItem('lp_quiz_nickname'); } catch { /* private mode */ } });

    await startLesson(page);
    const answers = await correctAnswerTexts(page);
    expect(answers.length, 'no quiz questions in the payload').toBeGreaterThan(0);

    const overlay = await playQuizToScoreCard(page, answers);

    // The card the report describes: a name field and a Submit button.
    const join = overlay.locator('[data-join]');
    await expect(join, 'no leaderboard join card — this player has no submit URL').toHaveCount(1);
    const nameField = join.locator('[data-nickname]');
    const submit = join.locator('[data-submit]');
    const error = join.locator('[data-join-error]');
    await expect(nameField).toHaveValue('');
    await expect(error, 'the card started out already complaining').toHaveText('');
    await page.screenshot({ path: shot('1-join-card-empty') });

    // 1. ONE empty press. The report says nothing happens.
    await submit.click();
    await expect(
      error,
      `an empty press said nothing — see ${shot('2-after-empty-press')}`,
    ).not.toHaveText('', { timeout: 5_000 });
    const message = (await error.innerText()).trim();
    await page.screenshot({ path: shot('2-after-empty-press') });

    // It is the student's language, not a raw English fallback.
    expect(message, `the refusal was not in ${LANGUAGE.option}: "${message}"`).toContain('au moins 2 lettres');

    // 2. The report pressed 222 times over 150 s. A message that only flashes would be no better
    //    than silence, so press repeatedly and check it is still on screen at the end.
    for (let i = 0; i < 20; i++) await submit.click();
    await expect(error, 'the refusal vanished after repeated pressing').toHaveText(message);
    await expect(submit, 'Submit disabled itself on an empty press').toBeEnabled();
    await page.screenshot({ path: shot('3-after-20-more-presses') });

    // 3. The boundary: one letter is still not a name.
    await nameField.fill('A');
    await submit.click();
    await expect(error, 'a one-letter name was accepted').toHaveText(message);

    // 4. NOT a dead end. A real name submits and the leaderboard replaces the card.
    const nickname = `PW${Date.now().toString().slice(-6)}`;
    await nameField.fill(nickname);
    await submit.click();
    await expect(
      overlay.locator('[data-join]'),
      'a valid name never left the join card — the form really is a dead end',
    ).toHaveCount(0, { timeout: 20_000 });
    await expect(overlay).toContainText(nickname, { timeout: 10_000 });
    await page.screenshot({ path: shot('4-leaderboard-after-valid-name') });
  });
});
