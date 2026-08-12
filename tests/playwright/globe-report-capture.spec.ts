import { test } from '@playwright/test';
import { writeFileSync } from 'node:fs';

/**
 * Dumps the measurement harness's whole report to a file, so a change can be shown as a DIFF of
 * numbers rather than argued from memory.
 *
 * Not a test — it asserts nothing. It exists because "prove the globe view is unchanged" is a
 * before-and-after question, and the only honest answer to it is two files and a diff.
 *
 *   HARNESS_URL=http://localhost:5204/ REPORT_OUT=/tmp/before.json \
 *     npx playwright test globe-report.capture
 */
const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5198/';
const OUT = process.env.REPORT_OUT;

// Opt-in. It has to carry a .spec name to be discovered at all, but it asserts nothing, so leaving
// it live in the suite would spend three minutes of every run producing a file nobody asked for.
test.skip(!OUT, 'set REPORT_OUT to capture');

test('capture the harness report', async ({ browser }) => {
  test.setTimeout(240_000);
  const page = await browser.newPage({ viewport: { width: 900, height: 700 } });
  const failures: string[] = [];
  page.on('pageerror', (error) => failures.push(error.message));

  await page.goto(HARNESS, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () => (window as any).__measured === true || !!(window as any).__harnessError,
    null,
    { timeout: 180_000 },
  );

  const error = await page.evaluate(() => (window as any).__harnessError);
  if (error) throw new Error(`harness failed: ${error}`);

  const report = await page.evaluate(() => (window as any).__report);
  const cloudRepeat = await page.evaluate(() => (window as any).__cloudRepeat());
  writeFileSync(OUT!, JSON.stringify({ ...report, cloudRepeat }, null, 2));
  if (failures.length) console.log('page errors:', failures.join('\n'));
  console.log(`report written to ${OUT}`);
});
