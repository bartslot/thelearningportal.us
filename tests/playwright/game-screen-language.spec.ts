/**
 * Are the player's GAME screens translated?
 *
 * A game scene set to Strategy or Debate (the wizard's game-scene inspector offers all three:
 * Quiz | Strategy | Debate) does NOT go through the quiz card overlay. `_afterSceneAudio` sends it
 * to _beginGameFlow → GAME_BRIEF → GAME_ACTIVE → TIME_UP, a set of full-screen panels rendered by
 * resources/views/lesson/player.blade.php.
 *
 * That same Blade file IS translated elsewhere — Skip, Chapters, Subtitles, Play/Pause all go
 * through __() — so this asks a narrow question: when the interface language is Dutch, do the game
 * panels speak Dutch too?
 *
 * The fixture provisions itself: a throwaway CHILD of the Anne Frank lesson
 * (php artisan lessons:test-copy 3), with its first game scene switched from Quiz to Strategy.
 * Both are ordinary teacher edits in the scene inspector. Nothing published is touched, and the
 * signed-in dev teacher's UI language is set to Dutch for the run and put back afterwards.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const artisan = (args: string[]) =>
  execFileSync('php', ['artisan', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

let CODE = '';
let SCENE = 0;

test.use({ locale: 'nl-NL' });
test.setTimeout(120_000);

test.beforeAll(() => {
  const out = artisan(['lessons:test-copy', '3', '--json']).trim().split('\n').filter(Boolean).pop()!;
  const child = JSON.parse(out) as { id: number; code: string };
  CODE = child.code;

  // Teacher edits: the game scene becomes a Strategy game, and the teacher's UI language is Dutch.
  const info = artisan(['tinker', '--execute', `
    $l = \\App\\Models\\Lesson::find(${child.id});
    $s = $l->scenes()->where('kind', 'game')->orderBy('order')->first();
    $s->game_type = 'strategy'; $s->save();
    $i = $l->scenes()->orderBy('order')->pluck('id')->search($s->id);
    $u = \\App\\Models\\User::where('email', 'teacher@example.com')->first();
    $u->locale = 'nl'; $u->save();
    echo "IDX=".$i;
  `]);
  SCENE = Number(/IDX=(\d+)/.exec(info)![1]);
});

test.afterAll(() => {
  artisan(['tinker', '--execute', `
    $u = \\App\\Models\\User::where('email','teacher@example.com')->first();
    $u->locale = 'en'; $u->save(); echo 'restored';
  `]);
});

test.describe('player game screens, interface language Dutch', () => {
  test('the surrounding player chrome is Dutch', async ({ page }) => {
    await page.goto(`/lesson/${CODE}`);
    await page.waitForFunction(() => (window as any).LESSON != null);

    // Control. This proves the page really rendered in Dutch and that THIS Blade file does
    // translate — so anything English below is a gap, not a page nobody ever translated.
    await expect(page.locator('html')).toHaveAttribute('lang', 'nl');
    const html = await page.content();
    expect(html).toContain('Hoofdstukken');  // __('Chapters')  — transport deck
    expect(html).toContain('Ondertiteling'); // __('Subtitles') — transport deck
  });

  test('the strategy brief, the time-up screen and the intel drop are English', async ({ page }) => {
    await page.goto(`/lesson/${CODE}?scene=${SCENE}`);
    await page.waitForFunction(() => (window as any).LESSON != null);
    await expect(page.locator('html')).toHaveAttribute('lang', 'nl');

    // Confirm the fixture is a NON-quiz game scene, or the player would take the quiz-overlay
    // branch and this test would be watching the wrong screen.
    const kind = await page.evaluate(
      (i: number) => {
        const s = (window as any).LESSON?.scenes?.[i];
        return s ? `${s.kind}/${s.game_type}` : 'missing';
      },
      SCENE,
    );
    expect(kind).toBe('game/strategy');

    // A student presses Start on the title screen — a real gesture, so audio is allowed.
    // (The CTA reads "Start lesson" in every language; that string is covered by
    // player-start-cta-language.spec.ts and is not what this spec is about.)
    const start = page.getByRole('button', { name: 'Start lesson' });
    await expect(start).toBeVisible({ timeout: 60_000 });
    await start.click();

    // ── GAME_BRIEF, reached by the app's own path (scene audio ends → _beginGameFlow).
    await expect(page.getByText('Your Challenge')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: 'Begin the challenge' })).toBeVisible();
    await page.screenshot({ path: 'tests/playwright/results-game-language/game-brief-nl.png' });

    // ── GAME_ACTIVE → TIME_UP. The clock is 10 real minutes (game_duration_seconds defaults to
    // 600), so shorten it — the countdown, the expiry and the panel are all still the app's own.
    await page.getByRole('button', { name: 'Begin the challenge' }).click();
    await page.waitForFunction(() => {
      const el = document.querySelector('[x-data^="lessonGame"]') as any;

      return el?._x_dataStack?.[0]?.phase === 'GAME_ACTIVE';
    });
    await page.evaluate(() => {
      const el = document.querySelector('[x-data^="lessonGame"]') as any;
      const c = el?._x_dataStack?.[0];
      if (c) c.timerSeconds = 2;
    });

    await expect(page.getByText("TIME'S UP")).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText('Present your strategy to the class.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue the lesson' })).toBeVisible();
    await page.screenshot({ path: 'tests/playwright/results-game-language/time-up-nl.png' });

    // ── INTEL_DROP rides on an intel_drop script event; raise it through the component.
    await page.evaluate(() => {
      const el = document.querySelector('[x-data^="lessonGame"]') as any;
      const c = el?._x_dataStack?.[0];
      if (c) { c.intelDropMessage = 'Bericht van het front'; c.phase = 'INTEL_DROP'; }
    });
    await expect(page.getByText('New Intelligence')).toBeVisible({ timeout: 10_000 });
    await page.screenshot({ path: 'tests/playwright/results-game-language/intel-drop-nl.png' });
  });
});
