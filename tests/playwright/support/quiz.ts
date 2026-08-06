import { expect, type Locator } from '@playwright/test';

/**
 * Waiting for the quiz card to let a student answer.
 *
 * Five specs used to spell this the same way: watch `[data-gate-secs]`, and once the countdown is
 * gone, click. That stopped being true when the read-gate became animation-led — the number leaves
 * a few seconds before the answers do, because the bars covering them lift one at a time after it
 * (resources/js/scene/QuizOverlay.js). A spec that clicked on the number's disappearance was
 * clicking a disabled button and only passing because the failure was swallowed.
 *
 * So ask the card the question that actually matters: is there an answer a student could press?
 */

/** Every answer that is currently pressable. Empty while the gate is shut. */
export const pressableAnswers = (overlay: Locator): Locator => overlay.locator('[data-opt]:not([disabled])');

/**
 * Block until the card would take an answer, and hand back the pressable ones.
 *
 * Returns null when none ever arrive — the quiz has moved on to its score screen, or this was
 * never a question card. Callers loop over questions, so "no answers" is an exit, not a failure.
 */
export async function waitForAnswers(overlay: Locator, timeout = 25_000): Promise<Locator | null> {
  const answers = pressableAnswers(overlay);
  try {
    await expect(answers.first()).toBeVisible({ timeout });
  } catch {
    return null;
  }
  return answers;
}
