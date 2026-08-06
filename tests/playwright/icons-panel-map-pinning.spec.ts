import { test, expect, request, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * The Icons panel (the dock tab that replaced "Clipart") and map pinning, driven the way a
 * teacher drives them: open the lesson, pick the Icons tab under the stage, click or drag an
 * icon onto the scene, move it, tint it, and — on a voyage scene — stick it to a place so it
 * rides the map through a pan and a zoom.
 *
 * FIXTURE. Every test here WRITES to the lesson (an icon is a layer, saved on the scene), so it
 * runs against a throwaway copy of the parent rather than the teacher's own lesson — the same
 * bargain `tests/playwright/support/global-setup.ts` strikes for the voyage specs. Point
 * ICONS_PARENT_LESSON_ID at another lesson to inherit from a different one; it needs at least
 * one narration scene with a background and one voyage scene.
 *
 * NAVIGATION. Scenes are opened by clicking their tile in the rail, never by the ?scene= query
 * parameter: a cold load deep-linked to a scene leaves the stage blank here (no background, no
 * layers, no `.wizard-artwork-host` ever created), which is a separate defect and would make
 * every assertion below look like an icon bug. See the report.
 *
 * LANGUAGE. The dev teacher's stored locale is whatever the last person left it as — this run
 * saw the same wizard in English, French and Dutch — so every visible label is matched against
 * all five shipped translations rather than the English one.
 */

const PARENT = process.env.ICONS_PARENT_LESSON_ID ?? '41';

/** Tab / button labels in the five shipped languages (en, nl, de, fr, it). */
const ICONS_TAB = /^\s*(Icons|Iconen|Icônes|Icone)\s*$/;
const NO_TINT = /^\s*(No tint|Geen kleur|Keine Farbe|Sans teinte|Nessuna tinta)\s*$/;
const LINE_ART = /^\s*(Line art|Lijntekeningen|Strichzeichnungen|Dessins au trait|Disegni al tratto)\s*$/;

/**
 * The two overlays that can draw an icon layer, and why every locator below has to name one.
 *
 * A voyage scene keeps its layers in the map overlay so they can be pinned; a slideshow scene
 * keeps them in the stage overlay. On a voyage scene BOTH are seeded with the same layers — the
 * stage copy is simply hidden behind the map preview — so a bare [data-layer-id="art_N"] matches
 * two nodes carrying the same id. See the report; until that is fixed, say which host you mean.
 */
const MAP_HOST = '#lesson-voyage-art';
const STAGE_HOST = '.wizard-artwork-host';

const layerNode = (page: Page, host: string, assetId: number) =>
  page.locator(`${host} [data-layer-id="art_${assetId}"]`);

type Fixture = { id: number; code: string; narration: number; voyage: number[] };
let lesson: Fixture;

/** The kind of every scene, read from the payload the player page inlines. */
async function scenesOf(code: string, baseURL: string) {
  const ctx = await request.newContext({ baseURL });
  const html = await (await ctx.get(`/lesson/${code}`)).text();
  await ctx.dispose();

  const start = html.indexOf('"scenes":[');
  if (start === -1) return [] as Array<{ id: number; kind: string }>;
  let depth = 0;
  let end = -1;
  const open = html.indexOf('[', start);
  for (let i = open; i < html.length; i++) {
    if (html[i] === '[') depth++;
    else if (html[i] === ']' && --depth === 0) { end = i + 1; break; }
  }

  return end === -1 ? [] : (JSON.parse(html.slice(open, end)) as Array<{ id: number; kind: string }>);
}

test.beforeAll(async ({ baseURL }) => {
  const out = execFileSync('php', ['artisan', 'lessons:test-copy', PARENT, '--json'], { encoding: 'utf8' });
  const child = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!) as { id: number; code: string };

  const scenes = await scenesOf(child.code, baseURL!);
  const narration = scenes.find((s) => s.kind === 'narration');
  const voyage = scenes.filter((s) => s.kind === 'voyage');

  expect(narration, `lesson #${PARENT} has no narration scene to drop an icon on`).toBeTruthy();
  expect(voyage.length, `lesson #${PARENT} has no voyage scene to pin an icon to`).toBeGreaterThan(1);

  lesson = { id: child.id, code: child.code, narration: narration!.id, voyage: voyage.map((s) => s.id) };
  // eslint-disable-next-line no-console
  console.log(`[icons] editing a fresh copy of #${PARENT} → #${lesson.id} ${lesson.code}`);
});

/** Open the scene editor and put `sceneId` on stage by clicking its tile in the rail. */
async function openScene(page: Page, sceneId: number, kind: 'narration' | 'voyage') {
  await page.goto(`/teacher/lessons/${lesson.id}/wizard?step=4`);
  // The canvas bridge mounts after three.js boots — tens of seconds under SwiftShader.
  await page.waitForFunction(() => (window as any).__lessonTextLayer !== undefined, null, { timeout: 180_000 });
  await page.locator(`[data-scene-id="${sceneId}"]`).click();
  // Wait for the STAGE to say a scene of this kind arrived, rather than for a fixed number of
  // seconds: the dev server answers one request at a time, so a round trip can take a while under
  // load, and the wizard always opens on scene 1 (a quiz here) so this is a real transition.
  await page.waitForFunction((k) => (window as any).__objScene?.kind === k, kind, { timeout: 120_000 });
  // The stage is repainted asynchronously after that (the scene bundle is imported on demand).
  await page.waitForTimeout(2000);
}

/** Wait for the map that voyage scenes draw under the stage, and for its style to settle. */
async function waitForMap(page: Page) {
  await page.waitForFunction(
    () => { const m = (window as any).__lessonMap; return !!m && m.isStyleLoaded(); },
    null,
    // Generous: under a machine running several browser suites at once, a MapLibre style over
    // software WebGL has taken well past a minute to settle. A short wait here reads as "the map
    // never came up", which is not what is being tested.
    { timeout: 180_000 },
  );
  await page.waitForTimeout(3000);   // let the leg's opening camera move finish
}

/** Show the Icons tab of the bottom dock. */
async function openIconsTab(page: Page) {
  await page.getByRole('tab', { name: ICONS_TAB }).click();
  await expect(page.locator('[wire\\:key^="icon-"]').first()).toBeVisible({ timeout: 30_000 });
}

/** The overlay that owns the icon layers on the scene currently on stage. */
const overlayLayers = (page: Page) => page.evaluate(() => {
  const o = (window as any).__artOverlay?.() ?? (window as any).__lessonArtworkLayer;
  if (!o) return null;

  return o._layers.map((l: any) => ({
    assetId: l.asset_id,
    x: Number(l.x.toFixed(2)),
    y: Number(l.y.toFixed(2)),
    anchor: l.anchor ?? 'screen',
    lng: l.lng ?? null,
    lat: l.lat ?? null,
    tint: l.tint ?? null,
  }));
});

/** Where the icon's node actually sits on screen, and where the map says its place is. */
const pinnedGeometry = (page: Page, assetId: number) => page.evaluate((id) => {
  const node = document.querySelector(`#lesson-voyage-art [data-layer-id="art_${id}"]`) as HTMLElement | null;
  const o = (window as any).__artOverlay?.() ?? (window as any).__lessonArtworkLayer;
  const layer = o?._layers.find((l: any) => String(l.asset_id) === String(id));
  const map = (window as any).__lessonMap;
  if (!node || !layer || !map) return null;

  const r = node.getBoundingClientRect();
  const canvas = map.getCanvas().getBoundingClientRect();
  const p = map.project([layer.lng, layer.lat]);

  return {
    node: { cx: Number((r.x + r.width / 2).toFixed(1)), cy: Number((r.y + r.height / 2).toFixed(1)) },
    place: { cx: Number((canvas.x + p.x).toFixed(1)), cy: Number((canvas.y + p.y).toFixed(1)) },
    lng: layer.lng,
    lat: layer.lat,
  };
}, assetId);

/** Drag with real mouse events, so the layer's own pointer handling runs. */
async function dragTo(page: Page, from: { x: number; y: number }, to: { x: number; y: number }) {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  await page.mouse.move(to.x, to.y, { steps: 20 });
  await page.mouse.up();
}

const shot = async (page: Page, info: TestInfo, name: string) => {
  const path = info.outputPath(`${name}.png`);
  await page.screenshot({ path, fullPage: false });

  return path;
};

test.describe('Icons panel and map pinning', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(420_000);

  // The asset the tests place and then keep talking about, learned from the first placement.
  let placedOnNarration = 0;
  let pinnedOnVoyage = 0;

  test('the dock offers Icons beside Script, and the collections filter the set', async ({ page }, info) => {
    await openScene(page, lesson.narration, 'narration');

    // The dock is tabbed, and Script is the sibling — the pair that replaced the old modal.
    await expect(page.getByRole('tab', { name: ICONS_TAB })).toBeVisible();
    await expect(page.getByRole('tab', { name: /^\s*(Script|Skript|Copione)\s*$/ })).toBeVisible();

    await openIconsTab(page);

    const icons = page.locator('[wire\\:key^="icon-"]');
    const lineArt = await icons.count();
    expect(lineArt, 'the Icons tab shows the bundled set').toBeGreaterThan(10);
    // Every tile names its icon, so a teacher can find one by its tooltip / a screen reader can read it.
    await expect(icons.first()).toHaveAttribute('aria-label', /\S/);

    // Switching collection swaps the set rather than appending to it. The pill is a Livewire
    // round trip and the dev server answers one request at a time, so be patient about it —
    // 15s was not enough on a busy machine and the assertion read like a broken filter.
    await page.getByRole('button', { name: /^\s*(Arrows|Pijlen|Pfeile|Flèches|Frecce)\s*$/ }).click();
    await expect.poll(() => icons.count(), { timeout: 60_000 }).not.toBe(lineArt);
    const arrows = await icons.count();
    expect(arrows).toBeGreaterThan(0);

    await page.getByRole('button', { name: LINE_ART }).click();
    await expect.poll(() => icons.count(), { timeout: 60_000 }).toBe(lineArt);

    // eslint-disable-next-line no-console
    console.log(`[icons] line art: ${lineArt} icons, arrows: ${arrows}`, await shot(page, info, 'icons-tab'));
  });

  test('clicking an icon puts it on the scene, and it is still there after a reload', async ({ page }, info) => {
    await openScene(page, lesson.narration, 'narration');
    await openIconsTab(page);

    const before = (await overlayLayers(page))?.length ?? 0;

    const tile = page.locator('[wire\\:key^="icon-"]').first();
    const title = await tile.getAttribute('aria-label');
    await tile.click();

    // The icon becomes an ordinary layer on the stage, mid-frame.
    const node = page.locator(`${STAGE_HOST} [data-layer-id]`).first();
    await expect(node).toBeVisible({ timeout: 30_000 });

    const layers = await overlayLayers(page);
    expect(layers!.length, 'exactly one icon was added').toBe(before + 1);
    const placed = layers!.at(-1)!;
    placedOnNarration = placed.assetId;
    expect(placed.x).toBeGreaterThan(20);
    expect(placed.x).toBeLessThan(80);
    expect(placed.y).toBeGreaterThan(20);
    expect(placed.y).toBeLessThan(80);

    // eslint-disable-next-line no-console
    console.log(`[icons] placed "${title}" (asset ${placedOnNarration})`, await shot(page, info, 'placed'));

    // Reload the whole editor and come back to the scene — the icon must still be on it.
    await openScene(page, lesson.narration, 'narration');
    await expect(layerNode(page, STAGE_HOST, placedOnNarration)).toBeVisible({ timeout: 30_000 });
  });

  test('dragging the icon moves it, and the new position survives a reload', async ({ page }, info) => {
    await openScene(page, lesson.narration, 'narration');
    const node = layerNode(page, STAGE_HOST, placedOnNarration);
    await expect(node).toBeVisible({ timeout: 30_000 });

    const start = (await overlayLayers(page))!.find((l) => l.assetId === placedOnNarration)!;
    const box = (await node.boundingBox())!;
    const stage = (await page.locator('#lesson-canvas-root').boundingBox())!;

    // Drag it up and to the left, to a quarter of the way across the stage.
    await dragTo(
      page,
      { x: box.x + box.width / 2, y: box.y + box.height / 2 },
      { x: stage.x + stage.width * 0.25, y: stage.y + stage.height * 0.3 },
    );
    await page.waitForTimeout(1500);

    const moved = (await overlayLayers(page))!.find((l) => l.assetId === placedOnNarration)!;
    expect(Math.abs(moved.x - 25), 'landed where it was dropped, horizontally').toBeLessThan(6);
    expect(Math.abs(moved.y - 30), 'landed where it was dropped, vertically').toBeLessThan(6);
    expect(moved.x).not.toBeCloseTo(start.x, 1);

    // eslint-disable-next-line no-console
    console.log(`[icons] moved ${start.x},${start.y} → ${moved.x},${moved.y}`, await shot(page, info, 'moved'));

    // And the move is saved, not just drawn.
    await openScene(page, lesson.narration, 'narration');
    await expect(layerNode(page, STAGE_HOST, placedOnNarration)).toBeVisible({ timeout: 30_000 });
    const afterReload = (await overlayLayers(page))!.find((l) => l.assetId === placedOnNarration)!;
    expect(Math.abs(afterReload.x - moved.x)).toBeLessThan(2);
    expect(Math.abs(afterReload.y - moved.y)).toBeLessThan(2);
  });

  test('dropping an icon on a voyage map pins it to the place under the cursor', async ({ page }, info) => {
    await openScene(page, lesson.voyage[1], 'voyage');
    await waitForMap(page);
    await openIconsTab(page);

    const map = (await page.locator('#lesson-map-preview').boundingBox())!;
    const target = { x: map.x + map.width * 0.35, y: map.y + map.height * 0.4 };
    // What place is under that point right now? That is what the icon must claim.
    const dropped = await page.evaluate(({ x, y }) => {
      const m = (window as any).__lessonMap;
      const c = m.getCanvas().getBoundingClientRect();
      const ll = m.unproject([x - c.x, y - c.y]);

      return { lng: ll.lng, lat: ll.lat };
    }, target);

    const tile = page.locator('[wire\\:key^="icon-"]').nth(3);
    const title = await tile.getAttribute('aria-label');
    const tileBox = (await tile.boundingBox())!;
    await dragTo(page, { x: tileBox.x + tileBox.width / 2, y: tileBox.y + tileBox.height / 2 }, target);
    await page.waitForTimeout(2500);

    const layers = await overlayLayers(page);
    const pinned = layers!.at(-1)!;
    pinnedOnVoyage = pinned.assetId;

    expect(pinned.anchor, 'an icon dropped on a live map is pinned to the map').toBe('map');
    expect(Math.abs(pinned.lng! - dropped.lng), 'pinned to the longitude it was dropped on').toBeLessThan(0.2);
    expect(Math.abs(pinned.lat! - dropped.lat), 'pinned to the latitude it was dropped on').toBeLessThan(0.2);

    // eslint-disable-next-line no-console
    console.log(
      `[icons] dropped "${title}" on ${dropped.lng.toFixed(3)},${dropped.lat.toFixed(3)} → pinned at ` +
      `${pinned.lng!.toFixed(3)},${pinned.lat!.toFixed(3)}`,
      await shot(page, info, 'pinned-on-map'),
    );

    // And the pin is saved on the scene, not just held in the browser.
    await openScene(page, lesson.voyage[1], 'voyage');
    await waitForMap(page);
    const reloaded = (await overlayLayers(page))!.find((l) => l.assetId === pinnedOnVoyage)!;
    expect(reloaded.anchor).toBe('map');
    expect(Math.abs(reloaded.lng! - pinned.lng!)).toBeLessThan(0.001);
  });

  test('a pinned icon holds its place through a pan and a zoom', async ({ page }, info) => {
    await openScene(page, lesson.voyage[1], 'voyage');
    await waitForMap(page);

    const before = (await pinnedGeometry(page, pinnedOnVoyage))!;
    expect(before, 'the pinned icon and the map are both on stage').toBeTruthy();
    // It sits exactly where the map puts its place — that is what "pinned" has to mean.
    expect(Math.abs(before.node.cx - before.place.cx)).toBeLessThan(2);
    expect(Math.abs(before.node.cy - before.place.cy)).toBeLessThan(2);

    // Pan by grabbing empty map, well away from the icon and from the route handles.
    const map = (await page.locator('#lesson-map-preview').boundingBox())!;
    await dragTo(
      page,
      { x: map.x + map.width * 0.75, y: map.y + map.height * 0.8 },
      { x: map.x + map.width * 0.75 - 200, y: map.y + map.height * 0.8 - 70 },
    );
    await page.waitForTimeout(1500);

    const panned = (await pinnedGeometry(page, pinnedOnVoyage))!;
    expect(panned.lng, 'panning does not repoint the icon').toBeCloseTo(before.lng, 6);
    expect(panned.lat, 'panning does not repoint the icon').toBeCloseTo(before.lat, 6);
    expect(Math.abs(panned.node.cx - before.node.cx), 'the icon travelled with the map').toBeGreaterThan(30);
    expect(Math.abs(panned.node.cx - panned.place.cx), 'still on its place after the pan').toBeLessThan(2);
    expect(Math.abs(panned.node.cy - panned.place.cy)).toBeLessThan(2);

    // Zoom in with the map's own control.
    await page.locator('.maplibregl-ctrl-zoom-in').first().click();
    await page.waitForTimeout(2000);

    const zoomed = (await pinnedGeometry(page, pinnedOnVoyage))!;
    expect(zoomed.lng).toBeCloseTo(before.lng, 6);
    expect(Math.abs(zoomed.node.cx - zoomed.place.cx), 'still on its place after the zoom').toBeLessThan(2);
    expect(Math.abs(zoomed.node.cy - zoomed.place.cy)).toBeLessThan(2);

    // eslint-disable-next-line no-console
    console.log(
      `[icons] pin held: ${JSON.stringify(before.node)} → ${JSON.stringify(panned.node)} → ${JSON.stringify(zoomed.node)}`,
      await shot(page, info, 'pin-holds'),
    );
  });

  test('one icon is drawn once', async ({ page }) => {
    // KNOWN DEFECT — see the report. On a voyage scene the stage overlay is seeded with the same
    // layers as the map overlay, so the icon exists twice under the same data-layer-id (and, once
    // tinted, twice under the same <filter id>). Only one is on screen — the other sits behind the
    // map preview — but a duplicate id is a duplicate id, and it broke a plain locator on sight.
    test.fail();

    await openScene(page, lesson.voyage[1], 'voyage');
    await waitForMap(page);

    const copies = await page.locator(`[data-layer-id="art_${pinnedOnVoyage}"]`).count();
    expect(copies, 'the icon appears once in the document').toBe(1);
  });

  test('unpinning returns the icon to a fixed spot on the screen', async ({ page }, info) => {
    await openScene(page, lesson.voyage[1], 'voyage');
    await waitForMap(page);

    // Select the icon so its settings fill the inspector.
    const node = layerNode(page, MAP_HOST, pinnedOnVoyage);
    await expect(node).toBeVisible({ timeout: 30_000 });
    const box = (await node.boundingBox())!;
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

    // "Pin to map" is on, because the icon was dropped on a map.
    const pin = page.locator('input[type=checkbox]').last();
    await expect(pin).toBeChecked({ timeout: 20_000 });

    await pin.click();
    await page.waitForTimeout(1500);

    const released = (await overlayLayers(page))!.find((l) => l.assetId === pinnedOnVoyage)!;
    expect(released.anchor, 'released back to the screen').toBe('screen');
    expect(released.lng, 'and it no longer remembers a place').toBeNull();

    // Now the map moving must leave it exactly where it is.
    const at = async () => {
      const r = (await layerNode(page, MAP_HOST, pinnedOnVoyage).boundingBox())!;

      return { cx: r.x + r.width / 2, cy: r.y + r.height / 2 };
    };
    const stillAt = await at();
    const map = (await page.locator('#lesson-map-preview').boundingBox())!;
    await dragTo(
      page,
      { x: map.x + map.width * 0.75, y: map.y + map.height * 0.8 },
      { x: map.x + map.width * 0.75 - 200, y: map.y + map.height * 0.8 - 70 },
    );
    await page.waitForTimeout(1500);

    const after = await at();
    expect(Math.abs(after.cx - stillAt.cx), 'a screen-anchored icon ignores the map').toBeLessThan(2);
    expect(Math.abs(after.cy - stillAt.cy)).toBeLessThan(2);

    // eslint-disable-next-line no-console
    console.log('[icons] unpinned and the map moved under it', await shot(page, info, 'unpinned'));
  });

  test('the tint control records a colour and hangs a filter on the icon', async ({ page }, info) => {
    await openScene(page, lesson.narration, 'narration');
    const node = layerNode(page, STAGE_HOST, placedOnNarration);
    await expect(node).toBeVisible({ timeout: 30_000 });
    const box = (await node.boundingBox())!;
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

    const swatch = page.locator('input[type=color]').last();
    await expect(swatch).toBeVisible({ timeout: 20_000 });
    await swatch.fill('#e11d48');
    await page.waitForTimeout(1500);

    const tinted = (await overlayLayers(page))!.find((l) => l.assetId === placedOnNarration)!;
    expect(tinted.tint).toBe('#e11d48');
    await expect(node.locator('img')).toHaveCSS('filter', new RegExp(`lyrfx-${placedOnNarration}`));

    // eslint-disable-next-line no-console
    console.log('[icons] tint stored', await shot(page, info, 'tint-set'));

    // "No tint" takes it back off.
    await page.getByRole('button', { name: NO_TINT }).click();
    await page.waitForTimeout(1500);
    const cleared = (await overlayLayers(page))!.find((l) => l.assetId === placedOnNarration)!;
    expect(cleared.tint).toBeNull();
  });

  test('the tint control changes what the icon looks like', async ({ page }, info) => {
    // KNOWN DEFECT — see the report. The tint is a duotone (luminance mapped onto the colour),
    // and every icon in the shipped set is solid black line art, so black × any colour is still
    // black: the swatch changes, the stored value changes, the pixels never do.
    test.fail();

    await openScene(page, lesson.narration, 'narration');
    const node = layerNode(page, STAGE_HOST, placedOnNarration);
    await expect(node).toBeVisible({ timeout: 30_000 });
    const box = (await node.boundingBox())!;
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

    // Sample a few pixels at the very middle of the glyph, NOT the whole node: the scene's
    // background drifts (Ken Burns), so a node-sized shot differs between two frames whatever the
    // tint does — that confound made this read as a pass once. The middle of the airplane is solid
    // artwork, so nothing but the tint can change it.
    const middle = {
      x: Math.round(box.x + box.width / 2) - 3,
      y: Math.round(box.y + box.height / 2) - 3,
      width: 6,
      height: 6,
    };
    const plain = await page.screenshot({ clip: middle });
    await node.screenshot({ path: info.outputPath('tint-before.png') });   // human-readable evidence

    const swatch = page.locator('input[type=color]').last();
    await expect(swatch).toBeVisible({ timeout: 20_000 });
    await swatch.fill('#e11d48');
    await page.waitForTimeout(2000);

    const tinted = await page.screenshot({ clip: middle });
    await node.screenshot({ path: info.outputPath('tint-after.png') });
    expect(
      tinted.equals(plain),
      'a crimson tint should not leave the middle of the icon pixel-for-pixel identical',
    ).toBe(false);
  });
});
