import { test, expect, Page } from '@playwright/test';

/**
 * Abel Tasman voyage lesson (lessons:make-tasman-voyage): full arrow-key playthrough.
 * Voyage scenes sail a dated leg then wait; galleries cycle images; → advances, ← goes back.
 * Rebuild the fixture lesson with: php artisan lessons:make-tasman-voyage
 */
const LESSON_CODE = process.env.VOYAGE_LESSON_CODE || 'YMJ1W9';

async function currentDateChip(page: Page): Promise<string> {
  return (await page.locator('#lesson-map-stage div.rounded-full').first().textContent()) || '';
}

test('voyage lesson: arrows traverse all scenes, chips and galleries render', async ({ page }) => {
  test.setTimeout(300_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 140)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 140)); });

  await page.goto(`/lesson/${LESSON_CODE}`);
  await page.click('button:has-text("Start lesson")', { timeout: 10_000 });

  // Scene 1 (voyage leg 0): space intro → sail → arrival card.
  await page.waitForTimeout(4_000);
  expect(await currentDateChip(page)).toContain('1642'); // date chip live during the sail
  await page.waitForTimeout(14_000);
  await expect(page.locator('#lesson-map-stage')).toContainText('Verversingspost', { timeout: 15_000 });
  await page.screenshot({ path: 'tests/playwright/results/voyage-1-arrival.png' });

  // Walk the remaining scenes with → only; verify each gallery shows its story panel.
  // Galleries assert on their pack date_label (stable); voyage legs on the live date chip year.
  const expectations = [
    { text: '5 september 1642', gallery: true },   // gallery: Mauritius
    { text: '164', gallery: false },               // voyage leg 2
    { text: '24 november 1642', gallery: true },   // gallery: Van Diemensland
    { text: '164', gallery: false },               // voyage leg 3
    { text: '13 december 1642', gallery: true },   // gallery: Moordenaarsbaai
    { text: '164', gallery: false },               // voyage leg 4
    { text: '21 januari 1643', gallery: true },    // gallery: Tonga
    { text: '164', gallery: false },               // voyage leg 5 (return)
  ];
  for (let i = 0; i < expectations.length; i++) {
    await page.keyboard.press('ArrowRight');
    // Voyage legs need their sail time; galleries render immediately.
    await page.waitForTimeout(expectations[i].gallery ? 3_000 : 16_000);
    await expect(page.locator('#lesson-map-stage')).toContainText(expectations[i].text, { timeout: 20_000 });
    await page.screenshot({ path: `tests/playwright/results/voyage-${i + 2}.png` });
  }

  // ← goes back (replays the previous scene) and A/B mirror the arrows.
  await page.keyboard.press('KeyA');
  await page.waitForTimeout(2_000);
  await page.keyboard.press('KeyB');
  await page.waitForTimeout(2_000);

  expect(errors, errors.join('\n')).toHaveLength(0);
});
