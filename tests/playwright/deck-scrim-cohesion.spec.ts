import { test, expect, type Page } from '@playwright/test';

/**
 * Does the bottom transport deck hide together with the scrim behind it?
 *
 * A student watching a lesson gets a Netflix-style deck at the bottom: play/pause, chapters,
 * a progress bar, white icons. Behind it sits a dark gradient (the "scrim") whose only job is
 * to keep those white icons legible over a bright painting. While narration plays and the
 * pointer moves away from the bottom edge, both are supposed to leave.
 *
 * The claim under test: they leave on two different curves, so for part of the 300ms the shade
 * is still there while the icons it exists for have already gone — a dark band over the picture
 * with nothing in it.
 *
 * This spec does NOT poll over the wire. Each Playwright round-trip is tens of milliseconds, so
 * a 300ms transition gets maybe six coarse, jittery samples. Instead it installs a
 * requestAnimationFrame recorder INSIDE the page: every frame it writes down both opacities and
 * the timestamp, so the two numbers are read from the same layout, on the same tick, ~60 times
 * a second. That is the only way to say honestly whether the two elements drift apart.
 *
 * Must run on the real GPU compositor (see playwright.deck-scrim.config.ts) — SwiftShader does
 * not tick compositor transitions at all, and would report an instant hide for both elements.
 */

const CODE = process.env.MOTION_LESSON_CODE ?? 'CF0AVG';
const SCENE_WITH_IMAGE = 4;                      // "A family in Frankfurt" — narration over a photo
const SHOT = 'tests/playwright/results-deck-scrim';

/** Tailwind's default transition curve, which any `transition-*` utility falls back to. */
const TAILWIND_DEFAULT = 'cubic-bezier(0.4, 0, 0.2, 1)';

type Frame = { t: number; deck: number; scrim: number };

async function openPlayer(page: Page, scene: number) {
  await page.goto(`/lesson/${CODE}?scene=${scene}`);
  await page.getByRole('button', { name: /start lesson/i }).click();
  await expect(page.locator('#background-layer > div').first()).toBeVisible();
  // A hidden page never fires rAF and never ticks a transition; assert the tab is really on
  // screen before believing any number this spec produces.
  expect(await page.evaluate(() => document.visibilityState)).toBe('visible');
  await page.waitForTimeout(2500);               // narration under way, chrome settled
}

/** Install the in-page recorder. It finds the two elements once and then just reads them. */
async function armRecorder(page: Page) {
  await page.evaluate(() => {
    const divs = [...document.querySelectorAll('div')] as HTMLElement[];
    const has = (el: HTMLElement, ...c: string[]) => c.every(x => (el.className || '').includes(x));
    const deck = divs.find(el => has(el, 'transition-all', 'bottom-0', 'inset-x-0'));
    const scrim = divs.find(el => has(el, 'transition-opacity', 'h-44', 'bottom-0'));
    const w = window as any;
    w.__cohesion = { frames: [] as any[], deck, scrim, running: false };
    w.__cohesionStart = () => {
      w.__cohesion.frames = [];
      w.__cohesion.running = true;
      const t0 = performance.now();
      const tick = () => {
        if (!w.__cohesion.running) return;
        w.__cohesion.frames.push({
          t: Math.round(performance.now() - t0),
          deck: Number(getComputedStyle(w.__cohesion.deck).opacity),
          scrim: Number(getComputedStyle(w.__cohesion.scrim).opacity),
        });
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    };
    w.__cohesionStop = () => { w.__cohesion.running = false; return w.__cohesion.frames; };
  });
  // The recorder is worthless if either element was missed.
  const found = await page.evaluate(() => {
    const w = window as any;
    return { deck: !!w.__cohesion.deck, scrim: !!w.__cohesion.scrim };
  });
  expect(found.deck, 'the transport deck was not on the page at all').toBe(true);
  expect(found.scrim, 'the bottom scrim was not on the page at all').toBe(true);
}

/** What each element DECLARES: curve, duration, and which properties it animates. */
const readDeclared = (page: Page) => page.evaluate(() => {
  const w = window as any;
  const one = (el: HTMLElement) => {
    const cs = getComputedStyle(el);
    return {
      ease: cs.transitionTimingFunction,
      duration: cs.transitionDuration,
      property: cs.transitionProperty,
      opacity: Number(Number(cs.opacity).toFixed(3)),
    };
  };
  return { deck: one(w.__cohesion.deck), scrim: one(w.__cohesion.scrim) };
});

/** Record one full hide: pointer away from the bottom edge while narration plays. */
async function recordHide(page: Page): Promise<Frame[]> {
  await page.mouse.move(640, 700);               // summon the chrome from the bottom edge
  await expect.poll(async () => (await readDeclared(page)).deck.opacity, { timeout: 8000 }).toBe(1);
  await expect.poll(async () => (await readDeclared(page)).scrim.opacity, { timeout: 8000 }).toBe(1);

  await page.evaluate(() => (window as any).__cohesionStart());
  await page.mouse.move(640, 300);               // pointer into the picture → both should leave
  await page.waitForTimeout(600);                // a 300ms transition plus slack either side
  return page.evaluate(() => (window as any).__cohesionStop());
}

test.describe('The transport deck and its scrim leave together', () => {

  test('the deck and the scrim declare the same hide curve', async ({ page }) => {
    // Cheap and decisive: whatever the rendering does, two curves declared on one movement is
    // already the defect. The scrim carries no easing utility, so it inherits Tailwind's
    // default ease-in-out while the deck asks for ease-out.
    await openPlayer(page, SCENE_WITH_IMAGE);
    await armRecorder(page);
    await page.mouse.move(640, 700);
    await expect.poll(async () => (await readDeclared(page)).deck.opacity, { timeout: 8000 }).toBe(1);

    const { deck, scrim } = await readDeclared(page);
    expect(deck.duration, 'the two halves of the chrome run for different lengths').toBe(scrim.duration);
    expect(
      scrim.ease,
      `the scrim fell back to Tailwind's default curve (${scrim.ease}) instead of the deck's ${deck.ease}`,
    ).toBe(deck.ease);
  });

  test('the icons and the shade behind them stay together through the whole hide', async ({ page }) => {
    await openPlayer(page, SCENE_WITH_IMAGE);
    await armRecorder(page);
    const frames = await recordHide(page);

    // Sanity: this must be a real, ticking fade, not a SwiftShader snap and not a page that
    // never hid anything. If either of these fails the measurement below means nothing.
    expect(frames.length, 'the recorder never ran — rAF asleep?').toBeGreaterThan(10);
    expect(frames.at(-1)!.deck, 'the deck never left').toBe(0);
    expect(frames.at(-1)!.scrim, 'the scrim never left').toBe(0);
    expect(
      frames.some(f => f.deck > 0.02 && f.deck < 0.98),
      'the deck snapped out instead of fading — the compositor is not ticking transitions',
    ).toBe(true);

    // Same tick, both numbers. How far apart do they ever get, and for how long?
    const gaps = frames.map(f => f.scrim - f.deck);
    const worst = Math.max(...gaps);
    const worstFrame = frames[gaps.indexOf(worst)];
    const moving = frames.filter(f => f.scrim > 0.001 || f.deck > 0.001);
    const apartMs = (() => {
      const bad = frames.filter(f => f.scrim - f.deck > 0.15);
      return bad.length ? bad.at(-1)!.t - bad[0].t : 0;
    })();

    // The moment a student would call a glitch: shade still substantially on screen, icons all
    // but gone. Pick the frame where the scrim is most "naked".
    const naked = frames.filter(f => f.deck <= 0.15 && f.scrim >= 0.35);

    console.log('deck/scrim hide, frame by frame (t ms: deck → scrim):');
    console.log(moving.map(f => `${f.t}: ${f.deck.toFixed(3)} → ${f.scrim.toFixed(3)}`).join('\n'));
    console.log(`worst same-frame gap ${worst.toFixed(3)} at t=${worstFrame.t}ms`);
    console.log(`gap stayed above 0.15 for ${apartMs}ms`);
    console.log(`frames with a near-empty shade (deck<=0.15, scrim>=0.35): ${naked.length}` +
      (naked.length ? ` — first at t=${naked[0].t}ms (deck ${naked[0].deck}, scrim ${naked[0].scrim})` : ''));

    // Evidence a person can look at: catch the picture mid-hide.
    await page.mouse.move(640, 700);
    await expect.poll(async () => (await readDeclared(page)).deck.opacity, { timeout: 8000 }).toBe(1);
    await page.screenshot({ path: `${SHOT}/deck-up.png` });
    await page.mouse.move(640, 300);
    await page.waitForTimeout(170);              // roughly the middle of the 300ms hide
    await page.screenshot({ path: `${SHOT}/deck-midfade.png` });
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${SHOT}/deck-gone.png` });

    // The assertion: one piece of chrome, one movement. Anything past a rounding difference
    // means the shade is outliving the icons it exists to make legible.
    expect(
      worst,
      `at t=${worstFrame.t}ms the scrim was still at ${worstFrame.scrim} while the deck had ` +
      `dropped to ${worstFrame.deck} — they were more than 0.15 apart for ${apartMs}ms`,
    ).toBeLessThanOrEqual(0.15);
  });
});
