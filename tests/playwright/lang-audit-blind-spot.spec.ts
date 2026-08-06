/**
 * The audit's blind spot, seen from a French student's screen.
 *
 * `php artisan lang:audit` reports every language at 100%. It counts `__()` calls, so a string
 * that was never wrapped in one is not "missing" — it is invisible. This spec puts the two
 * halves side by side in a real browser: the audit says nothing is missing, and the player's
 * title screen still says "Start lesson" to a browser asking for French.
 *
 * Nothing here edits application code. If someone wraps these strings later, the spec fails on
 * the "still English" assertions — which is the correct way for it to die.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const REPO = process.cwd();
const LESSON = process.env.PW_FRENCH_LESSON_CODE ?? 'V6OYLL';

function audit(): string {
  return execFileSync('php', ['artisan', 'lang:audit'], { cwd: REPO, encoding: 'utf8' });
}

test.describe('lang:audit blind spot', () => {
  test('the audit claims full coverage', async () => {
    const out = audit();
    expect(out).toContain('Every language covers the whole interface.');
    expect(out).toMatch(/Fran.ais \(fr\)\s*\|\s*\d+ \/ \d+\s*\|\s*0\s*\|\s*100%/);
  });

  test('a French browser still reads "Start lesson" on the player title screen', async ({ browser }) => {
    const ctx = await browser.newContext({ locale: 'fr-FR', extraHTTPHeaders: { 'Accept-Language': 'fr' } });
    const page = await ctx.newPage();
    await page.goto(`/lesson/${LESSON}`);

    // The page's translation pipeline is live — Blade handed the browser a dictionary.
    // (Which language it holds depends on the session: this dev host auto-logs in a teacher whose
    // stored locale other specs change. That is exactly why the assertion below is locale-blind.)
    const dict = await page.evaluate(() => (window as any).__lpLang ?? {});
    expect(Object.keys(dict).length).toBeGreaterThan(10);
    console.log(`[blind spot] page lang=${await page.evaluate(() => document.documentElement.lang)} dict['Quiz paused']=${dict['Quiz paused']}`);

    // …and the call to action next to it is English, in every language, always.
    const cta = page.getByRole('button', { name: /Start lesson/ });
    await expect(cta).toBeVisible({ timeout: 30_000 });

    await page.screenshot({
      path: 'tests/playwright/results-lang-blindspot/fr-title-screen.png',
      fullPage: false,
    });

    // The audit cannot see it, because no translation key exists for it at all.
    for (const lang of ['fr', 'nl', 'de', 'it']) {
      const json = JSON.parse(readFileSync(path.join(REPO, 'lang', `${lang}.json`), 'utf8'));
      expect(Object.keys(json)).not.toContain('Start lesson');
    }

    await ctx.close();
  });

  /**
   * This one used to assert the blind spot: the quiz's score card rendered "Join the leaderboard"
   * and "Leaderboard" as English literals, they reached no dictionary, and the audit counted none
   * of them. That hole is closed, so per the note at the top of this file the old assertions have
   * died the correct way and this is what replaces them — the guard that keeps them closed.
   *
   * Two conditions, because the strings need both to be worth anything:
   *
   *   1. the browser was handed a translation, so the card can render in the class's language, and
   *   2. the audit COUNTS them, so a language falling behind fails the build.
   *
   * (2) is the one that quietly broke before. App\Support\JsTranslations used to loop over an
   * array and translate each entry, which the extractor cannot see — the strings were translated
   * but invisible, reported as UNUSED, and one `lang:audit --prune` from being deleted outright.
   */
  test('quiz-overlay chrome the JS writes is inside the dictionary, and inside the audit', async ({ browser }) => {
    const ctx = await browser.newContext({ locale: 'fr-FR', extraHTTPHeaders: { 'Accept-Language': 'fr' } });
    const page = await ctx.newPage();
    await page.goto(`/lesson/${LESSON}`);

    const dict = await page.evaluate(() => (window as any).__lpLang ?? {});
    const counted = JSON.parse(
      execFileSync('php', ['artisan', 'lang:audit', '--json'], { cwd: REPO, encoding: 'utf8' }),
    );

    for (const s of ['Join the leaderboard', 'Leaderboard', 'Lesson starts in :count']) {
      expect(Object.keys(dict), `"${s}" never reached the browser`).toContain(s);
      expect(
        counted.fr?.missing ?? [],
        `"${s}" is missing from French — the audit should be failing`,
      ).not.toContain(s);
    }

    // Counted, not merely present: a string the extractor cannot see is reported as UNUSED, and
    // that is exactly the state --prune deletes.
    for (const code of ['nl', 'de', 'fr', 'it']) {
      expect(
        counted[code]?.unused ?? [],
        `the audit cannot see the quiz's JS strings in ${code} — --prune would delete them`,
      ).not.toContain('Join the leaderboard');
    }

    await ctx.close();
  });
});
