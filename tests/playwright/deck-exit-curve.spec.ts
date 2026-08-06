import { test, expect, type Page } from '@playwright/test';

/**
 * Refutation pass on one motion-lane finding:
 *
 *   "The deck uses one curve for both leaving and arriving. Leaving, it lingers at the start and
 *    then drops off — the opposite of the intended 'accelerate away'. Practically the chrome is
 *    slightly slow to get out of the way of the picture."
 *
 * Two separate claims are bundled there, and they need separate evidence:
 *
 *   A. DECLARED — is the leave curve the same as the arrive curve, and is either of them one of
 *      the easing.js intent curves? (a string read)
 *   B. RENDERED — does the deck actually LINGER at the start of its leave? (a measurement)
 *
 * Claim B is the whole user-visible story, and it is the one a string read cannot settle. So this
 * spec samples the deck's real opacity with requestAnimationFrame while it is leaving and asks
 * where the movement is front-loaded or back-loaded.
 *
 * Must run WITHOUT SwiftShader — under it CSS transitions snap to their end frame:
 *
 *   npx playwright test --config=playwright.deck-curve.config.ts --project=motion
 */

const CODE = process.env.MOTION_LESSON_CODE ?? 'CF0AVG';
const SCENE_WITH_IMAGE = 4;
const SHOT = 'tests/playwright/results-deck-exit-curve';

/** resources/js/easing.js, as Chrome serialises the aliases. */
const EASE = {
  enter: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
  exit: 'cubic-bezier(0.55, 0.055, 0.675, 0.19)',
};
/** Tailwind's own `ease-out`, which is what the blade actually writes. */
const TAILWIND_EASE_OUT = 'cubic-bezier(0, 0, 0.2, 1)';

async function openPlayer(page: Page, scene: number) {
  await page.goto(`/lesson/${CODE}?scene=${scene}`);
  await page.getByRole('button', { name: /start lesson/i }).click();
  await expect(page.locator('#background-layer > div').first()).toBeVisible();
  expect(await page.evaluate(() => document.visibilityState)).toBe('visible');
  await page.waitForTimeout(2500);
}

const DECK_FINDER = `(() => {
  const divs = [...document.querySelectorAll('div')];
  return divs.find(el => ['transition-all','bottom-0','inset-x-0'].every(c => (el.className || '').includes(c)));
})()`;

const readDeck = (page: Page) => page.evaluate(`(() => {
  const el = ${DECK_FINDER};
  if (!el) return null;
  const cs = getComputedStyle(el);
  return {
    opacity: Number(Number(cs.opacity).toFixed(4)),
    ease: cs.transitionTimingFunction,
    duration: cs.transitionDuration,
    property: cs.transitionProperty,
  };
})()`) as Promise<{ opacity: number; ease: string; duration: string; property: string } | null>;

/** Install a rAF sampler that records the deck's opacity for `ms` milliseconds. */
async function startSampler(page: Page, ms: number) {
  await page.evaluate(`(() => {
    const el = ${DECK_FINDER};
    window.__deckSamples = [];
    const t0 = performance.now();
    const tick = () => {
      const t = performance.now() - t0;
      window.__deckSamples.push([t, Number(getComputedStyle(el).opacity)]);
      if (t < ${ms}) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  })()`);
}

const collect = (page: Page) =>
  page.evaluate('window.__deckSamples') as Promise<Array<[number, number]>>;

test.describe('student player — the deck\'s leaving curve', () => {
  test('what the deck actually declares in each direction', async ({ page }) => {
    await openPlayer(page, SCENE_WITH_IMAGE);

    await page.mouse.move(640, 700);                       // wake the chrome
    await expect.poll(async () => (await readDeck(page))?.opacity, { timeout: 5000 }).toBe(1);
    const arriving = (await readDeck(page))!;

    await page.mouse.move(640, 360);                       // send it away
    await page.waitForTimeout(80);
    const leaving = (await readDeck(page))!;

    console.log('arriving:', JSON.stringify(arriving));
    console.log('leaving :', JSON.stringify(leaving));

    // The structural half of the finding is simply true and needs no argument: one class list,
    // one curve, both directions.
    expect(leaving.ease, 'the two directions somehow differ').toBe(arriving.ease);

    // But the finding says the ARRIVAL is right and only the exit is wrong. It is not: neither
    // direction uses an easing.js intent curve. Both are Tailwind's stock ease-out.
    expect(arriving.ease).toBe(TAILWIND_EASE_OUT);
    expect(arriving.ease).not.toBe(EASE.enter);
    expect(leaving.ease).not.toBe(EASE.exit);
  });

  test('the deck does NOT linger at the start of its leave', async ({ page }) => {
    // The finding's user-visible claim. cubic-bezier(0, 0, 0.2, 1) is a DECELERATION: it starts
    // fast and eases to a stop. Lingering-then-dropping is what an ease-IN looks like — the very
    // curve the finding asks for. So if the finding's description of the symptom is right, the
    // deck should still be near opacity 1 halfway through its 300ms. Measure it.
    await openPlayer(page, SCENE_WITH_IMAGE);

    await page.mouse.move(640, 700);
    await expect.poll(async () => (await readDeck(page))?.opacity, { timeout: 5000 }).toBe(1);
    await page.screenshot({ path: `${SHOT}/deck-visible.png` });

    await startSampler(page, 600);
    await page.mouse.move(640, 360);
    await page.waitForTimeout(750);
    const samples = await collect(page);

    // Trim to the transition itself: from the last frame still fully opaque, to the first at rest.
    const startIdx = samples.findIndex(([, o]) => o < 0.999);
    expect(startIdx, 'the deck never started leaving').toBeGreaterThan(-1);
    const t0 = samples[startIdx - 1]?.[0] ?? samples[startIdx][0];
    const endIdx = samples.findIndex(([, o]) => o <= 0.001);
    expect(endIdx, 'the deck never finished leaving').toBeGreaterThan(-1);
    const dur = samples[endIdx][0] - t0;

    const trace = samples
      .slice(Math.max(0, startIdx - 1), endIdx + 1)
      .map(([t, o]) => `${Math.round(t - t0)}ms:${o.toFixed(2)}`)
      .join(' ');
    console.log(`leave took ${Math.round(dur)}ms — ${trace}`);

    // Progress at the halfway point of the transition. Fade goes 1 → 0, so progress = 1 - opacity.
    const half = samples
      .slice(startIdx - 1, endIdx + 1)
      .reduce((best, s) =>
        Math.abs(s[0] - t0 - dur / 2) < Math.abs(best[0] - t0 - dur / 2) ? s : best);
    const progressAtHalf = 1 - half[1];
    console.log(`progress at the halfway point: ${(progressAtHalf * 100).toFixed(1)}%`);

    await page.screenshot({ path: `${SHOT}/deck-mid-leave.png` });

    // easeInCubic (what the finding asks for) would sit at ~12% here. Tailwind ease-out sits
    // at ~81%. Anything past half means the deck is already mostly gone at the halfway mark —
    // front-loaded, not lingering.
    expect(
      progressAtHalf,
      `the deck was only ${(progressAtHalf * 100).toFixed(1)}% gone halfway through — that IS a linger`,
    ).toBeGreaterThan(0.5);
  });
});
