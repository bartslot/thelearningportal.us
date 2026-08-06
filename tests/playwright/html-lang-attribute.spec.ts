import { test, expect, Page } from '@playwright/test';

/**
 * REFUTATION SPEC — "The page declares lang=\"en\" while rendering in the user's language".
 *
 * The claim was raised from `curl http://127.0.0.1:8000/teacher/lessons/63/wizard?step=4`.
 * Lesson 63 does not exist in this database, so that URL renders Laravel's 404 page, whose
 * stock template hard-codes `<html lang="en">`. The app's own layouts all interpolate
 * `app()->getLocale()` (resources/views/components/layouts/app.blade.php:2).
 *
 * So this spec walks the route a real teacher walks: Settings -> Language -> Français -> Save,
 * then opens the wizard and reads both the <html lang> attribute and the visible UI language
 * off the SAME rendered page. If the finding were real, we would see lang="en" over French copy.
 *
 * It restores English at the end so the shared dev database is left as it was found.
 */

const LESSON_ID = process.env.PW_LESSON_ID ?? '39';

async function pickLanguage(page: Page, languageName: string) {
  await page.goto('/settings');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  // The UI-language picker is the first listbox button on the page (Teaching language is second).
  const picker = page.getByRole('button', { name: /^(Language|Taal|Sprache|Langue|Lingua)$/ }).first();
  await picker.click();
  await page.getByRole('option', { name: languageName }).click();
  await expect(picker).toContainText(languageName);

  await page.getByRole('button', { name: /^(Save|Opslaan|Speichern|Enregistrer|Salva)$/ }).click();
  await expect(page.getByRole('status')).toBeVisible();
}

async function htmlLang(page: Page) {
  return page.locator('html').first().getAttribute('lang');
}

test.describe('<html lang> follows the teacher\'s interface language', () => {
  test.afterEach(async ({ page }) => {
    await pickLanguage(page, 'English').catch(() => { /* best effort restore */ });
  });

  test('English teacher: English body, lang="en"', async ({ page }) => {
    await pickLanguage(page, 'English');

    await page.goto(`/teacher/lessons/${LESSON_ID}/wizard?step=4`);
    await page.waitForLoadState('domcontentloaded');

    expect(await htmlLang(page)).toBe('en');
    await expect(page.locator('body')).not.toContainText('Ajouter la narration');
  });

  test('French teacher: French body AND lang="fr" on the same response', async ({ page }) => {
    await pickLanguage(page, 'Français');

    await page.goto(`/teacher/lessons/${LESSON_ID}/wizard?step=4`);
    await page.waitForLoadState('domcontentloaded');

    const lang = await htmlLang(page);
    const bodyText = (await page.locator('body').innerText()).toLowerCase();

    // Evidence of what a screen reader / browser-translate actually gets.
    await page.screenshot({ path: 'tests/playwright/results/html-lang/fr-wizard.png', fullPage: false });

    // The body really is French (proves the locale switch took effect on this very page).
    const looksFrench = /narration|aperçu|paramètres|publier|scène/.test(bodyText);
    expect(looksFrench, `body did not look French: ${bodyText.slice(0, 300)}`).toBe(true);

    // ...and the declared language matches it. This is the assertion the finding says fails.
    expect(lang).toBe('fr');
  });

  test('the reported URL (lesson 63) is a 404 page, not a localised wizard', async ({ page }) => {
    const response = await page.goto('/teacher/lessons/63/wizard?step=4');
    expect(response?.status()).toBe(404);
    // Laravel's stock error template is what carries the literal lang="en".
    expect(await htmlLang(page)).toBe('en');
    await expect(page.locator('body')).not.toContainText('Ajouter la narration');
  });
});
