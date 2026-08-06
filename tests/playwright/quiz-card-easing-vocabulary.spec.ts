import { test, expect, type Page } from '@playwright/test';

/**
 * Does the quiz card's entrance curve actually make it the odd one out?
 *
 * A motion-lane finding says the quiz card enters on cubic-bezier(0.16, 1, 0.3, 1) (app.css:1265)
 * rather than EASE.enter from resources/js/easing.js, and that the cost is drift: "the quiz is now
 * the one surface whose entrance does not match the rest of the app, so tuning EASE.enter in
 * easing.js will visibly change every other reveal and leave the quiz card behind."
 *
 * That is a claim about the WHOLE app, not about the quiz — so this spec measures the whole app.
 * It reads every declared curve out of the live stylesheet in a real browser and asks:
 *
 *   1. Does a student see the quiz card animate in, decelerating?              (is anything broken)
 *   2. How many CSS rules in the shipped bundle use EASE.enter?                (what would tuning move)
 *   3. Do the app's OTHER CSS entrance animations use the quiz card's curve?   (is the quiz an outlier)
 *
 * Run WITHOUT SwiftShader — under it CSS animations snap to their end frame and never report a
 * curve to the Animation domain at all:
 *
 *   npx playwright test --config=tests/playwright/quiz-card-easing-vocabulary.config.ts
 */

const CODE = process.env.MOTION_LESSON_CODE ?? 'CF0AVG';
const SCENE_QUIZ = 21;                               // "What do you already know?" in CF0AVG
const SHOT = 'tests/playwright/results-quiz-card-easing';

/** resources/js/easing.js — the semantic aliases, as Chrome serialises them. */
const EASE = {
  enter: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
  exit: 'cubic-bezier(0.55, 0.055, 0.675, 0.19)',
  move: 'cubic-bezier(0.645, 0.045, 0.355, 1)',
  pop: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
};

/** The curve the finding objects to. */
const QUIZ_CARD_CURVE = 'cubic-bezier(0.16, 1, 0.3, 1)';

/** Fast start, soft landing — what "enter" means, whatever the exact control points. */
function decelerates(curve: string): boolean {
  const m = curve.match(/cubic-bezier\(([-\d.]+),\s*([-\d.]+),/);
  if (!m) return false;
  return Number(m[2]) > Number(m[1]);
}

async function recordAnimations(page: Page) {
  const cdp = await page.context().newCDPSession(page);
  const started: any[] = [];
  await cdp.send('Animation.enable');
  cdp.on('Animation.animationStarted', ({ animation }) => started.push(animation));

  return async (name: string) => {
    await expect
      .poll(() => started.some((a) => a.name === name), {
        timeout: 10_000,
        message: `no "${name}" animation was ever declared`,
      })
      .toBe(true);
    return started.find((a) => a.name === name);
  };
}

/**
 * Every cubic-bezier the shipped stylesheet declares, with the selector that declares it and
 * whether it rides an `animation` or a `transition`. Read from the live document rather than by
 * grepping source, so what is measured is what the browser actually loaded.
 */
type Decl = { selector: string; curve: string; via: 'animation' | 'transition' };

async function readDeclaredCurves(page: Page): Promise<Decl[]> {
  // HARNESS TRAP, hit on the first run of this spec: in dev, Vite serves the stylesheet from
  // port 5173 while the page is on 8000, so every sheet is cross-origin and `sheet.cssRules`
  // throws — the survey silently reported zero curves in a bundle that has 53. So collect the
  // sheet URLs from the page, then fetch the text and parse it. Same bytes the browser loaded.
  const sources = await page.evaluate(() =>
    [...document.querySelectorAll('link[rel="stylesheet"]')].map((l) => (l as HTMLLinkElement).href)
      .concat([...document.querySelectorAll('style')].map((s) => 'inline:' + s.textContent)),
  );

  let css = '';
  for (const src of sources) {
    if (src.startsWith('inline:')) { css += src.slice(7) + '\n'; continue; }
    const res = await page.request.get(src);
    if (res.ok()) css += (await res.text()) + '\n';
  }

  const out: Decl[] = [];
  // Walk `<selector> { <body> }` blocks. Nested at-rules (@layer/@media) leave their body as the
  // next block's prefix, which only ever makes the selector label noisier, never the count wrong.
  for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = m[1].trim().split('\n').pop()!.trim();
    const body = m[2];
    for (const line of body.split(';')) {
      const via = /animation/.test(line) ? 'animation' : /transition/.test(line) ? 'transition' : null;
      if (!via) continue;
      for (const c of line.matchAll(/cubic-bezier\([^)]*\)/g)) {
        // Normalise whitespace so "cubic-bezier(0.16,1,0.3,1)" compares equal to the spaced form.
        out.push({ selector, curve: c[0].replace(/\s+/g, '').replace(/,/g, ', '), via: via as Decl['via'] });
      }
    }
  }
  return out;
}

test.describe('Quiz card entrance — is it really the odd one out?', () => {

  test('a student sees the quiz card rise and fade in, decelerating', async ({ page }) => {
    const awaitAnimation = await recordAnimations(page);

    await page.goto(`/lesson/${CODE}?scene=${SCENE_QUIZ}`);
    await page.getByRole('button', { name: /start lesson/i }).click();

    const card = page.locator('.qz-card');
    await expect(card).toBeVisible({ timeout: 30_000 });
    await page.screenshot({ path: `${SHOT}/quiz-card-student-view.png` });

    const entrance = await awaitAnimation('qz-slide-in');
    const curves: string[] = (entrance.source.keyframesRule?.keyframes ?? []).map((k: any) => k.easing);

    // `source.easing` always reports "linear" for a CSSAnimation; the real curve is per keyframe.
    expect(curves.length, 'the entrance has no keyframes').toBeGreaterThan(1);
    expect(new Set(curves).size, 'the entrance mixes curves between keyframes').toBe(1);
    expect(entrance.source.duration, 'the entrance has no duration').toBeGreaterThan(120);
    expect(
      decelerates(curves[0]),
      `the quiz card arrives on "${curves[0]}", which does not decelerate to rest`,
    ).toBe(true);

    console.log(`[quiz card] ${entrance.source.duration}ms on ${curves[0]}`);
  });

  test('tuning EASE.enter would move nothing in CSS — no rule in the bundle uses it', async ({ page }) => {
    // The finding's stated cost is that "tuning EASE.enter in easing.js will visibly change every
    // other reveal and leave the quiz card behind". That is only true if some CSS reveal is on
    // EASE.enter today. easing.js is a JS module exporting JS strings, and there is no
    // --ease-* custom property bridging it into the stylesheet, so this counts what is actually
    // there.
    await page.goto('/');
    await expect(page.locator('body')).toBeVisible();

    const declared = await readDeclaredCurves(page);
    expect(declared.length, 'no curves were readable from the stylesheet').toBeGreaterThan(10);

    const onEnter = declared.filter((d) => d.curve === EASE.enter);
    const tally = new Map<string, number>();
    for (const d of declared) tally.set(d.curve, (tally.get(d.curve) ?? 0) + 1);
    console.log(
      '[declared CSS curves]\n' +
      [...tally.entries()].sort((a, b) => b[1] - a[1]).map(([c, n]) => `  ${String(n).padStart(3)}  ${c}`).join('\n'),
    );

    // Every CSS appearance of EASE.enter's curve is a Tailwind ARBITRARY-VALUE utility —
    // `.ease-[cubic-bezier(0.215,0.61,0.355,1)]` — i.e. the control points are typed by hand into
    // a class name in a blade (language-picker, help, welcome-tour). Those do not import
    // easing.js any more than app.css does. So no CSS rule anywhere derives its curve from the
    // easing vocabulary, and editing EASE.enter in easing.js repaints exactly nothing in CSS.
    const fromVocabulary = onEnter.filter((d) => !/\.ease-/.test(d.selector));
    expect(
      fromVocabulary.length,
      `a CSS rule derives its curve from easing.js: ${fromVocabulary.map((d) => d.selector).join(', ')}`,
    ).toBe(0);

    // Sanity: the literal does appear, as a hand-typed Tailwind utility and nothing more.
    console.log(`[EASE.enter in CSS] ${onEnter.length} hit(s), all arbitrary-value utilities`);
  });

  test('the quiz card shares its curve with the app\'s other CSS entrances', async ({ page }) => {
    // If the quiz card were the outlier the finding describes, its curve would appear once in the
    // whole bundle. Count it.
    await page.goto('/');
    await expect(page.locator('body')).toBeVisible();

    const declared = await readDeclaredCurves(page);
    const siblings = declared.filter((d) => d.curve === QUIZ_CARD_CURVE);
    console.log(
      `[${QUIZ_CARD_CURVE}] used by ${siblings.length} rule(s):\n` +
      siblings.map((d) => `  ${d.via.padEnd(10)} ${d.selector}`).join('\n'),
    );

    expect(
      siblings.length,
      `the quiz card's curve appears ${siblings.length}x — it would be an outlier at 1`,
    ).toBeGreaterThan(1);

    // And specifically: the site header's entrance, another reveal, is on the very same curve.
    expect(
      siblings.some((d) => /site-header__logo|site-nav/.test(d.selector)),
      'the site header entrance does not share the quiz card\'s curve',
    ).toBe(true);
  });

  test('the site header entrance really runs on that curve in a real browser', async ({ page }) => {
    // Not just declared — observed. The header rise is the closest analogue to the quiz card:
    // an element entering the page, in CSS, with a translate + fade.
    const awaitAnimation = await recordAnimations(page);
    await page.goto('/');

    const header = await awaitAnimation('site-header-rise');
    const curves: string[] = (header.source.keyframesRule?.keyframes ?? []).map((k: any) => k.easing);
    await page.screenshot({ path: `${SHOT}/site-header-entrance.png` });

    console.log(`[site header] ${header.source.duration}ms on ${curves[0]}`);
    expect(curves[0], 'the header entrance is not on the quiz card\'s curve').toBe(QUIZ_CARD_CURVE);
  });
});
