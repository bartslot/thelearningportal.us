import { test, expect, Page, Browser } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Layer BLEND MODE, ROTATE and tint STRENGTH — the three controls in the Format panel that change
 * how an artwork layer LOOKS rather than where it sits. Nothing exercised them in a browser before
 * this file, so nobody had watched a teacher pick "Screen", drag the rotate grip, or pull the tint
 * strength down and seen what the canvas actually did.
 *
 * What a teacher does here, in order:
 *   open the scene editor → Icons → drop an icon on the painting → click it → Format panel
 *   → Blend / Rotate / Tint + Strength → and expect all of it to be there tomorrow.
 *
 * Three things about this file are deliberate.
 *
 * 1. IT EDITS A THROWAWAY COPY. Adding layers to a published lesson rewrites a teacher's own work,
 *    so the parent lesson stays the source of truth and every run copies it (the same
 *    `lessons:test-copy` contract the voyage fixtures use).
 *
 * 2. IT NEVER SELECTS BY VISIBLE TEXT. The UI is translated into five languages and the signed-in
 *    dev teacher's locale is a database column — this suite has watched the wizard come up in
 *    Dutch, French and English on consecutive runs. Every control here is found by ROLE plus the
 *    attributes that define it (a slider whose range is -180…180 is the Rotate slider in any
 *    language), never by a label that translation would move.
 *
 * 3. IT MEASURES PIXELS IN THE BROWSER. "Visibly changes the canvas" is not a DOM assertion, so
 *    each step screenshots the layer and measures it — mean lightness, how red it leans, how much
 *    ring-blue is on screen — by decoding that screenshot back inside the page with a 2D canvas.
 *    No image dependency, and it reads the composited result rather than the styles we hoped would
 *    produce it. See `pixels()` for why those are averages and not a pixel-by-pixel diff.
 *
 * The compositing here is static (mix-blend-mode, a transform, an SVG filter), not an animation, so
 * the default 'chromium' project's SwiftShader flags are harmless: nothing in this file waits for a
 * transition to tick.
 */

/** Where the evidence screenshots land. Its own directory — concurrent runs share fixtures. */
const OUT = 'tests/playwright/results/layer-blend-rotate-tint';

/**
 * The lesson to inherit from. Any published lesson with a narrated scene will do, so rather than
 * name one, this asks the catalogue and takes something NOBODY ELSE IS USING.
 *
 * That last part is not fussiness. `lessons:test-copy` keeps one child per parent and force-deletes
 * the previous one, so two suites copying the same parent delete each other's lesson mid-run — this
 * spec watched its own lesson turn into a 404 halfway through because another session copied the
 * same fixture. Steering away from the two lessons global setup already exported keeps the common
 * case collision-free; LAYER_LESSON_PARENT pins one by hand when it isn't.
 */
const PINNED_PARENT = process.env.LAYER_LESSON_PARENT ?? '';
const TAKEN = new Set([process.env.PW_LESSON_ID, process.env.VOYAGE_LESSON_ID].filter(Boolean));

type Copy = { id: number; code: string };

let browser: Browser;
let page: Page;
let lesson: Copy;
let sceneId: number;
/** The object id of the icon layer under test, e.g. "art_196". */
let iconLayer: string;

test.describe.configure({ mode: 'serial' });

/** Something no other run will guess, used to claim this run's lesson and to clean it up after. */
const RUN_TAG = `layer-blend-${process.pid}-${Date.now()}`;

/**
 * A fresh child of the parent lesson, so the run starts from known-clean shots.
 *
 * `lessons:test-copy` keeps ONE child per parent and force-deletes the previous one, which is right
 * for a fixture a single suite owns and fatal when two suites are running: the second copy of the
 * same parent deletes the first suite's lesson while it is still using it. So the child is re-marked
 * with this run's own tag the moment it exists — nobody else's cleanup can match that — and
 * `afterAll` deletes it, which is the bookkeeping the shared marker used to do.
 */
function freshCopy(parent: string): Copy {
  const out = execFileSync('php', ['artisan', 'lessons:test-copy', parent, '--json'], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const child = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!) as Copy;
  artisanEval(
    `$l = App\\Models\\Lesson::find(${child.id});`
    + `$c = $l->game_config; $c['test_copy_of'] = '${RUN_TAG}'; $l->update(['game_config' => $c]);`
  );

  return child;
}

/** Run one statement through tinker. Used only for fixture bookkeeping, never for assertions. */
function artisanEval(php: string) {
  try {
    execFileSync('php', ['artisan', 'tinker', '--execute', php], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  } catch {
    // Bookkeeping only: a failure here costs a stray test lesson, never a wrong test result.
  }
}

/**
 * Open the scene editor on one scene, and wait until the stage has actually painted it.
 *
 * The click on the scene thumbnail is not decoration. On a cold load the wizard stage comes up
 * EMPTY — no background, no layers — and stays empty however long you wait; re-selecting a scene is
 * what makes it paint. See the finding filed with this spec. Everything below needs a painted
 * stage, so the workaround lives here rather than being repeated in five tests.
 */
async function openScene(target: number) {
  const res = await page.goto(`/teacher/lessons/${lesson.id}/wizard?step=4&scene=${target}`);
  expect(
    res?.status(),
    `lesson #${lesson.id} vanished mid-run — another suite copied the same parent and deleted it. `
    + 'Pin an unused one with LAYER_LESSON_PARENT.'
  ).toBeLessThan(400);
  await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => {});   // the dev server is shared; never block on it
  await page.locator(`[data-scene-id="${target}"]`).click();
  await page.waitForFunction(() => !!(window as any).__lessonStage, null, { timeout: 30_000 });
}

/** The Format panel as it looks while a layer is selected: one inspector, keyed by asset id. */
const inspector = () => page.locator('[wire\\:key^="layer-inspector-"]');

/** Blend is the only <select> in the panel offering the CSS blend modes. */
const blendSelect = () => inspector().locator('select').filter({ has: page.locator('option[value="multiply"]') });

/** Rotate: degrees, so it is the slider that runs -180…180. */
const rotateSlider = () => inspector().getByRole('slider').and(page.locator('[min="-180"][max="180"]'));

/** Tint strength: 0…1 in twentieths. Opacity starts at 0.05, so the two never collide. */
const strengthSlider = () => inspector().getByRole('slider').and(page.locator('[min="0"][max="1"][step="0.05"]'));

const tintColour = () => inspector().locator('input[type="color"]');

/**
 * Select a layer on the canvas the way a teacher does — by clicking it.
 *
 * The wait first: a layer replays its entrance whenever the stage loads, and Playwright refuses to
 * click a moving element, so without it every selection burns most of a minute in click retries.
 */
async function selectLayer(id: string) {
  await atRest(id);
  await page.locator(`[data-layer-id="${id}"]`).click();
  await expect(page.locator(`[data-layer-chrome="${id}"]`)).toBeVisible();
  await expect(inspector()).toHaveCount(1);
}

type Pixels = {
  /** mean channel values over the clip */
  r: number; g: number; b: number;
  /** pixels close to #38bdf8 — the selection ring and its handles */
  ring: number;
};

/** Overall lightness of a patch. Screen can only raise it; Multiply can only lower it. */
const mean = (p: Pixels) => (p.r + p.g + p.b) / 3;

/** How red a patch leans. A red tint has to push this up, and push it up further at full strength. */
const redness = (p: Pixels) => p.r - (p.g + p.b) / 2;

/**
 * Screenshot a fixed box on the stage and measure its colour.
 *
 * The box is fixed (never the layer's own bounding box) because rotation changes that box — a
 * before/after comparison has to look at the same patch of stage both times.
 *
 * WHY COLOUR AVERAGES AND NOT A PIXEL DIFF. The editor stage never holds still: the background runs
 * a permanent Ken Burns drift, so a fixed patch of it differs from itself by 2–11% of its pixels
 * from one frame to the next with nothing touched. A "how many pixels moved" comparison therefore
 * measures the drift, not the setting — measured on this suite's own fixture, an idle stage scored
 * 10.2% and an actual 45° rotation scored 20.8%, which is not a gap anything should be asserted on.
 * The channel averages barely move under that drift (well under one unit), while Screen lifted the
 * mean by 8 and a full red tint moved the redness by 13. So the measurements below are averages,
 * and the things a drift would drown out are asserted on the layer's geometry instead.
 */
type Clip = { x: number; y: number; width: number; height: number };

/**
 * Measure the patch, but only once the measurement has stopped moving.
 *
 * Saving a setting bumps the scene's `updated_at`, which cache-busts the background's URL, which
 * makes the stage re-fetch and re-paint it — so for a beat after every change the patch can be a
 * blank frame with no backdrop in it at all. One run read a mean of 249 (near white) immediately
 * after switching to Multiply, a mode that cannot lighten anything: it had measured the gap.
 *
 * So this settles on the numbers it is about to assert on. Two consecutive readings that agree to
 * within a unit mean the repaint is over — and they still agree under the Ken Burns drift, which is
 * exactly the property that makes averages the right measurement here in the first place.
 */
async function pixels(clip: Clip, name?: string): Promise<Pixels> {
  let prev = await sample(clip);
  for (let i = 0; i < 8; i++) {
    await page.waitForTimeout(300);
    const next = await sample(clip);
    const steady = Math.abs(mean(next) - mean(prev)) < 1 && Math.abs(redness(next) - redness(prev)) < 1;
    prev = next;
    if (steady) break;
  }
  if (name) await page.screenshot({ clip, path: `${OUT}/${name}.png` });

  return prev;
}

/** One reading of the patch, decoded and counted inside the page. */
async function sample(clip: Clip): Promise<Pixels> {
  const shot = await page.screenshot({ clip });

  return page.evaluate(async (b64) => {
    const img = new Image();
    img.src = `data:image/png;base64,${b64}`;
    await img.decode();
    const c = document.createElement('canvas');
    c.width = img.width;
    c.height = img.height;
    c.getContext('2d')!.drawImage(img, 0, 0);
    const d = c.getContext('2d')!.getImageData(0, 0, c.width, c.height).data;

    let r = 0, g = 0, b = 0, ring = 0;
    const n = d.length / 4;
    for (let i = 0; i < d.length; i += 4) {
      const R = d[i], G = d[i + 1], B = d[i + 2];
      r += R; g += G; b += B;
      if (Math.abs(R - 56) < 45 && Math.abs(G - 189) < 45 && Math.abs(B - 248) < 45) ring++;
    }

    return { r: r / n, g: g / n, b: b / n, ring };
  }, shot.toString('base64'));
}

/**
 * Wait until the layer has stopped animating, rather than sleeping and hoping.
 *
 * Every one of these settings saves through Livewire, and the save re-seeds the overlay and REPLAYS
 * the layer's entrance — so for a moment after a slider moves, the layer is animating for reasons
 * that have nothing to do with the setting, and a measurement taken then reads a half-played frame.
 * The entrance is a Web Animation, so the layer can be asked directly whether it is still running.
 */
async function atRest(id: string) {
  await page.waitForFunction((layerId) => {
    const node = document.querySelector(`[data-layer-id=${JSON.stringify(layerId)}]`);

    return !!node && node.getAnimations({ subtree: true })
      .every((a) => a.playState === 'finished' || a.playState === 'idle');
  }, id, { timeout: 15_000 });
}

/** A fixed square of stage centred on a layer, big enough to hold it through a 45° turn. */
async function boxAround(id: string, half: number) {
  const bb = (await page.locator(`[data-layer-id="${id}"]`).boundingBox())!;

  return {
    x: Math.round(bb.x + bb.width / 2 - half),
    y: Math.round(bb.y + bb.height / 2 - half),
    width: half * 2,
    height: half * 2,
  };
}

test.beforeAll(async ({ browser: b }) => {
  test.setTimeout(240_000);
  browser = b;
  page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

  const candidates: string[] = [];
  if (PINNED_PARENT) {
    candidates.push(PINNED_PARENT);
  } else {
    const manifest = await (await page.request.get('/api/v1/catalog/manifest')).json();
    for (const l of (manifest.lessons ?? []) as any[]) {
      if (l.status !== 'published' || !(l.counts?.scenes > 0)) continue;
      if (TAKEN.has(String(l.id)) || String(l.title ?? '').startsWith('[test]')) continue;
      candidates.push(String(l.id));
    }
    // Shuffled, not sorted. Any order that two suites both compute lands them on the same parent,
    // and the loser's lesson is force-deleted out from under it mid-run — which is exactly what
    // happened while this file was being written, twice, on the two lessons everything defaults to.
    // Picking at random from everything published makes that a coincidence rather than the norm.
    candidates.sort(() => Math.random() - 0.5);
  }
  expect(candidates.length, 'the catalogue has no published lesson to copy').toBeGreaterThan(0);

  // Ask the page which scene to work on rather than naming one: the wizard already inlines every
  // scene for its own first paint, and a narrated scene with a background gives the layer something
  // to blend WITH — which is the whole point of the controls under test. It also has to start with
  // NO artwork on it, or the layer this spec adds lands on top of somebody else's and the two
  // fight over every click.
  const empty = (s: any) => !(s.shots ?? []).some((shot: any) => (shot.layers ?? []).some((l: any) => l.asset_id));
  for (const parent of candidates.slice(0, 3)) {
    lesson = freshCopy(parent);
    await page.goto(`/teacher/lessons/${lesson.id}/wizard?step=4`);
    const scenes = JSON.parse(await page.locator('#step3-scenes-data').textContent() ?? '[]');
    const target = scenes.find((s: any) => s.kind === 'narration' && s.image_path && empty(s));
    if (target) {
      sceneId = target.id;
      break;
    }
  }
  expect(sceneId, 'no copied lesson had a bare narrated scene with a background to put a layer on').toBeTruthy();

  await openScene(sceneId);

  // Drop an icon on the scene: the dock's Icons tab, then the first icon in the grid. The dock's
  // tablist is the second on the page (the first is the panel's Format/Animate pair).
  await page.getByRole('tablist').nth(1).getByRole('tab').first().click();
  await page.locator('[wire\\:key^="icon-"]').first().click();
  const node = page.locator('[data-layer-id^="art_"]');
  await node.first().waitFor({ timeout: 30_000 });
  iconLayer = (await node.first().getAttribute('data-layer-id'))!;
  expect(await node.count(), 'the scene should hold exactly the one layer this spec put there').toBe(1);
});

test.afterAll(async () => {
  await page?.close();
  // This run owns its lesson outright, so it also has to take it away again.
  artisanEval(`App\\Models\\Lesson::withTrashed()->where('game_config->test_copy_of', '${RUN_TAG}')->get()->each->forceDelete();`);
});

test('Blend takes the layer out of its box, and is still set after a reload', async () => {
  test.setTimeout(240_000);
  await selectLayer(iconLayer);
  const clip = await boxAround(iconLayer, 170);

  await atRest(iconLayer);
  const plain = await pixels(clip, '01-blend-none');

  // "Screen (drops black)" is the honest test of whether a blend reaches the compositor: the icons
  // are black line art, so Screen has to make the icon disappear into whatever is behind it.
  //
  // The assertions are the blend modes' own arithmetic rather than a threshold tuned to one
  // backdrop — scenes here range from a bright medieval map to a near-black photograph, and a fixed
  // "this many dark pixels" number only ever describes the lesson it was written against. Screen
  // can never darken a pixel and Multiply can never lighten one, whatever is underneath.
  await blendSelect().selectOption('screen');
  await expect(page.locator(`[data-layer-id="${iconLayer}"]`)).toHaveJSProperty('style.mixBlendMode', 'screen');
  await atRest(iconLayer);
  const lit = await pixels(clip, '02-blend-screen');

  expect(mean(lit), `Screen lightens the stage and never darkens it (${mean(plain).toFixed(1)} → ${mean(lit).toFixed(1)})`)
    .toBeGreaterThan(mean(plain) + 3);

  // Multiply is the mode teachers actually reach for, and it must round-trip through the server.
  await blendSelect().selectOption('multiply');
  await expect(page.locator(`[data-layer-id="${iconLayer}"]`)).toHaveJSProperty('style.mixBlendMode', 'multiply');
  await atRest(iconLayer);
  const dark = await pixels(clip, '03-blend-multiply');

  expect(mean(dark), `Multiply brings the ink back (${mean(lit).toFixed(1)} → ${mean(dark).toFixed(1)})`)
    .toBeLessThan(mean(lit) - 3);

  // Saved? The panel saves on `change`, which a select fires on selection — so a reload is the
  // only proof that the value reached the database rather than only the canvas.
  await page.waitForTimeout(1500);
  await openScene(sceneId);
  await selectLayer(iconLayer);

  await expect(blendSelect(), 'the reloaded panel remembers the blend').toHaveValue('multiply');
  await expect(page.locator(`[data-layer-id="${iconLayer}"]`)).toHaveJSProperty('style.mixBlendMode', 'multiply');
});

test('the selection ring and handles never inherit the blend', async () => {
  test.setTimeout(240_000);
  await selectLayer(iconLayer);
  const clip = await boxAround(iconLayer, 170);

  // House rule: blend, opacity and blur apply to the layer's CONTENT. A child cannot opt out of an
  // ancestor's blend, so the ring and handles have to be rendered as a SIBLING of the blended node —
  // otherwise a Multiply layer over a dark map takes its own grab handles with it.
  const chrome = await page.evaluate((id) => {
    const node = document.querySelector(`[data-layer-id="${id}"]`)!;
    const ring = document.querySelector(`[data-layer-chrome="${id}"]`)!;
    const cs = getComputedStyle(ring);

    return {
      nodeBlend: (node as HTMLElement).style.mixBlendMode,
      insideNode: node.contains(ring),
      sibling: node.parentElement === ring.parentElement,
      ringBlend: cs.mixBlendMode,
      ringFilter: cs.filter,
      ringOpacity: cs.opacity,
    };
  }, iconLayer);

  expect(chrome.nodeBlend, 'the layer really is blending').toBe('multiply');
  expect(chrome.insideNode, 'chrome inside the blended node would be blended with it').toBe(false);
  expect(chrome.sibling, 'chrome is a sibling of the layer node').toBe(true);
  expect(chrome.ringBlend).toBe('normal');
  expect(chrome.ringFilter).toBe('none');
  expect(chrome.ringOpacity).toBe('1');

  // And legible on the glass, not just in the styles: the ring is still #38bdf8 on screen.
  const shown = await pixels(clip, '04-ring-over-multiply');
  expect(shown.ring, 'the sky-blue ring and handles survive the blend').toBeGreaterThan(200);
});

test('Rotate turns the layer and its handles together, and survives a reload', async () => {
  test.setTimeout(240_000);
  await selectLayer(iconLayer);
  const clip = await boxAround(iconLayer, 170);

  await expect(rotateSlider(), 'a new layer starts unturned').toHaveValue('0');
  await atRest(iconLayer);
  await pixels(clip, '05-rotate-0');
  const uprightBox = (await page.locator(`[data-layer-id="${iconLayer}"]`).boundingBox())!;

  await rotateSlider().fill('45');

  const turned = await page.evaluate((id) => ({
    node: (document.querySelector(`[data-layer-id="${id}"]`) as HTMLElement).style.transform,
    ring: (document.querySelector(`[data-layer-chrome="${id}"]`) as HTMLElement).style.transform,
  }), iconLayer);

  expect(turned.node, 'the layer turns about its own middle').toContain('rotate(45deg)');
  expect(turned.ring, 'the ring and handles turn with it, or they stop framing the layer').toBe(turned.node);

  // Turned 45°, the axis-aligned box the layer occupies on the stage has to GROW. This is the
  // rendered geometry, read back from the live layout — not the style string that asked for it — and
  // it is what a colour average cannot see: turning a shape moves its pixels without changing how
  // many dark ones there are. The paired screenshots below carry the visual half of the proof.
  const turnedBox = (await page.locator(`[data-layer-id="${iconLayer}"]`).boundingBox())!;
  expect(
    turnedBox.width,
    `the turned layer covers a wider stretch of stage (${uprightBox.width.toFixed(0)}px → ${turnedBox.width.toFixed(0)}px)`
  ).toBeGreaterThan(uprightBox.width * 1.1);
  expect(turnedBox.height, 'and a taller one').toBeGreaterThan(uprightBox.height * 1.1);

  await atRest(iconLayer);
  await pixels(clip, '06-rotate-45');

  await page.waitForTimeout(1500);
  await openScene(sceneId);
  await selectLayer(iconLayer);

  await expect(rotateSlider(), 'the reloaded panel remembers the angle').toHaveValue('45');
  await expect(page.locator(`[data-layer-id="${iconLayer}"]`))
    .toHaveJSProperty('style.transform', 'translate(calc(-50% + 0px), calc(-50% + 0px)) rotate(45deg)');
});

/**
 * CHARACTERISATION, NOT APPROVAL. Tint is a duotone: the layer is drained of colour and its
 * LUMINANCE is mapped onto the chosen hue. Black has no luminance, so black stays black at any
 * strength — and every icon in the Icons panel is black line art. A teacher who picks an icon,
 * chooses a bright colour and pulls Strength to the top sees nothing happen.
 *
 * This test pins that behaviour so the day someone fixes it, this file says so out loud. The test
 * below proves the same control works on an image layer, so the pipeline is sound; it is the
 * line-art case that has nothing to work with.
 */
test('tint has nothing to bite on with a black line-art icon (known gap)', async () => {
  test.setTimeout(240_000);
  await openScene(sceneId);
  await selectLayer(iconLayer);

  // Take the blend back off so nothing but the tint can move the pixels.
  await blendSelect().selectOption('normal');
  await expect(page.locator(`[data-layer-id="${iconLayer}"]`)).toHaveJSProperty('style.mixBlendMode', '');
  const clip = await boxAround(iconLayer, 170);
  await atRest(iconLayer);
  const before = await pixels(clip, '11-icon-untinted');

  await tintColour().fill('#ff2d2d');
  await expect(strengthSlider()).toHaveValue('1');
  await atRest(iconLayer);
  const after = await pixels(clip, '12-icon-tinted-full');

  // The filter really is applied…
  const applied = await page.evaluate((id) => {
    const img = document.querySelector(`[data-layer-id="${id}"] img`) as HTMLImageElement;
    const fx = document.querySelector(`#lyrfx-${id.replace('art_', '')}`);

    return { filter: img.style.filter, markup: fx ? fx.innerHTML.replace(/\s+/g, ' ') : null };
  }, iconLayer);
  expect(applied.filter, 'the icon layer is running the colour filter').toContain('lyrfx-');
  expect(applied.markup, 'with the chosen colour in the matrix').toContain('feColorMatrix');

  // …and yet the canvas does not change, because the ink has no luminance to recolour. For scale:
  // the same full-strength red on an image layer, in the test below, moves this number by about 13.
  expect(
    redness(after) - redness(before),
    'a full-strength red tint leaves the icon exactly as black as it was '
    + `(redness ${redness(before).toFixed(2)} → ${redness(after).toFixed(2)})`
  ).toBeLessThan(1);
});

test('tint Strength repaints an image layer, and a lower strength paints it less', async () => {
  test.setTimeout(240_000);

  // Tint needs a layer with tones in it (see the test above for why an icon will not do), so add
  // one the way a teacher would: Icons ▸ Import…, which lists the images they have already used.
  await openScene(sceneId);
  await page.getByRole('tablist').nth(1).getByRole('tab').first().click();
  await page.locator('button[x-on\\:click*="open-svg-library"]').click();
  const library = page.locator('.modal-open');
  await expect(library).toBeVisible();
  const artworks = library.locator('img');
  const count = await artworks.count();
  test.skip(count === 0, 'this teacher has no imported artwork to tint — nothing to test here');

  const before = await page.locator('[data-layer-id^="art_"]').count();
  await artworks.first().click();
  await expect(page.locator('[data-layer-id^="art_"]')).toHaveCount(before + 1, { timeout: 30_000 });
  const ids = await page.locator('[data-layer-id^="art_"]').evaluateAll(
    (ns) => ns.map((n) => (n as HTMLElement).dataset.layerId!)
  );
  const painting = ids.find((id) => id !== iconLayer)!;

  await selectLayer(painting);
  const clip = await boxAround(painting, 140);
  await atRest(painting);
  const plain = await pixels(clip, '07-tint-off');

  // A colour picker saves on `change` — "when the picker closes" — while the canvas follows the drag
  // live. fill() fires both, which is the state a teacher leaves the picker in.
  await tintColour().fill('#ff2d2d');
  await expect(strengthSlider(), 'a fresh tint arrives at full strength').toHaveValue('1');
  await atRest(painting);
  const full = await pixels(clip, '08-tint-full');

  expect(redness(full), `full strength must recolour the artwork (redness ${redness(plain).toFixed(1)} → ${redness(full).toFixed(1)})`)
    .toBeGreaterThan(redness(plain) + 3);

  await strengthSlider().fill('0.25');
  await atRest(painting);
  const weak = await pixels(clip, '09-tint-quarter');

  expect(redness(weak), 'a quarter strength is weaker than full').toBeLessThan(redness(full));
  expect(redness(weak), 'but still stronger than no tint at all — it is a wash, not an on/off switch')
    .toBeGreaterThan(redness(plain) + 0.5);

  // The strength is carried by the layer's own SVG filter, blending the recoloured copy back over
  // the untouched one. If the k2 term is missing the slider is decorative.
  const markup = await page.evaluate((id) => {
    const fx = document.querySelector(`#lyrfx-${id.replace('art_', '')}`);

    return fx ? fx.innerHTML.replace(/\s+/g, ' ') : null;
  }, painting);
  expect(markup, 'the layer carries its own colour filter').toContain('feComposite');
  expect(markup, 'and the composite is weighted by the strength the teacher chose').toContain('k2="0.25"');

  await page.waitForTimeout(1500);
  await openScene(sceneId);
  await selectLayer(painting);

  await expect(tintColour(), 'the reloaded panel remembers the colour').toHaveValue('#ff2d2d');
  await expect(strengthSlider(), 'the reloaded panel remembers the strength').toHaveValue('0.25');
  const reloaded = await pixels(await boxAround(painting, 140), '10-tint-after-reload');
  expect(redness(reloaded), 'and the canvas comes back tinted').toBeGreaterThan(redness(plain) + 0.5);
});
