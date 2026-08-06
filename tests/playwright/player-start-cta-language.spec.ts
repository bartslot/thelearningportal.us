/**
 * REFUTATION ATTEMPT — "the Start lesson button is English in every translated language".
 *
 * The claim is narrow and checkable: the one control a whole class waits on, the white CTA in the
 * bottom-left of the title screen, is printed by resources/views/lesson/player.blade.php as plain
 * text and never passes through __(). If that is true, a French teacher who has set the interface
 * to Français and opened La Révolution française for the class sees a perfectly French title
 * screen with one English button on it.
 *
 * This spec tries to knock the finding down in the three ways it could be wrong:
 *
 *   1. the surrounding chrome is English too, so the finding is really "the player isn't
 *      translated" and not "one button leaked" — checked by reading the era line and the
 *      teacher's own Edit-scene pill in the same screenshot;
 *   2. something later rewrites the button at runtime (the player's JS has a t() dictionary in
 *      window.__lpLang), so the served HTML lies about what the teacher sees — checked by reading
 *      the live DOM text of the button after the title screen has settled, not the response body;
 *   3. it only happens in a state nobody reaches — checked by driving the real settings screen and
 *      the real /lesson/{code} URL, with no database poking.
 *
 * Screenshots land in tests/playwright/results/start-cta-language/ and are the evidence.
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results/start-cta-language');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

/** Set the teacher's interface language the way a teacher does: /settings, pick, save. */
async function setInterfaceLanguage(page: Page, languageName: string) {
  await page.goto('/settings');
  const picker = page.locator('[aria-haspopup="listbox"]').first();
  await picker.waitFor({ state: 'visible' });

  // Picking writes through $wire.set and Livewire re-renders the list, detaching the <li>
  // mid-click. Retry against the visible state rather than against a clock.
  for (let attempt = 0; attempt < 4; attempt++) {
    if ((await picker.innerText()).includes(languageName)) break;
    await picker.click().catch(() => {});
    await page.getByRole('option', { name: languageName, exact: true }).first()
      .click({ force: true, timeout: 5_000 })
      .catch(() => {});
    await expect(picker).toContainText(languageName, { timeout: 5_000 }).catch(() => {});
  }
  await expect(picker, `language picker never settled on ${languageName}`).toContainText(languageName);

  await page.locator('button[wire\\:click="save"]').click();
  await expect(page.locator('[role="status"]')).toBeVisible({ timeout: 15_000 });
}

/** Open the player and wait until Alpine has left LOADING. */
async function openPlayer(page: Page, code: string) {
  await page.goto(`/lesson/${code}`);
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const phase = root?._x_dataStack?.[0]?.phase;
    return Boolean(phase) && phase !== 'LOADING';
  }, undefined, { timeout: 45_000 });
}

/** The live text of the title-screen CTA — whichever of the two states is on the glass. */
async function ctaState(page: Page) {
  return page.evaluate(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const state = root?._x_dataStack?.[0];
    const btn = document.querySelector('button.rounded-full.bg-white') as HTMLElement | null;
    const waiting = Array.from(document.querySelectorAll('div.rounded-full')).find(
      (el) => (el as HTMLElement).innerText?.includes('…')
    ) as HTMLElement | null;
    return {
      phase: state?.phase ?? null,
      canStart: state?.canStart ?? null,
      // What Alpine's t() would return, if the button ever went through it at all.
      dictHasStart: Boolean((window as any).__lpLang && (window as any).__lpLang['Start lesson']),
      locale: document.documentElement.lang,
      cta: btn?.innerText?.trim() ?? null,
      waitingLabel: waiting?.innerText?.trim() ?? null,
    };
  });
}

for (const lesson of [
  { code: process.env.PW_LESSON_FR ?? 'V6OYLL', language: 'Français', tag: 'fr', localCta: /Commencer|Démarrer|leçon/i },
  { code: process.env.PW_LESSON_NL ?? 'WMDIF8', language: 'Nederlands', tag: 'nl', localCta: /Start de les|Begin/i },
]) {
  test(`title-screen CTA is written in ${lesson.language}`, async ({ page }) => {
    await setInterfaceLanguage(page, lesson.language);
    await openPlayer(page, lesson.code);

    const state = await ctaState(page);
    await page.screenshot({ path: shot(`${lesson.tag}-title-screen`), fullPage: false });
    console.log(`[${lesson.tag}]`, JSON.stringify(state));

    // Control 1 — the page really is in this language, so an English CTA is a leak and not the
    // whole player being untranslated.
    expect(state.locale, 'the served page is not in the language the teacher chose').toBe(lesson.tag);

    // Control 2 — the runtime dictionary does not carry this string, so nothing can rewrite it.
    expect(
      state.dictHasStart,
      'window.__lpLang carries "Start lesson", so the JS layer could still translate it',
    ).toBe(false);

    // The claim itself, read off the live DOM.
    const label = state.canStart ? state.cta : state.waitingLabel;
    expect(label, 'no CTA on the title screen at all').not.toBeNull();
    expect(
      label,
      `the button a class must press reads "${label}" on a ${lesson.language} title screen`,
    ).toMatch(lesson.localCta);
  });
}
