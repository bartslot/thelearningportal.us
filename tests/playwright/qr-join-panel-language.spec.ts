/**
 * The QR "join" panel on the projected title screen, read in French and in Dutch.
 *
 * A class starts with the title screen on the beamer. Top right sits a QR code, the lesson code,
 * and one line of instruction under it — the only sentence on that screen aimed at the phones in
 * the room. Clicking the QR blows it up into a modal with a second instruction line.
 *
 * This spec sets the teacher's interface language through the real settings screen, opens a real
 * published lesson, and reads those two lines off the glass. It also reads a CONTROL string from
 * the same screen (the start CTA and the narrator caption), so a failure can never be blamed on
 * "the locale never switched" — if the control is translated and the QR line is not, the QR line
 * is the defect.
 *
 * No motion, no WebGL, no rAF: everything asserted here is static text painted at first render,
 * so the default chromium project (SwiftShader) is fine.
 *
 * Screenshots: tests/playwright/results/qr-join-panel/
 */
import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve('tests/playwright/results/qr-join-panel');
fs.mkdirSync(SHOTS, { recursive: true });
const shot = (name: string) => path.join(SHOTS, `${name}.png`);

const LESSONS = [
  { language: 'Français', code: 'V6OYLL', tag: 'fr' },
  { language: 'Nederlands', code: 'WMDIF8', tag: 'nl' },
];

/** Set the signed-in teacher's interface language through the account settings screen. */
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

/** Open the player and wait for the title screen to have finished loading. */
async function openPlayer(page: Page, code: string) {
  await page.goto(`/lesson/${code}`);
  await page.waitForFunction(() => {
    const root = document.querySelector('[x-data]') as (HTMLElement & { _x_dataStack?: any[] }) | null;
    const phase = root?._x_dataStack?.[0]?.phase;
    return Boolean(phase) && phase !== 'LOADING';
  }, undefined, { timeout: 45_000 });
}

/** The instruction line under the QR code, as a viewer in the back row reads it. */
const qrCaption = (page: Page) =>
  page.locator('button:has(#title-qr-canvas) p').last();

test.describe('QR join panel — projected title screen', () => {
  for (const lesson of LESSONS) {
    test(`${lesson.language}: the line under the QR code`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 }); // the panel is hidden below `sm`
      await setInterfaceLanguage(page, lesson.language);
      await openPlayer(page, lesson.code);

      // Control: the panel really is on screen, and the page really is in this language.
      const caption = qrCaption(page);
      await expect(caption, 'the QR panel should be visible on a projector-sized screen').toBeVisible();

      // Control: this page really did render in the chosen language. Two independent proofs —
      // the document language, and a string the same Blade file DOES pass through __().
      const htmlLang = await page.locator('html').getAttribute('lang');
      expect(htmlLang, 'the page should be served in the teacher\'s language').toBe(lesson.tag);
      const subtitles = await page.evaluate(() =>
        document.querySelector('[data-tooltip-key="C"]')?.getAttribute('data-tooltip') ?? '');
      expect(subtitles.toLowerCase(), 'a __() string on this same page is still English')
        .not.toBe('subtitles');
      const controlTranslated = `lang=${htmlLang}, subtitles tooltip="${subtitles}"`;

      await page.screenshot({ path: shot(`${lesson.tag}-01-title-screen`) });

      const captionText = (await caption.innerText()).trim();
      expect(
        captionText.toLowerCase(),
        `[${lesson.language}] control (page IS translated): ${controlTranslated}. ` +
        `The line under the QR code reads "${captionText}" — see ${shot(`${lesson.tag}-01-title-screen`)}`,
      ).not.toBe('scan to join');
    });

    test(`${lesson.language}: the enlarged QR modal`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await setInterfaceLanguage(page, lesson.language);
      await openPlayer(page, lesson.code);

      await page.locator('button:has(#title-qr-canvas)').click();
      const modal = page.locator('#qr-modal');
      await expect(modal).toBeVisible();
      // The instruction line is the last paragraph in the box, under the big lesson code.
      const line = modal.locator('.modal-box p').last();
      await expect(line).toBeVisible();

      await page.screenshot({ path: shot(`${lesson.tag}-02-qr-modal`) });

      const text = (await line.innerText()).trim();
      expect(
        text.toLowerCase(),
        `[${lesson.language}] the join modal reads "${text}" — see ${shot(`${lesson.tag}-02-qr-modal`)}`,
      ).not.toBe('scan to join the lesson');
    });
  }
});

test.afterAll(async ({ browser }) => {
  // Leave the dev teacher's account the way it was found.
  const page = await browser.newPage();
  await setInterfaceLanguage(page, 'English');
  await page.close();
});
