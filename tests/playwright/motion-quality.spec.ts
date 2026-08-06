import { test, expect, type Page } from '@playwright/test';

/**
 * Motion quality in the student player — measured, not eyeballed.
 *
 * Two things make this spec different from a normal UI test.
 *
 *  1. It must run WITHOUT SwiftShader. Under `--use-angle=swiftshader` (the default `chromium`
 *     project) compositor-driven CSS transitions do not tick: they snap to their end frame and
 *     sit there, so every drift and fade reads as "frozen" whether or not it works. Run it with
 *
 *         npx playwright test --config=playwright.motion.config.ts --project=motion
 *
 *  2. It reads the DECLARED animation as well as the rendered one. A fade can be perfectly
 *     smooth and still be wrong: the project keeps one easing vocabulary in resources/js/easing.js
 *     and picks curves by INTENT — reveals decelerate (EASE.enter), closes accelerate away
 *     (EASE.exit), cameras glide (EASE.move), pins overshoot (EASE.pop). So each test asks two
 *     questions: does it move at all, and does it move on the curve the project says it should.
 *
 * Fixture: lesson CF0AVG, "Anne Frank and the Secret Annex" — 24 scenes mixing narration, map,
 * gallery, video and two quizzes, all narrated. Scene indices below are queue indices, the same
 * ones the player's own ?scene= deep link takes.
 */

const CODE = process.env.MOTION_LESSON_CODE ?? 'CF0AVG';

// Queue indices in CF0AVG. Both narration scenes carry a background image, which is what makes
// them the right pair for a crossfade: two pictures, not a picture and a colour.
const SCENE_WITH_IMAGE = 4;              // "A family in Frankfurt"
const NEXT_CHAPTER_WITH_IMAGE = 'Escape to Amsterdam';
const SCENE_QUIZ = 21;                   // "What do you already know?"

const SHOT = 'tests/playwright/results-motion-quality';

/** resources/js/easing.js — the semantic aliases, as Chrome serialises them. */
const EASE = {
  enter: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
  exit: 'cubic-bezier(0.55, 0.055, 0.675, 0.19)',
  move: 'cubic-bezier(0.645, 0.045, 0.355, 1)',
  pop: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
};
const INTENT_CURVES = Object.values(EASE);

/**
 * Is this curve a DECELERATION — fast start, soft landing? That is what "enter" means, whatever
 * the exact control points. y1 well above x1 on the first control point is the whole test.
 */
function decelerates(curve: string): boolean {
  const m = curve.match(/cubic-bezier\(([-\d.]+),\s*([-\d.]+),/);
  if (!m) return false;
  return Number(m[2]) > Number(m[1]);
}

/** Start the lesson at a given scene, the way the player's own deep link does. */
async function openPlayer(page: Page, scene: number) {
  await page.goto(`/lesson/${CODE}?scene=${scene}`);
  // The label is hardcoded English in the blade, so it survives the UI language.
  await page.getByRole('button', { name: /start lesson/i }).click();
  await expect(page.locator('#background-layer > div').first()).toBeVisible();
  // The player is genuinely on screen — a hidden document never fires rAF, and the drift's
  // "to" frame is scheduled there. Assert it rather than assume it.
  expect(await page.evaluate(() => document.visibilityState)).toBe('visible');
  await page.waitForTimeout(2500);   // narration starts, chrome settles
}

/** The two crossfade layers, read in one frame so they can be compared against each other. */
const readLayers = (page: Page) => page.evaluate(() => {
  const kids = [...(document.getElementById('background-layer')?.children ?? [])] as HTMLElement[];
  return kids
    .filter(el => el.style.backgroundImage && el.style.backgroundImage !== 'none')
    .map(el => {
      const cs = getComputedStyle(el);
      const props = cs.transitionProperty.split(',').map(s => s.trim());
      const fns = cs.transitionTimingFunction.split(/,(?![^(]*\))/).map(s => s.trim());
      const durs = cs.transitionDuration.split(',').map(s => s.trim());
      const at = (p: string) => { const i = props.indexOf(p); return i === -1 ? null : { fn: fns[i], dur: durs[i] }; };
      return {
        image: el.style.backgroundImage.replace(/^url\("?|"?\)$/g, '').split('/').slice(-3).join('/'),
        opacity: Number(cs.opacity),
        transform: cs.transform,
        opacityEase: at('opacity'),
        transformEase: at('transform'),
      };
    });
});

/** The bottom chrome: the deck itself and the scrim that must come and go with it. */
const readChrome = (page: Page) => page.evaluate(() => {
  const divs = [...document.querySelectorAll('div')] as HTMLElement[];
  const has = (el: HTMLElement, ...c: string[]) => c.every(x => (el.className || '').includes(x));
  const deck = divs.find(el => has(el, 'transition-all', 'bottom-0', 'inset-x-0'));
  const scrim = divs.find(el => has(el, 'transition-opacity', 'h-44', 'bottom-0'));
  const one = (el?: HTMLElement) => {
    if (!el) return null;
    const cs = getComputedStyle(el);
    return {
      opacity: Number(Number(cs.opacity).toFixed(3)),
      lift: cs.translate,                       // Tailwind v4 uses `translate`, not `transform`
      ease: cs.transitionTimingFunction,
      duration: cs.transitionDuration,
      property: cs.transitionProperty,
    };
  };
  return { deck: one(deck), scrim: one(scrim) };
});

/** Sample a reader every `every` ms, `n` times. Returns the frames in order. */
async function sample<T>(page: Page, read: (p: Page) => Promise<T>, n: number, every = 50): Promise<T[]> {
  const frames: T[] = [];
  for (let i = 0; i < n; i++) { frames.push(await read(page)); await page.waitForTimeout(every); }
  return frames;
}

/**
 * Watch every animation the page declares, through the DevTools protocol.
 *
 * TRAP: for a CSSAnimation, `source.easing` is ALWAYS "linear" — the real curve lives per
 * keyframe in `source.keyframesRule.keyframes[].easing`. For a CSSTransition the effect-level
 * easing IS the real one.
 */
async function recordAnimations(page: Page) {
  const cdp = await page.context().newCDPSession(page);
  const started: any[] = [];
  await cdp.send('Animation.enable');
  cdp.on('Animation.animationStarted', ({ animation }) => started.push(animation));

  // The protocol event can land a beat after the element is on screen, so callers poll rather
  // than read once — a single read is a race that reports a working animation as missing.
  return async (name: string) => {
    await expect
      .poll(() => started.some(a => a.name === name), {
        timeout: 5_000,
        message: `no "${name}" animation was ever declared`,
      })
      .toBe(true);
    return started.find(a => a.name === name);
  };
}

/** Jump chapters the way a student does: open the list in the deck, click a chapter by name. */
async function jumpToChapter(page: Page, name: string) {
  await page.mouse.move(640, 700);                       // summon the chrome from the bottom edge
  await expect.poll(async () => (await readChrome(page)).deck?.opacity).toBe(1);
  const chapters = page.locator('button[aria-expanded]').first();
  await chapters.click();
  await expect(chapters).toHaveAttribute('aria-expanded', 'true');
  // Chapter names come from the lesson's data, not from a translation file, so they are stable
  // whichever UI language the account happens to be in.
  await page.getByRole('button', { name }).click();
}

test.describe('Motion quality — student player', () => {

  // ── The transport deck ───────────────────────────────────────────────────────────────────
  //
  // Netflix-style bottom deck: it hides itself while narration plays and the pointer is away
  // from the edges, and comes back when the pointer returns. Both halves have to be a movement;
  // chrome that pops in and out is the thing this design was built to avoid.

  test('the deck fades and lifts away when the student stops touching it, and comes back', async ({ page }) => {
    await openPlayer(page, SCENE_WITH_IMAGE);

    await page.mouse.move(640, 700);                      // pointer at the bottom edge → deck up
    await expect.poll(async () => (await readChrome(page)).deck?.opacity, { timeout: 5000 }).toBe(1);

    await page.mouse.move(640, 360);                      // pointer to the middle → deck goes away
    const hiding = await sample(page, readChrome, 10);
    await page.screenshot({ path: `${SHOT}/deck-hidden.png` });

    const hideOpacity = hiding.map(f => f.deck!.opacity);
    expect(hideOpacity.at(-1), 'the deck never left the screen').toBe(0);
    expect(
      hideOpacity.some(o => o > 0.02 && o < 0.98),
      `the deck snapped out instead of fading — opacity went ${hideOpacity.join(' → ')}`,
    ).toBe(true);

    // It lifts as it goes (translate-y-2 = 8px). A pure opacity fade was not the design.
    const lift = hiding.map(f => parseFloat((f.deck!.lift || '0').split(' ')[1] ?? '0') || 0);
    expect(Math.max(...lift), 'the deck faded but never lifted').toBeCloseTo(8, 0);
    expect(lift.some(v => v > 0.2 && v < 7.8), 'the lift jumped straight to 8px').toBe(true);

    await page.mouse.move(640, 700);                      // and back
    const showing = await sample(page, readChrome, 10);
    await page.screenshot({ path: `${SHOT}/deck-shown.png` });
    const showOpacity = showing.map(f => f.deck!.opacity);
    expect(showOpacity.at(-1), 'the deck never came back').toBe(1);
    expect(
      showOpacity.some(o => o > 0.02 && o < 0.98),
      `the deck snapped back instead of fading — opacity went ${showOpacity.join(' → ')}`,
    ).toBe(true);
  });

  test('the deck and the scrim behind it hide on the same curve', async ({ page }) => {
    // They are one piece of chrome to a student: the dark gradient exists only to keep the
    // deck's white icons legible. Two curves on one movement is the defect — the icons and the
    // shade under them drift apart mid-fade.
    await openPlayer(page, SCENE_WITH_IMAGE);
    await page.mouse.move(640, 700);
    await expect.poll(async () => (await readChrome(page)).deck?.opacity, { timeout: 5000 }).toBe(1);

    const { deck, scrim } = await readChrome(page);
    expect(scrim, 'no bottom scrim found').not.toBeNull();
    expect(deck!.duration).toBe(scrim!.duration);
    expect(
      deck!.ease,
      `the deck eases on ${deck!.ease} while its own scrim eases on ${scrim!.ease}`,
    ).toBe(scrim!.ease);
  });

  test('the deck leaves on an exit curve and returns on an enter curve', async ({ page }) => {
    // easing.js: a close accelerates away (EASE.exit), a reveal decelerates to rest (EASE.enter).
    // One shared transition cannot do both, so this reads the curve in each direction.
    await openPlayer(page, SCENE_WITH_IMAGE);

    await page.mouse.move(640, 700);
    await expect.poll(async () => (await readChrome(page)).deck?.opacity, { timeout: 5000 }).toBe(1);
    const shown = (await readChrome(page)).deck!;

    await page.mouse.move(640, 360);
    await page.waitForTimeout(80);                        // read the curve while it is leaving
    const leaving = (await readChrome(page)).deck!;

    expect(leaving.ease, `the deck leaves on ${leaving.ease}, not EASE.exit`).toBe(EASE.exit);
    expect(shown.ease, `the deck arrives on ${shown.ease}, not EASE.enter`).toBe(EASE.enter);
  });

  // ── Scene transitions ────────────────────────────────────────────────────────────────────

  test('changing chapter crossfades one background into the next', async ({ page }) => {
    await openPlayer(page, SCENE_WITH_IMAGE);
    const before = await readLayers(page);
    expect(before.length, 'no background image on the opening scene').toBeGreaterThan(0);
    const wasVisible = before.find(l => l.opacity > 0.5)!;

    await jumpToChapter(page, NEXT_CHAPTER_WITH_IMAGE);
    const frames = await sample(page, readLayers, 14, 70);
    await page.screenshot({ path: `${SHOT}/scene-change.png` });

    // The outgoing picture must pass through the middle, not blink out.
    const outgoing = frames.map(f => f.find(l => l.image === wasVisible.image)?.opacity ?? 0);
    expect(
      outgoing.some(o => o > 0.05 && o < 0.95),
      `the previous scene cut instead of fading — opacity went ${outgoing.join(' → ')}`,
    ).toBe(true);
    expect(outgoing.at(-1), 'the previous scene never faded out').toBeLessThan(0.02);

    // …and the new picture must be there, at full strength, when it is over.
    const last = frames.at(-1)!;
    const incoming = last.find(l => l.image !== wasVisible.image);
    expect(incoming, 'the next chapter never put its picture up').toBeTruthy();
    expect(incoming!.opacity).toBe(1);
  });

  test('the scene transition runs on one of the project easing curves', async ({ page }) => {
    // The Animate tab lets a teacher pick Settle / Smooth / Accelerate / Overshoot for how a
    // scene replaces the one before it, and the player resolves that choice to a cubic-bezier
    // (SCENE_EASINGS in lesson-player.js, default 'move'). This checks the curve that actually
    // reaches the layer is one of those four.
    await openPlayer(page, SCENE_WITH_IMAGE);
    const layer = (await readLayers(page)).find(l => l.opacity > 0.5)!;

    expect(layer.opacityEase, 'the layer has no opacity transition at all').not.toBeNull();
    const fn = layer.opacityEase!.fn;
    expect(
      INTENT_CURVES.includes(fn) ? fn : `${fn} (expected one of ${INTENT_CURVES.join(' / ')})`,
      `the crossfade runs on "${fn}" — not one of EASE.enter/exit/move/pop, `
      + "so the Animate tab's Easing choice never reaches the player",
    ).toBe(fn);
  });

  // ── Ken Burns ────────────────────────────────────────────────────────────────────────────

  test('the background of a newly opened chapter keeps drifting', async ({ page }) => {
    await openPlayer(page, SCENE_WITH_IMAGE);
    await jumpToChapter(page, NEXT_CHAPTER_WITH_IMAGE);
    await page.waitForTimeout(1200);                      // let the crossfade finish

    const visible = () => readLayers(page).then(ls => ls.find(l => l.opacity > 0.5)!);
    const a = await visible();
    await page.waitForTimeout(900);
    const b = await visible();
    await page.waitForTimeout(900);
    const c = await visible();

    expect(a.transform, 'the new chapter opened on a frozen background').not.toBe(b.transform);
    expect(b.transform, 'the drift stopped part-way through the chapter').not.toBe(c.transform);
  });

  test('the Ken Burns drift is eased as a camera move, not run at a constant rate', async ({ page }) => {
    // easing.js: "move/reposition/camera → easeInOutCubic". A drift declared `linear` is the one
    // curve the conventions rule out.
    await openPlayer(page, SCENE_WITH_IMAGE);
    const layer = (await readLayers(page)).find(l => l.opacity > 0.5)!;

    expect(layer.transformEase, 'the background has no transform transition — no drift at all').not.toBeNull();
    expect(
      layer.transformEase!.fn,
      `the ${layer.transformEase!.dur} Ken Burns drift is declared "${layer.transformEase!.fn}"`,
    ).not.toBe('linear');
  });

  // ── Quiz card ────────────────────────────────────────────────────────────────────────────

  test('the quiz card animates in rather than appearing', async ({ page }) => {
    const awaitAnimation = await recordAnimations(page);

    await page.goto(`/lesson/${CODE}?scene=${SCENE_QUIZ}`);
    await page.getByRole('button', { name: /start lesson/i }).click();

    const card = page.locator('.qz-card');
    await expect(card).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: `${SHOT}/quiz-card.png` });

    const entrance = await awaitAnimation('qz-slide-in');
    expect(entrance.source.duration, 'the entrance has no duration').toBeGreaterThan(120);

    const curves: string[] = (entrance.source.keyframesRule?.keyframes ?? []).map((k: any) => k.easing);
    expect(curves.length, 'the entrance has no keyframes').toBeGreaterThan(1);
    expect(new Set(curves).size, 'the entrance mixes curves between keyframes').toBe(1);
    expect(
      decelerates(curves[0]),
      `the quiz card arrives on "${curves[0]}", which does not decelerate to rest`,
    ).toBe(true);
  });

  test('the quiz card entrance uses the shared enter curve', async ({ page }) => {
    // The card decelerates, which is the right INTENT — but on a curve of its own, written in
    // app.css rather than taken from easing.js. One vocabulary or none.
    const awaitAnimation = await recordAnimations(page);

    await page.goto(`/lesson/${CODE}?scene=${SCENE_QUIZ}`);
    await page.getByRole('button', { name: /start lesson/i }).click();
    await expect(page.locator('.qz-card')).toBeVisible({ timeout: 20_000 });

    const entrance = await awaitAnimation('qz-slide-in');
    const curve = entrance?.source?.keyframesRule?.keyframes?.[0]?.easing;
    expect(curve, `the quiz card enters on "${curve}" instead of EASE.enter`).toBe(EASE.enter);
  });
});
