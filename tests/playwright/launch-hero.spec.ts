import { expect, test } from '@playwright/test';

/**
 * The launch announcement shares the landing hero's stage and its GSAP timeline, and differs only in
 * what it says and what arrives out of the tunnel. So there are exactly two things worth proving:
 * the launch script really plays through to its reveal, and adding it did not disturb the lesson
 * demo that the landing page still depends on.
 *
 * Run in a REAL browser on purpose. GSAP does not advance while document.hidden is true, so the
 * reveal reads as opacity 0 forever in a headless pane that reports itself hidden — the animation is
 * fine and the check is what is broken. See the gsap-sleeps-in-the-hidden-browser-pane note.
 */

const settle = async (page: import('@playwright/test').Page) => {
  // Skip is what a visitor presses to get to the end; it is also the shortest honest way to assert
  // the END STATE without waiting out the whole timeline.
  const skip = page.locator('[data-demo-skip]');
  if (await skip.isVisible().catch(() => false)) await skip.click();
  await page.waitForTimeout(1800);
};

test('the launch hero announces the product and lands the logo', async ({ page }) => {
  await page.goto('/launch', { waitUntil: 'domcontentloaded' });
  await settle(page);

  const reveal = page.locator('[data-demo-reveal]');
  await expect(reveal).toBeVisible();
  await expect(reveal.locator('h1')).toHaveText(/History Portal is now live/i);

  // The logo is what arrives in the slot the lesson poster uses in the other script. It has to be
  // ON SCREEN and actually decoded — a broken image would still satisfy a visibility check.
  const mark = page.locator('.hero-launch-mark');
  await expect(mark).toBeVisible();
  expect(await mark.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);

  await expect(reveal.getByRole('link', { name: /watch a lesson/i })).toBeVisible();
  await expect(reveal.getByRole('link', { name: /browse the lessons/i })).toBeVisible();
});

test('the launch script types its own line, not the lesson demo one', async ({ page }) => {
  await page.goto('/launch', { waitUntil: 'domcontentloaded' });

  const typed = page.locator('[data-demo-typed]');
  await expect(typed).toHaveAttribute('data-demo-typed', /all of it, in one place/i);

  // The lesson demo's questions must not leak into the announcement.
  await expect(page.locator('body')).not.toContainText('What is your learning goal?');
  await expect(page.locator('body')).not.toContainText('Your lesson is ready');
});

test('the landing page still runs the lesson demo', async ({ page }) => {
  // The whole point of the mode prop is that this page is untouched.
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await settle(page);

  await expect(page.locator('body')).toContainText('Your lesson is ready');
  await expect(page.locator('.hero-launch-mark')).toHaveCount(0);
});

test('both scripts survive reduced motion with their words intact', async ({ browser }) => {
  // With motion off the timeline never runs, so the reveal IS the page. If the copy only exists
  // once GSAP has played, a motion-sensitive visitor gets an empty hero.
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();

  await page.goto('/launch', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-demo-reveal] h1')).toContainText(/History Portal is now live/i);

  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-demo-reveal] h1')).toContainText(/Your lesson is ready/i);

  await context.close();
});
