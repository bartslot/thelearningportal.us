/**
 * The Fit row of the video inspector, read in three languages.
 *
 * A finding from the video-scene lane says the framing control labels itself with the name of one
 * of its own options: the row reads "Fit  [Cover | Fit]" in English, "Passend  [Vullend | Passend]"
 * in Dutch, "Einpassen  [Füllend | Einpassen]" in German. This spec refuses to take that from the
 * template and goes and looks, as a teacher would: it opens a throwaway copy of a real published
 * lesson (#3 Anne Frank, which owns a video scene with a YouTube embed already set), clicks the
 * video tile in the rail, and reads the text actually painted in the inspector.
 *
 * What it asserts, per language:
 *   - the row exists and carries two option buttons,
 *   - the row's label is NOT the same word as either option.
 *
 * The last one is the finding. If the label and an option really do collide, the assertion fails
 * and names the collision. Screenshots (evidence either way) land in
 * tests/playwright/results/video-fit-label/.
 *
 * No motion, no map, no WebGL assertion here — the default chromium project is fine. The wizard
 * canvas does boot three.js under SwiftShader, hence the long mount wait.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results/video-fit-label');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** Anne Frank — 1 video scene among 24. */
const PARENT = 3;

// The wizard canvas boots three.js under SwiftShader; the config's 60s default is not enough to
// mount it, sign in through settings, and read the inspector in one test.
test.setTimeout(180_000);

let lesson: { id: number; code: string; video: number };

test.beforeAll(() => {
  const out = execFileSync('php', ['artisan', 'lessons:test-copy', String(PARENT), '--json'], { encoding: 'utf8' });
  const child = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!) as { id: number; code: string };

  // Ask the database which scene is the video one rather than hardcoding an id that only ever
  // existed in one copy.
  const sceneId = execFileSync('php', [
    'artisan', 'tinker', '--execute',
    `echo App\\Models\\Scene::where('lesson_id', ${child.id})->where('kind', 'video')->value('id');`,
  ], { encoding: 'utf8' }).trim().split('\n').pop()!.trim();

  expect(Number(sceneId), `the copy of #${PARENT} has no video scene`).toBeGreaterThan(0);
  lesson = { id: child.id, code: child.code, video: Number(sceneId) };
  // eslint-disable-next-line no-console
  console.log(`[fit] editing a fresh copy of #${PARENT} → #${lesson.id} ${lesson.code}, video scene ${lesson.video}`);
});

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

/** Open the scene editor with the video scene on stage, inspector showing. */
async function openVideoScene(page: Page) {
  await page.goto(`/teacher/lessons/${lesson.id}/wizard?step=4`);

  const tile = page.locator(`[data-scene-id="${lesson.video}"]`);
  await tile.waitFor({ state: 'visible', timeout: 60_000 });

  // Selecting a scene is a Livewire round-trip, and the rail re-renders under the click while the
  // three.js stage is still booting. Retry against the visible state — the framing row only exists
  // once the inspector is showing a video scene with an embed — rather than against a clock.
  for (let attempt = 0; attempt < 8; attempt++) {
    await tile.click({ timeout: 10_000 }).catch(() => {});
    const showing = await page
      .waitForFunction(() => document.querySelectorAll('[data-fit-option]').length >= 2
        || Array.from(document.querySelectorAll('button')).filter((b) =>
          /fit\s*=\s*'(cover|fit)'/.test(b.getAttribute('@click') ?? b.getAttribute('x-on:click') ?? '')).length >= 2,
        null, { timeout: 8_000 })
      .then(() => true).catch(() => false);
    if (showing) return;
  }
  throw new Error('the video inspector never showed its framing row after selecting the video scene');
}

/**
 * The framing row as a teacher reads it: the label on the left, the two buttons on the right.
 *
 * Located structurally, from the option buttons outwards, so the spec never has to know what the
 * label says in the language under test — which is the whole point.
 */
async function fitRow(page: Page) {
  return page.evaluate(() => {
    // The two framing buttons are the only pair of buttons whose click handler sets `fit`.
    const buttons = Array.from(document.querySelectorAll<HTMLButtonElement>('button'))
      .filter((b) => /fit\s*=\s*'(cover|fit)'/.test(b.getAttribute('@click') ?? b.getAttribute('x-on:click') ?? ''));
    if (buttons.length < 2) return null;

    const group = buttons[0].parentElement!;
    const row = group.parentElement!;
    const label = Array.from(row.children).find((el) => el !== group) as HTMLElement | undefined;

    return {
      label: (label?.innerText ?? '').trim(),
      options: buttons.map((b) => b.innerText.trim()),
      rowText: (row as HTMLElement).innerText.replace(/\s+/g, ' ').trim(),
    };
  });
}

for (const { name, language } of [
  { name: 'english', language: 'English' },
  { name: 'dutch', language: 'Nederlands' },
  { name: 'german', language: 'Deutsch' },
]) {
  test(`the video inspector's framing row does not label itself with one of its own options (${name})`, async ({ page }) => {
    await setInterfaceLanguage(page, language);
    await openVideoScene(page);

    const row = await fitRow(page);
    expect(row, 'the framing row was never found in the video inspector').toBeTruthy();

    // eslint-disable-next-line no-console
    console.log(`[fit] ${name}: row reads "${row!.rowText}"  (label="${row!.label}", options=${JSON.stringify(row!.options)})`);

    // Evidence. The YouTube iframe in the inspector never stops moving, so an element screenshot
    // waits forever on a stable box — take the page instead, animations frozen.
    await page.screenshot({ path: shot(`inspector-${name}`), animations: 'disabled', timeout: 20_000 })
      .catch(() => {});

    expect(row!.options, 'the framing row should offer exactly two options').toHaveLength(2);
    expect(row!.label, 'the framing row has no label').not.toBe('');

    const collision = row!.options.find((o) => o.toLocaleLowerCase() === row!.label.toLocaleLowerCase());
    expect(
      collision,
      `the framing row is labelled "${row!.label}" and one of its own options is also "${collision}" — `
        + `a teacher reads "${row!.rowText}"`,
    ).toBeUndefined();
  });
}
