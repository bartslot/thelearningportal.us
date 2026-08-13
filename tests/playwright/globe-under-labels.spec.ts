import { test, expect, Page } from '@playwright/test';
import { PNG } from 'pngjs';
import { loginAsTeacher } from './support/auth';

/**
 * The sea belongs UNDER the writing, on every map that has both.
 *
 * Reported as a font bug, because that is what it looks like: on the Earth style a place name is
 * legible over land and erased where it crosses water, so "Constantinople" comes out cut in two at
 * the Bosphorus, "Carthage" stops at the coast and "Athens" reads "Ath". The cause is one line —
 * MapLibre's addLayer appends to the TOP when it is handed no anchor, and the lesson map handed it
 * none, so all seven globe layers went over every label.
 *
 * It took a long time to find because the obvious place to look was the Time-Map, which passes an
 * anchor and whose layer order was right the whole time. So this checks BOTH surfaces: the one that
 * was broken, and the one that was blamed.
 *
 * The layer-order assertion is the exact statement of the bug, and it is the one that catches it:
 * put back either half of the fix and it names the victims by layer id. The pixel assertion after it
 * is the user-visible consequence — a pixel the text covers completely may not move by more than
 * one unit of rounding when the sea beneath it is switched off.
 */

/**
 * The paint order, with each layer marked for whether it draws text.
 *
 * Two sources, because neither alone is enough: custom layers are absent from getStyle().layers, so
 * the ORDER has to come off style._order; and the live layer object's `layout.get('text-field')`
 * answers with a default-valued property rather than null for a layer that has no text at all, so
 * the TEXT flag has to come off the serialized spec. Using the live object for both marks the tree
 * and mountain glyph layers as writing, which is how this test first reported four scenery layers
 * as the victims.
 */
async function paintOrder(page: Page, handle: string) {
  return page.evaluate((name) => {
    const map = (window as any)[name];
    if (!map?.style?._order) return null;
    const specs = new Map<string, any>((map.getStyle()?.layers ?? []).map((l: any) => [l.id, l]));
    return map.style._order.map((id: string) => ({
      id,
      type: map.style._layers[id]?.type,
      text: specs.get(id)?.type === 'symbol' && specs.get(id)?.layout?.['text-field'] != null,
    }));
  }, handle);
}

/**
 * The sea, and everything under it in the stack, below every layer that draws text.
 *
 * Deliberately about the SEA rather than the whole globe stack. The cloud deck and the limb haze
 * sit ABOVE the voyage route labels on the Time-Map, and that is a decision with a reason written
 * beside it (see initVoyages in timemap/index.js: the voyages anchor at tm-clouds). Weather passing
 * over a name is a taste call; the ocean erasing one is not.
 */
function assertSeaUnderWriting(order: { id: string; type: string; text: boolean }[], where: string) {
  const at = (id: string) => order.findIndex((l) => l.id === id);
  const sea = at('tm-ocean');
  const writing = order.map((l, i) => ({ ...l, i })).filter((l) => l.text);

  // Neither may be missing, or this passes by describing an empty map.
  expect(sea, `${where}: the sea is not on this map at all`).toBeGreaterThan(-1);
  expect(writing.length, `${where}: no layer on this map draws text, so there is nothing to protect`)
    .toBeGreaterThan(0);

  // Everything at or below the sea in the stack — the sky bodies go under it by design.
  const drowned = order
    .map((l, i) => ({ ...l, i }))
    .filter((l) => l.text && l.i < sea)
    .map((l) => l.id);

  expect(drowned, `${where}: text drawn UNDER the sea — ${drowned.join(', ')}`).toEqual([]);
  expect(sea).toBeLessThan(Math.min(...writing.map((l) => l.i)));
}

// The cloud deck drifts on a wind field, so two screenshots seconds apart differ over every cloud
// edge — which is enough to swamp the pixel comparison below with pixels that are not text at all.
// Reduced motion is the app's own switch for exactly this (it reaches the layers as animate:false),
// so the scene is frozen through the map's real code path rather than by poking at it.
test.use({ reducedMotion: 'reduce' });

test.describe('the sea is drawn under the labels', () => {
  test('lesson map, Earth style — the surface this shipped broken on', async ({ page }) => {
    test.setTimeout(180_000);
    await loginAsTeacher(page);
    // Lesson 7 is seeded with map scenes and map_style = 'earth'.
    await page.goto('/teacher/lessons/7/wizard');
    await page.waitForTimeout(5_000);

    // A real pointer on the second thumbnail — the first map scene — as a teacher would.
    await page.mouse.move(87, 273);
    await page.mouse.down();
    await page.mouse.up();
    await page.waitForFunction(() => !!(window as any).__lessonMap?.style?._order, { timeout: 60_000 });
    await page.waitForTimeout(9_000);

    const order = await paintOrder(page, '__lessonMap');
    expect(order, 'the lesson map never mounted').not.toBeNull();
    assertSeaUnderWriting(order!, 'lesson map');

    // ── And the consequence, in pixels.
    //
    // NOTHING is toggled here except the sea. Hiding the labels to find them was the first idea and
    // it is a trap: showing them again re-runs MapLibre's symbol placement, so a few names land
    // differently and the two frames stop being comparable. Seven pixels out of 2562 disagreed that
    // way, and every one of them was a label that had moved rather than a label the sea had eaten.
    const textLayers = order!.filter((l) => l.text).map((l) => l.id);
    const setSea = (visible: boolean) => page.evaluate((v) => (window as any).__lessonMap
      .setLayoutProperty('tm-ocean', 'visibility', v ? 'visible' : 'none'), visible);

    /**
     * Set the names larger for the measurement, through the map's own text-size property.
     *
     * At the shipping 13px there are only ~56 pixels on the whole map that a glyph covers
     * completely, because the strokes of this calligraphy are barely a pixel wide. That is a real
     * sample but a thin one. Thicker strokes give hundreds, and the property being driven is the
     * same one the Time-Map's own label-size control drives — it changes how much ink there is to
     * measure, not what is drawn on top of what.
     */
    await page.evaluate((ids) => {
      ids.forEach((id: string) => (window as any).__lessonMap.setLayoutProperty(id, 'text-size', 20));
    }, textLayers);

    // Settled before every shot: the ground is streamed imagery, and a tile arriving between two
    // captures changes a whole block of the picture, which the difference below would read as text.
    const settled = async () => {
      await page.waitForFunction(() => (window as any).__lessonMap?.loaded?.() === true, { timeout: 60_000 });
      await page.waitForTimeout(1_200);
    };

    await settled();
    const withSea = PNG.sync.read(await page.screenshot());
    await setSea(false);
    await settled();
    const withoutSea = PNG.sync.read(await page.screenshot());
    await setSea(true);

    // Ink and halo, read off the live map rather than assumed. Which of the two a pixel is decides
    // everything: on the Earth style the names are DARK ink on a bright parchment halo, and the halo
    // is deliberately semi-transparent (text-halo-blur), so halo pixels composite with what is
    // beneath them and change when the sea is hidden exactly as they should. Measuring those was
    // this test's first wrong answer — 305 of 307 "ink" pixels changed, and every one was halo.
    const paint = await page.evaluate((ids) => {
      const map = (window as any).__lessonMap;
      const hex = (v: unknown) => (typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v)
        ? [1, 3, 5].map((at) => parseInt(v.slice(at, at + 2), 16)) : null);
      for (const id of ids) {
        const ink = hex(map.getPaintProperty(id, 'text-color'));
        const halo = hex(map.getPaintProperty(id, 'text-halo-color'));
        if (ink && halo) return { ink, halo };
      }
      return null;
    }, textLayers);
    expect(paint, 'could not read the label ink and halo colours off the map').not.toBeNull();
    const { ink, halo } = paint!;

    const { width: w, height: h, data: a } = withSea;
    const noSea = withoutSea.data;
    const near = (buf: Buffer, i: number, c: number[], slack: number) => Math.abs(buf[i] - c[0]) <= slack
      && Math.abs(buf[i + 1] - c[1]) <= slack && Math.abs(buf[i + 2] - c[2]) <= slack;
    // In BOTH frames. The sea paints a bright shelf band along every coast, so a dark rock beside it
    // has a near-white neighbour and passes for ink with the sea on — and then "changes" when the
    // sea goes, which is the band leaving, not a glyph. A real halo is drawn on top of the sea and
    // is still there without it.
    const haloBeside = (x: number, y: number) => {
      for (let d = 1; d <= 3; d++) {
        for (const j of [((y - d) * w + x) * 4, ((y + d) * w + x) * 4, (y * w + x - d) * 4, (y * w + x + d) * 4]) {
          if (near(a, j, halo, 30) && near(noSea, j, halo, 30)) return true;
        }
      }
      return false;
    };

    /**
     * Glyph core: EXACTLY the ink colour, with the halo somewhere in the four pixels around it.
     *
     * Exactly, with no tolerance, and that is the whole point. A pixel the glyph covers completely
     * is the ink colour and nothing else; one covered 97% has already blended a few units toward
     * the near-white halo behind it, and blends differently when the sea beneath changes — so a
     * tolerance of ±6 admitted 23 such pixels and turned an equality into a fudge. Exact match ⇒
     * full coverage ⇒ the pixel cannot legally change.
     *
     * The halo neighbour is what makes it text rather than a coincidence: dark ground can land on
     * the ink colour by chance, but it does not come wrapped in near-white parchment.
     */
    const isInk = (i: number, x: number, y: number) =>
      a[i] === ink[0] && a[i + 1] === ink[1] && a[i + 2] === ink[2] && haloBeside(x, y);

    let inkPixels = 0;
    let changed = 0;
    let worst = { delta: 0, x: -1, y: -1 };
    const bounds = { x0: 1e9, y0: 1e9, x1: -1e9, y1: -1e9 };
    for (let y = 3; y < h - 3; y++) {
      for (let x = 3; x < w - 3; x++) {
        const i = (y * w + x) * 4;
        if (!isInk(i, x, y)) continue;
        inkPixels++;
        bounds.x0 = Math.min(bounds.x0, x); bounds.y0 = Math.min(bounds.y0, y);
        bounds.x1 = Math.max(bounds.x1, x); bounds.y1 = Math.max(bounds.y1, y);
        const delta = Math.max(
          Math.abs(a[i] - noSea[i]), Math.abs(a[i + 1] - noSea[i + 1]), Math.abs(a[i + 2] - noSea[i + 2]),
        );
        if (delta > worst.delta) worst = { delta, x, y };
        if (delta > 1) changed++;
      }
    }
    console.log(`INK ${inkPixels} over1=${changed} worst=${JSON.stringify(worst)} bounds=${JSON.stringify(bounds)}`);

    expect(inkPixels, 'found no covered ink at all — the labels did not render, so this proves nothing')
      .toBeGreaterThan(800);
    /**
     * One unit, and one unit only.
     *
     * Not a tolerance anyone picked: a glyph pixel at 99.x% coverage still carries a fraction of
     * whatever is behind it, so it rounds one step differently over two different backgrounds. That
     * is arithmetic, and it is the floor — measured at exactly ±1 across every disagreeing pixel.
     *
     * It cannot hide the bug it exists to catch. A pixel the SEA had painted over would come back
     * as the sea's colour, tens to hundreds of units from the ink, not one.
     */
    expect(changed, `${changed} of ${inkPixels} covered ink pixels moved by more than rounding — worst ${worst.delta} at (${worst.x}, ${worst.y})`)
      .toBe(0);
    expect(worst.delta, 'a covered ink pixel changed by more than sub-unit rounding when the sea was hidden')
      .toBeLessThanOrEqual(1);
  });

  test('Time-Map, Earth style — the surface that was blamed for it', async ({ page }) => {
    test.setTimeout(120_000);
    await loginAsTeacher(page);
    await page.goto('/teacher/timemap');
    await page.waitForFunction(
      () => !!(window as any).__tmMap?.getLayer('boundaries-fill') && !!(window as any).__applyMapStyle,
      { timeout: 40_000 },
    );
    await page.evaluate(() => (window as any).__applyMapStyle('earth'));
    await page.waitForTimeout(6_000);

    const order = await paintOrder(page, '__tmMap');
    expect(order, 'the Time-Map never mounted').not.toBeNull();
    assertSeaUnderWriting(order!, 'Time-Map');
  });
});
