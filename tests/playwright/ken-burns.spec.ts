import { test, expect } from '@playwright/test';

/**
 * The Ken Burns drift on a background, measured rather than eyeballed.
 *
 * It cannot be checked from the in-app browser pane: that pane runs hidden, and a hidden document
 * never fires requestAnimationFrame — which is exactly where the player schedules the "to" frame
 * of the drift. A hidden pane therefore shows a frozen background whether or not the animation
 * works, so this lives in Playwright, where the page is genuinely visible.
 */
test.describe('Ken Burns', () => {
  test('the background keeps moving after the first frame', async ({ page }) => {
    await page.goto('/lesson/WMDIF8');
    expect(await page.evaluate(() => document.visibilityState)).toBe('visible');

    // The two crossfade layers are anonymous divs appended to #background-layer; A is the first.
    const layer = page.locator('#background-layer > div').first();
    await expect(layer).toHaveCount(1);

    const transformAt = () => layer.evaluate(el => getComputedStyle(el).transform);

    // Sample across a second and a half. The drift runs over several seconds, so consecutive
    // samples must differ; a static background reports the identical matrix every time.
    const first = await transformAt();
    await page.waitForTimeout(700);
    const middle = await transformAt();
    await page.waitForTimeout(700);
    const last = await transformAt();

    expect(first, 'the background never left its opening frame').not.toBe(middle);
    expect(middle, 'the background stopped moving part-way through').not.toBe(last);
  });

  test('a lesson with a single cover image keeps drifting past one pass', async ({ page }) => {
    // The regression: one CSS transition ends after _bgSlideMax and parks at its final frame.
    // Lessons with several images hid it, because changing picture restarted the drift; a voyage
    // lesson has exactly one cover, so its title screen moved for twelve seconds and then stopped.
    test.setTimeout(60_000);
    await page.goto('/lesson/WMDIF8');

    // Read whichever layer is actually on screen. The two layers crossfade, so after the first
    // repeat the visible one is B — sampling A regardless reports the hidden layer sitting still.
    const transformAt = () => page.evaluate(() => {
      const layers = [...document.getElementById('background-layer').children];
      const visible = layers.find(el => Number(getComputedStyle(el).opacity) > 0.5) ?? layers[0];
      return getComputedStyle(visible).transform;
    });

    // Well past one full pass (12s), where the old behaviour was frozen.
    await page.waitForTimeout(16_000);
    const afterOnePass = await transformAt();
    await page.waitForTimeout(1_500);

    expect(await transformAt(), 'the drift stopped after its first pass').not.toBe(afterOnePass);
  });

  test('a background with no face is cropped from the centre', async ({ page }) => {
    // Lesson 18's cover is a tall engraving of Tasman's fleet. Anchoring it to the top showed the
    // mast and flag and cropped the ships away.
    await page.goto('/lesson/WMDIF8');

    const position = await page
      .locator('#background-layer > div')
      .first()
      .evaluate(el => getComputedStyle(el).backgroundPosition);

    expect(position).toBe('50% 50%');
  });
});
