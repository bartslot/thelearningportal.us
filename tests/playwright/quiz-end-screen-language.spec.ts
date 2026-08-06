/**
 * The quiz END SCREEN, in a French classroom.
 *
 * A separate lane reported that the card a class stares at after a quiz — stars, score, the
 * leaderboard invitation — is half English even when everything around it is French. This spec
 * exists to REFUTE that: it drives the published French lesson as a class would see it projected,
 * plays the opening quiz to the end, and reads the text actually painted on the glass.
 *
 * It also carries the follow-on screen the report only inferred: submitting a nickname. The POST
 * is intercepted at the network layer (no app code is touched, and no real score is written) so
 * the leaderboard card renders from a normal server-shaped response and can be read too.
 *
 * Nothing here asserts on motion, so the default SwiftShader project is fine; the page is never
 * hidden, so rAF and the score count-up run.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { waitForAnswers } from './support/quiz';

const SHOTS = path.resolve('tests/playwright/results/quiz-end-screen-language');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** The published French lesson. Its first scene is a "what do you already know?" quiz. */
const FRENCH_CODE = 'V6OYLL';

/**
 * English a French class must never be shown. Whole phrases only — a bare word like "Quiz"
 * appears in the lesson's own French copy and matching it would report the content as a bug.
 */
const ENGLISH_NEEDLES = [
  'Join the leaderboard',
  'Lesson starts in',
  'Skip ›',
  'Leaderboard',
  'No scores yet',
  'keep climbing',
  ' · you',
  'players',
  'Submit',
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
    .catch(async () => { await page.getByText('Start lesson', { exact: false }).first().click(); });
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as any;
    return ['INTRO', 'WELCOME_VIDEO', 'GAME_BRIEF', 'GAME_ACTIVE'].includes(root?._x_dataStack?.[0]?.phase);
  }, undefined, { timeout: 60_000 });
}

/** Answer every question until the score card appears. Waits on the gate, never on a clock. */
async function playQuizToTheEnd(page: Page) {
  const overlay = page.locator('#lesson-game-overlay');
  await overlay.locator('button').first().waitFor({ state: 'visible', timeout: 90_000 });

  for (let i = 0; i < 16; i++) {
    if (await page.locator('[data-final-score]').count()) break;
    const answers = await waitForAnswers(overlay);
    if (!answers) break;
    await answers.first().click({ timeout: 5_000 }).catch(() => {});
    await page.waitForFunction(
      () => Boolean(document.querySelector('[data-final-score]')) || !document.querySelector('[data-choice-locked]'),
      undefined,
      { timeout: 15_000 },
    ).catch(() => {});
    await page.waitForTimeout(1200);
  }

  await expect(page.locator('[data-final-score]'), 'the quiz never reached its score screen').toHaveCount(1);
  return overlay;
}

const found = (text: string) => ENGLISH_NEEDLES.filter((n) => text.includes(n));

test.describe('the quiz end screen in a French class', () => {
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await setInterfaceLanguage(page, 'English');
    await page.close();
  });

  test('the score card a French class sees is French', async ({ page }) => {
    // A full quiz walk got longer when the read-gate became animation-led: the answers now
    // arrive one bar at a time, so every question costs a few seconds more
    // (resources/js/scene/QuizOverlay.js). Without this the run dies on the 60s default and
    // reports a timeout instead of whatever it actually found.
    test.slow();
    await setInterfaceLanguage(page, 'Français');
    await openPlayer(page, FRENCH_CODE);
    await startLesson(page);

    const overlay = await playQuizToTheEnd(page);
    await page.screenshot({ path: shot('fr-score-card') });

    const text = await overlay.innerText();
    // The gate that precedes it IS translated — proof the dictionary reached this page, so any
    // English below is the card's own, not a missing locale.
    const dict = await page.evaluate(() => (window as any).__lpLang);
    expect(dict?.['Submit'], 'the French dictionary never reached the player').not.toBe('Submit');

    expect(
      found(text).join(', ') || text,
      `English on the French score card — see ${shot('fr-score-card')}\n---\n${text}\n---`,
    ).toBe('');
  });

  test('the leaderboard card after a submit is French', async ({ page }) => {
    // A full quiz walk got longer when the read-gate became animation-led: the answers now
    // arrive one bar at a time, so every question costs a few seconds more
    // (resources/js/scene/QuizOverlay.js). Without this the run dies on the 60s default and
    // reports a timeout instead of whatever it actually found.
    test.slow();
    await setInterfaceLanguage(page, 'Français');

    // Answer the POST ourselves: a real, server-shaped leaderboard, no score written to the
    // database, and a rank OUTSIDE the top so the "keep climbing" line renders too.
    await page.route('**/quiz-score*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          top: [
            { nickname: 'Amélie', score: 900 },
            { nickname: 'Baptiste', score: 800 },
            { nickname: 'Chloé', score: 700 },
          ],
          players: 12,
          rank: 4,
        }),
      });
    });

    await openPlayer(page, FRENCH_CODE);
    await startLesson(page);
    const overlay = await playQuizToTheEnd(page);

    await overlay.locator('[data-nickname]').fill('Élodie');
    await overlay.locator('[data-submit]').click();
    await expect(overlay.locator('[data-countdown]')).toBeVisible({ timeout: 15_000 });
    await page.screenshot({ path: shot('fr-leaderboard-card') });

    const text = await overlay.innerText();
    expect(
      found(text).join(', ') || text,
      `English on the French leaderboard — see ${shot('fr-leaderboard-card')}\n---\n${text}\n---`,
    ).toBe('');
  });
});
