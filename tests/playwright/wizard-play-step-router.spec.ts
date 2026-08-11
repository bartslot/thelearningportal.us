import { test, expect } from '@playwright/test';

/**
 * Getting back out of the Play step.
 *
 * The Play step hands the stage to the real student player in an iframe whenever the lesson holds a
 * scene kind the wizard's own sequencer cannot play (voyage, gallery, map). That player draws the
 * teacher's only way back into editing — the "Edit scene" pill — so the pill is navigating from
 * INSIDE a frame, and `window.location` there means the frame, not the page.
 *
 * Left that way it loaded the entire wizard inside the player frame: a wizard nested in a preview,
 * with the address bar still reading step=5. Every step after that happened in the frame, so the
 * router never moved again — which is exactly what "it keeps on step 4 or step 5" looks like.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;

const playUrl = (): string => WIZARD_URL.replace(/([?&])step=\d+/, '$1step=5');

test('Edit scene on the Play step moves the page, not the player frame', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto(playUrl());
  await page.waitForLoadState('networkidle').catch(() => {});

  // Only the embedded-player shape has this problem; the wizard's own sequencer draws no pill.
  const frame = page.locator('iframe');
  test.skip(await frame.count() === 0, 'this lesson plays in the wizard sequencer, which has no iframe');

  const pill = page.frameLocator('iframe').getByRole('button', { name: 'Edit scene' });
  await expect(pill).toBeVisible({ timeout: 30_000 });
  await pill.click();

  // The page itself has to arrive at the editor.
  await expect.poll(() => new URL(page.url()).searchParams.get('step'), { timeout: 20_000 }).toBe('4');

  // And it must be the real thing, not the wizard redrawn inside the player frame.
  await expect(page.locator('[data-scene-id]').first()).toBeVisible({ timeout: 30_000 });
  expect(await page.locator('iframe').count(), 'the editor is nested inside the player frame').toBe(0);
});
