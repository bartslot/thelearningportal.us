/**
 * Does the dev auto-login teacher's stored UI language leak between test runs?
 *
 * The claim under test: every spec in this suite is signed in as the SAME row —
 * AutoLoginDev picks users.email = teacher@example.com when APP_USER_ROLE=teacher — and
 * App\Http\Middleware\SetLocale paints every page in that row's `locale`. Two specs write
 * that column (five-language-ui.spec.ts through the real settings screen,
 * game-screen-language.spec.ts through tinker). If either one's restore never runs, the
 * column stays foreign and any OTHER spec that matches a control by its English label
 * points at a working player and times out.
 *
 * This spec does not trust that story. It measures three things:
 *
 *   1. what the column says RIGHT NOW, before any test in this file has touched anything —
 *      i.e. whether a previous run actually left residue in the shared database;
 *   2. that the student player's chrome really does follow that column (the coupling);
 *   3. that with the column set to a foreign language, a byname lookup for an English
 *      control finds nothing — the exact failure the claim describes.
 *
 * Everything this file changes it changes back, and it records the value it found on the
 * way in so an interrupted run restores the state it inherited, not a guessed 'en'.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results-autologin-locale');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

const artisan = (php: string) =>
  execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });

const DEV_TEACHER = 'teacher@example.com';

const readLocale = (): string => {
  const out = artisan(
    `$u = \\App\\Models\\User::where('email','${DEV_TEACHER}')->first(); echo 'LOC='.($u?->locale ?? 'null');`,
  );
  return (/LOC=(\S+)/.exec(out)?.[1] ?? 'null').trim();
};

const writeLocale = (code: string) => {
  artisan(
    `$u = \\App\\Models\\User::where('email','${DEV_TEACHER}')->first(); $u->locale='${code}'; $u->save(); echo 'OK';`,
  );
};

/** A published lesson any student can open. Anne Frank — narration, map, gallery, video, quiz. */
const CODE = process.env.LOCALE_BLEED_LESSON ?? 'CF0AVG';

/** The locale this file inherited. Restored in afterAll so the suite is left as it was found. */
let INHERITED = 'unknown';

/** Open the player and wait on the payload, never on a clock. */
async function openPlayer(page: Page) {
  await page.goto(`/lesson/${CODE}`);
  await page.waitForFunction(() => (window as any).LESSON != null, null, { timeout: 60_000 });
}

/**
 * The transport deck auto-hides, and getByRole never matches a hidden control. Nudging the
 * bottom edge is what a student does with the mouse, so it is what the test does too.
 */
async function wakeDeck(page: Page) {
  const size = page.viewportSize() ?? { width: 1280, height: 720 };
  await page.mouse.move(size.width / 2, size.height - 40);
  await page.mouse.move(size.width / 2, size.height - 50);
  await page.mouse.move(size.width / 2, size.height - 60);
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(120_000);

test.beforeAll(() => {
  INHERITED = readLocale();
  console.log(`[locale-bleed] ${DEV_TEACHER} was found at locale=${INHERITED}`);
});

test.afterAll(() => {
  writeLocale(INHERITED === 'null' ? 'en' : INHERITED);
  console.log(`[locale-bleed] restored ${DEV_TEACHER} to locale=${INHERITED}`);
});

test('1. coupling: the STUDENT player chrome is painted in the teacher row\'s language', async ({ page }) => {
  writeLocale('de');
  await openPlayer(page);
  await page.screenshot({ path: shot('01-student-player-de') });

  await expect(
    page.locator('html'),
    'the student player did not follow the signed-in dev teacher\'s stored locale',
  ).toHaveAttribute('lang', 'de');

  const labels = (
    await page.locator('[aria-label]').evaluateAll((els) =>
      els.map((e) => e.getAttribute('aria-label')).filter(Boolean) as string[],
    )
  ).join(' | ');
  console.log(`[locale-bleed] de aria-labels: ${labels}`);

  expect(labels, 'German chrome should name the chapter list in German').toMatch(/Kapitel/i);
});

test('2. the failure: an English by-name lookup finds nothing on that same working player', async ({ page }) => {
  writeLocale('de');
  await openPlayer(page);

  // Match the control the way a spec written in English matches it — by its accessible name.
  // Attribute locators are used rather than getByRole because the transport deck is hidden
  // until the lesson starts, and role queries skip hidden nodes for BOTH languages, which
  // would make the comparison meaningless.
  const english = page.locator('[aria-label="Chapters"]');
  const german = page.locator('[aria-label="Kapitel"]');

  await page.screenshot({ path: shot('02-english-lookup-misses') });

  // The control EXISTS and works — it just answers to a different name.
  expect(await german.count(), 'the chapter control should be on the page, in German').toBeGreaterThan(0);
  expect(
    await english.count(),
    'an English name lookup still matched — the bleed would be harmless',
  ).toBe(0);

  // And the same for the caption toggle, so this is not one unlucky string.
  expect(await page.locator('[aria-label="Untertitel"]').count()).toBeGreaterThan(0);
  expect(await page.locator('[aria-label="Subtitles"]').count()).toBe(0);
});

test('3. and it is back to English the moment the column is', async ({ page }) => {
  writeLocale('en');
  await openPlayer(page);
  await wakeDeck(page);
  await page.screenshot({ path: shot('03-student-player-en') });

  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  expect(await page.locator('[aria-label="Chapters"]').count()).toBeGreaterThan(0);
  const labels = (
    await page.locator('[aria-label]').evaluateAll((els) =>
      els.map((e) => e.getAttribute('aria-label')).filter(Boolean) as string[],
    )
  ).join(' | ');
  expect(labels, 'English chrome should name the chapter list in English').toMatch(/Chapters/);
});

test('4. residue: the shared dev teacher row is carrying a language nobody in this run chose', async () => {
  // This is not an assertion about correct behaviour — it is a reading of the shared state a
  // fresh spec would inherit. It is reported either way; only a foreign value is a finding.
  const carried = INHERITED;
  console.log(`[locale-bleed] inherited locale = ${carried}`);

  expect(
    carried,
    `the auto-login teacher was left at locale="${carried}" by an earlier run — every spec that ` +
      `matches a control by its English label will now look at a ${carried} page`,
  ).toBe('en');
});
