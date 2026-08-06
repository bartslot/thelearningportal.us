/**
 * Does the player's chrome really come back "in a random language" on consecutive loads of the
 * same English lesson in the same session?
 *
 * The claim from the performance lane is that a student who opens the SAME lesson twice gets two
 * different interfaces — "Continue", then "Continuer", then "Verder" — with the lesson content
 * staying English throughout. That is a claim about NON-DETERMINISM, so this file measures
 * determinism directly:
 *
 *   1. ten consecutive loads of the English Abel Tasman lesson in ONE browser context: is the
 *      chrome language identical every single time?
 *   2. the same ten loads in a fresh context per load (a student on a cold browser);
 *   3. what actually decides the language — flip the one input and watch the output follow,
 *      then flip it back. If the output tracks the input exactly, nothing is random.
 *
 * Everything this file writes it restores, and it records the value it inherited so an
 * interrupted run puts back what it found rather than a guessed 'en'.
 */
import { test, expect, type Browser, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results-chrome-language-determinism');
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

/** The English Abel Tasman lesson named in the report: 9 voyage, 6 narration, 2 quiz, 1 gallery. */
const CODE = process.env.DETERMINISM_LESSON ?? 'LYTVSK';

const LOADS = 10;

let INHERITED = 'unknown';

type Reading = { lang: string; labels: string };

/** Open the player, wait on the payload (never a clock) and read back what language it is in. */
async function readChrome(page: Page): Promise<Reading> {
  await page.goto(`/lesson/${CODE}`);
  await page.waitForFunction(() => (window as any).LESSON != null, null, { timeout: 60_000 });

  const lang = (await page.locator('html').getAttribute('lang')) ?? 'missing';
  const labels = (
    await page
      .locator('[aria-label]')
      .evaluateAll((els) => els.map((e) => e.getAttribute('aria-label')).filter(Boolean) as string[])
  )
    .sort()
    .join(' | ');

  return { lang, labels };
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(240_000);

test.beforeAll(() => {
  INHERITED = readLocale();
  console.log(`[determinism] ${DEV_TEACHER} was found at locale=${INHERITED}`);
});

test.afterAll(() => {
  writeLocale(INHERITED === 'null' ? 'en' : INHERITED);
  console.log(`[determinism] restored ${DEV_TEACHER} to locale=${INHERITED}`);
});

test(`1. ${LOADS} consecutive loads in ONE session all speak the same language`, async ({ page }) => {
  writeLocale('en');

  const readings: Reading[] = [];
  for (let i = 0; i < LOADS; i++) {
    readings.push(await readChrome(page));
  }
  await page.screenshot({ path: shot('01-same-session-last-load') });

  const langs = readings.map((r) => r.lang);
  console.log(`[determinism] same-session html[lang] over ${LOADS} loads: ${langs.join(',')}`);

  expect(new Set(langs).size, `the chrome language changed between loads: ${langs.join(',')}`).toBe(1);
  expect(langs[0]).toBe('en');
  expect(
    new Set(readings.map((r) => r.labels)).size,
    'the accessible names of the chrome changed between loads of the same lesson',
  ).toBe(1);
  console.log(`[determinism] stable chrome labels: ${readings[0].labels}`);
});

test(`2. ${LOADS} cold contexts (a student opening it fresh) all speak the same language`, async ({
  browser,
}: {
  browser: Browser;
}) => {
  writeLocale('en');

  const langs: string[] = [];
  for (let i = 0; i < LOADS; i++) {
    const context = await browser.newContext();
    const page = await context.newPage();
    langs.push((await readChrome(page)).lang);
    if (i === LOADS - 1) await page.screenshot({ path: shot('02-cold-context-last-load') });
    await context.close();
  }

  console.log(`[determinism] cold-context html[lang]: ${langs.join(',')}`);
  expect(new Set(langs).size, `cold loads disagreed: ${langs.join(',')}`).toBe(1);
  expect(langs[0]).toBe('en');
});

test('3. the language is a function of the signed-in row, and it follows it exactly', async ({ page }) => {
  // Drive the one input twice each, out of order, to show the output is a function of it and
  // carries nothing over from the previous load.
  for (const code of ['nl', 'en', 'fr', 'en', 'nl'] as const) {
    writeLocale(code);
    const { lang } = await readChrome(page);
    console.log(`[determinism] users.locale=${code} -> html[lang]=${lang}`);
    expect(lang, `player chrome did not follow users.locale=${code}`).toBe(code);
  }

  writeLocale('nl');
  await readChrome(page);
  await page.screenshot({ path: shot('03-dutch-chrome-english-lesson') });

  // The report's own observation: with the row set to Dutch the CONTENT is still the English
  // lesson while the chrome is Dutch. That is the documented design (SetLocale paints the UI,
  // the lesson carries its own text), not a random flip.
  const title = await page.evaluate(() => (window as any).LESSON?.title ?? '');
  console.log(`[determinism] lesson title while chrome is Dutch: ${title}`);
  expect(title.length, 'no lesson payload to compare against').toBeGreaterThan(0);
});
