/**
 * REFUTATION LANE — "the student's Start lesson button is hardcoded English in every language".
 *
 * This spec is written to knock the claim down, not to confirm it. It only fails if the button a
 * whole class waits on is still English while the same document proves the interface language is
 * not English.
 *
 * The three ways the finding could be wrong, and how each is ruled out here:
 *
 *  1. "the whole player is untranslated, so this is not a leak" — ruled out by reading, from the
 *     SAME live document, strings that DO go through __(): the transport deck's Sous-titres /
 *     Chapitres (fr) and Ondertiteling / Hoofdstukken (nl). If those are localized and the CTA is
 *     not, the CTA is a leak.
 *  2. "something rewrites the label at runtime" — ruled out by reading innerText off the live DOM
 *     after Alpine has left LOADING (never the response body), and by checking every window-level
 *     dictionary the player ships for a "Start lesson" entry.
 *  3. "no real user reaches this state" — ruled out by driving the real /settings language picker
 *     as a teacher does before class, then opening the real public /lesson/{code} URL. No database
 *     poking, no query strings, no forced locale.
 *
 * Harness traps considered: nothing here asserts on motion, so SwiftShader freezing compositor
 * transitions is irrelevant; the page is never hidden, so rAF/GSAP/MapLibre stalls do not apply;
 * screenshots go to this spec's own output directory so a concurrent run cannot overwrite them.
 *
 * Evidence: tests/playwright/results-student-cta-i18n/
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results-student-cta-i18n');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

type Case = {
  code: string;
  language: string;
  tag: string;
  /** Strings that prove the document IS in this language (they go through __()). */
  translatedControls: string[];
  /** What a localized CTA would plausibly look like. */
  localCta: RegExp;
};

const CASES: Case[] = [
  {
    code: process.env.PW_LESSON_FR ?? 'V6OYLL',
    language: 'Français',
    tag: 'fr',
    translatedControls: ['Sous-titres', 'Chapitres'],
    localCta: /commencer|démarrer|leçon/i,
  },
  {
    code: process.env.PW_LESSON_NL ?? 'WMDIF8',
    language: 'Nederlands',
    tag: 'nl',
    translatedControls: ['Ondertiteling', 'Hoofdstukken'],
    localCta: /start de les|begin|les starten/i,
  },
];

/** Change the interface language the way a teacher does: open /settings, pick, save. */
async function setInterfaceLanguage(page: Page, languageName: string) {
  await page.goto('/settings');
  const picker = page.locator('[aria-haspopup="listbox"]').first();
  await picker.waitFor({ state: 'visible' });

  for (let attempt = 0; attempt < 4; attempt++) {
    if ((await picker.innerText()).includes(languageName)) break;
    await picker.click().catch(() => {});
    await page
      .getByRole('option', { name: languageName, exact: true })
      .first()
      .click({ force: true, timeout: 5_000 })
      .catch(() => {});
    await expect(picker).toContainText(languageName, { timeout: 5_000 }).catch(() => {});
  }
  await expect(picker, `language picker never settled on ${languageName}`).toContainText(languageName);

  await page.locator('button[wire\\:click="save"]').click();
  await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 15_000 });
}

/** Open the public player and wait for Alpine to leave LOADING (state, not a sleep). */
async function openPlayer(page: Page, code: string) {
  await page.goto(`/lesson/${code}`);
  await page.waitForFunction(
    () => {
      const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
      const phase = root?._x_dataStack?.[0]?.phase;
      return Boolean(phase) && phase !== 'LOADING';
    },
    undefined,
    { timeout: 45_000 },
  );
}

/** Read the title screen off the live DOM. */
async function readTitleScreen(page: Page) {
  return page.evaluate(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const state = root?._x_dataStack?.[0];

    const startBtn = Array.from(document.querySelectorAll('button')).find((b) =>
      b.className.includes('bg-white') && b.className.includes('rounded-full'),
    ) as HTMLElement | undefined;

    const waiting = Array.from(document.querySelectorAll('div.rounded-full')).find((el) =>
      (el as HTMLElement).innerText?.includes('…'),
    ) as HTMLElement | undefined;

    // Any runtime dictionary the player might use to rewrite labels after paint.
    const dictionaries = Object.keys(window).filter((k) => /lang|i18n|trans/i.test(k));
    const dictHasStart = dictionaries.some((k) => {
      const v = (window as any)[k];
      return v && typeof v === 'object' && typeof v['Start lesson'] === 'string';
    });

    return {
      phase: state?.phase ?? null,
      canStart: state?.canStart ?? null,
      locale: document.documentElement.lang,
      cta: startBtn?.innerText?.trim() ?? null,
      waitingLabel: waiting?.innerText?.trim() ?? null,
      dictionaries,
      dictHasStart,
      bodyText: document.body.innerText,
      html: document.documentElement.outerHTML,
    };
  });
}

for (const c of CASES) {
  test(`a ${c.language} class sees a ${c.language} button on the title screen (${c.code})`, async ({ page }) => {
    await setInterfaceLanguage(page, c.language);
    await openPlayer(page, c.code);

    const seen = await readTitleScreen(page);
    await page.screenshot({ path: shot(`${c.tag}-title-screen`) });
    console.log(
      `[${c.tag}] locale=${seen.locale} phase=${seen.phase} canStart=${seen.canStart} ` +
        `cta=${JSON.stringify(seen.cta)} waiting=${JSON.stringify(seen.waitingLabel)} ` +
        `dicts=${JSON.stringify(seen.dictionaries)} dictHasStart=${seen.dictHasStart}`,
    );

    // Rule out #1a: the document really is served in this language.
    expect(seen.locale, 'the player was not served in the chosen interface language').toBe(c.tag);

    // Rule out #1b: translated UI strings are present in this very document, so the player is
    // not simply an untranslated screen.
    for (const control of c.translatedControls) {
      expect(
        seen.html.includes(control),
        `"${control}" is missing, so the player may just be untranslated rather than leaking`,
      ).toBe(true);
    }

    // Rule out #2: nothing on the page can rewrite the label at runtime.
    expect(seen.dictHasStart, 'a runtime dictionary carries "Start lesson" and could translate it').toBe(false);

    // The claim itself, read off the live DOM in the state a class actually reaches.
    const label = seen.canStart ? seen.cta : seen.waitingLabel;
    expect(label, 'there is no CTA on the title screen at all').toBeTruthy();
    expect(
      label,
      `the one control a ${c.language} class must press reads "${label}"`,
    ).toMatch(c.localCta);
  });
}
