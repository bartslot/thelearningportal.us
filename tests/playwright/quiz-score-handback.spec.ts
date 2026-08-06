/**
 * What happens to a class AFTER the last quiz question, in the real student player.
 *
 * A separate lane reported that the score screen "never counts down", parking the class on a
 * leaderboard form with no way onward but a button — the exact dead end the code comment at
 * QuizOverlay.js:46 says the design wanted to avoid. This spec exists to REFUTE that, by driving
 * a published lesson at /lesson/{CODE} the way a class would and watching both exits:
 *
 *   1. Skip ›     — the student declines the leaderboard; the lesson must resume.
 *   2. Submit     — the student joins; the leaderboard must then count down and hand the lesson
 *                   back BY ITSELF, with nobody touching the machine.
 *
 * If (2) holds, the countdown is plainly reachable from the student player and the report's
 * central claim ("reachable only from the wizard preview") is false.
 *
 * The score POST is intercepted at the network layer — no app code is touched and no real score
 * is written. Nothing here asserts on CSS motion, so the default project is fine; the page is
 * never hidden, so rAF (the score count-up) and the setInterval countdown both run.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results-quiz-score-handback');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** Published lesson whose FIRST scene is a quiz — the shortest honest route to a score screen. */
const CODE = 'V6OYLL';

/** The countdown the design promises, plus the slack a 1s interval needs to be observed ticking. */
const AUTO_CONTINUE_SECONDS = 5;

async function openPlayer(page: Page) {
  await page.goto(`/lesson/${CODE}`);
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const phase = root?._x_dataStack?.[0]?.phase;
    return Boolean(phase) && phase !== 'LOADING';
  }, undefined, { timeout: 45_000 });
}

const phaseOf = (page: Page) =>
  page.evaluate(() => (document.querySelector('[x-data]') as any)?._x_dataStack?.[0]?.phase ?? null);

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
    const gate = page.locator('[data-gate-secs]');
    if (await gate.count()) await expect(gate).toHaveCount(0, { timeout: 20_000 });
    const answers = overlay.locator('button').filter({ hasNotText: /^$/ });
    if (!(await answers.count())) break;
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

test.describe('after the last quiz question, in the student player', () => {
  // Answering a whole quiz honestly (read-gate, praise, explanation) costs more than the
  // default 60s wall clock — the wait is the app's pacing, not a hang.
  test.setTimeout(150_000);

  test('the score screen offers a real choice, and Skip hands the lesson straight back', async ({ page }) => {
    await openPlayer(page);
    await startLesson(page);
    const overlay = await playQuizToTheEnd(page);

    // This much of the report is true and by design: with a leaderboard to join, the card asks
    // for a nickname instead of counting down. There is something to decide.
    await expect(overlay.locator('[data-nickname]')).toBeVisible();
    await expect(overlay.locator('[data-countdown]')).toHaveCount(0);
    await page.screenshot({ path: shot('01-score-screen') });

    // The claim under test is that this is a DEAD END. Decline the leaderboard and see.
    await overlay.getByText(/Skip/).click();
    await page.waitForFunction(
      () => !document.querySelector('[data-final-score]'),
      undefined,
      { timeout: 10_000 },
    );
    expect(await phaseOf(page), 'Skip left the player stuck outside a playing phase')
      .not.toBe('LOADING');
    await expect(page.locator('#lesson-game-overlay [data-nickname]')).toHaveCount(0);
    await page.screenshot({ path: shot('02-after-skip') });
  });

  test('joining the leaderboard ends in a countdown that resumes the lesson unattended', async ({ page }) => {
    // Server-shaped response; nothing is written to the database.
    await page.route('**/quiz-score*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          top: [{ nickname: 'Amélie', score: 900 }, { nickname: 'Baptiste', score: 800 }],
          players: 7,
          rank: 3,
        }),
      });
    });

    await openPlayer(page);
    await startLesson(page);
    const overlay = await playQuizToTheEnd(page);

    await overlay.locator('[data-nickname]').fill('Bart');
    await overlay.locator('[data-submit]').click();

    // The countdown the report says a student can never reach.
    const countdown = overlay.locator('[data-countdown]');
    await expect(countdown).toBeVisible({ timeout: 15_000 });
    await expect(countdown).toContainText(String(AUTO_CONTINUE_SECONDS));
    await page.screenshot({ path: shot('03-leaderboard-countdown') });

    // It must actually tick, not merely print a 5.
    await expect(countdown).toContainText(/\b[1-4]\b/, { timeout: 4_000 });

    // And then hand the lesson back with nobody pressing anything.
    await page.waitForFunction(
      () => !document.querySelector('[data-countdown]') && !document.querySelector('[data-final-score]'),
      undefined,
      { timeout: (AUTO_CONTINUE_SECONDS + 6) * 1000 },
    );
    await page.screenshot({ path: shot('04-resumed-unattended') });
    expect(await phaseOf(page), 'the lesson did not resume after the countdown').not.toBe('LOADING');
  });
});
