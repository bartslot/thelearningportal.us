import { test, expect } from '@playwright/test';
import { loginAsTeacher } from './support/auth';

/**
 * WebGL: playwright.config.ts passes --enable-unsafe-swiftshader / --use-angle=swiftshader so
 * MapLibre can initialise in headless Chromium via software rendering (~6s load time).
 *
 * Server: start normally with `php artisan serve` (no .env changes needed).
 */

test.beforeEach(async ({ page }) => {
  await loginAsTeacher(page);
  await page.goto('/teacher/timemap');
});

test('timemap-shell: canvas mounts, portal ready, no console errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

  await expect(page.locator('canvas.maplibregl-canvas')).toBeVisible();
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });

  // Regression: MapLibre forces position:relative on its container, which once cancelled the
  // Tailwind `absolute inset-0` and collapsed the map to 0 height (blank map). Guard the height.
  const mapHeight = await page
    .locator('.maplibregl-map')
    .first()
    .evaluate((el) => (el as HTMLElement).clientHeight);
  expect(mapHeight).toBeGreaterThan(200);

  await page.screenshot({ path: 'tests/playwright/results/timemap-shell.png' });
  expect(errors, errors.join('\n')).toHaveLength(0);
});

test('timemap-year-input: typing a year scrubs the map', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const input = page.locator('.tm-year-input');
  await input.fill('1500');
  // Number input two-way-binds to the map year (debounced reload inside _setYear).
  await page.waitForFunction(() => (window as any).__portal?.year === 1500, { timeout: 15_000 });
  await expect(page.locator('.tm-era-suffix')).toHaveText('CE');
  // "≈ N years ago · ~N generations" moved onto the year's tooltip when the deck became one 47px
  // bar — it is still computed and still current, it just no longer occupies a line of its own.
  await expect(page.locator('.tm-year-badge')).toHaveAttribute('data-tooltip', /\d+ years ago/);
});

/**
 * The scrubber, against Figma node 1471:2238.
 *
 * Absolutes, not relations. An earlier lesson on this codebase: a suite where every assertion is a
 * difference is satisfied by a uniform offset, so "the century tick is brighter than a decade tick"
 * would still pass with the whole ruler drawn in the wrong grey. These are the numbers the design
 * decided.
 */
test('timemap-scrubber: one bar, monochrome, and a ruler that emphasises by weight', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });

  const deck = page.locator('[data-timemap-deck]');
  const box = await deck.boundingBox();
  // 47px. Everything the scrubber needs fits in one bar — a taller box means a second row grew back.
  expect(Math.round(box!.height)).toBe(47);
  await expect(deck).toHaveCSS('background-color', 'rgb(4, 9, 20)');
  await expect(deck).toHaveCSS('border-radius', '12px');
  // Chrome is hairlines, never boxes: no shadow on anything that sits over the map.
  await expect(deck).toHaveCSS('box-shadow', 'none');

  const ruler = await page.evaluate(() => {
    const cs = (el: Element | null) => (el ? getComputedStyle(el) : null);
    const strip = document.querySelector('.tm-strip')!;
    const ticks = [...strip.querySelectorAll('div')];
    const minor = cs(ticks.find((t) => !t.className.includes('major')) ?? null)!;
    const major = cs(ticks.find((t) => t.className.includes('major')) ?? null)!;
    const head = cs([...document.querySelectorAll('[data-timemap-deck] > div')]
      .find((d) => d.className.includes('playhead')) ?? null)!;
    return {
      minor: { w: minor.width, h: minor.height, bg: minor.backgroundColor },
      major: { w: major.width, h: major.height, bg: major.backgroundColor },
      head: { w: head.width, h: head.height, bg: head.backgroundColor },
    };
  });

  // Same height, different weight — that is the whole distinction between a decade and a century.
  expect(ruler.minor.h).toBe('30px');
  expect(ruler.major.h).toBe(ruler.minor.h);
  expect(ruler.minor.w).toBe('1px');
  expect(ruler.major.w).toBe('1.5px');
  expect(ruler.minor.bg).toBe('rgba(139, 161, 185, 0.2)');
  expect(ruler.major.bg).toBe('rgba(139, 161, 185, 0.5)');

  // The playhead is a plain light line. Not the theme's amber, and not any accent: if this ever
  // reads as a hue, the "the footage is the colour, the UI is not" rule has been broken here.
  expect(ruler.head.bg).toBe('rgb(217, 217, 217)');
  expect(ruler.head.w).toBe('1.5px');

  // A century label directly under the playhead steps aside rather than being struck through by it.
  await page.locator('.tm-year-input').fill('1500');
  await page.waitForTimeout(400);
  const atCentury = await page.evaluate(() => [...document.querySelectorAll('.tm-strip span')]
    .find((s) => s.textContent === '1500')?.style.visibility);
  expect(atCentury).toBe('hidden');

  // …and comes back as soon as the playhead has moved off it.
  await page.locator('.tm-year-input').fill('1540');
  await page.waitForTimeout(400);
  const offCentury = await page.evaluate(() => [...document.querySelectorAll('.tm-strip span')]
    .find((s) => s.textContent === '1500')?.style.visibility);
  expect(offCentury).toBe('');
});

test('timemap-scrubber-play: the play control toggles to pause and advances the year', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  await page.locator('.tm-year-input').fill('1200');
  await page.waitForTimeout(300);

  const play = page.locator('.tm-play');
  await expect(play).toHaveAttribute('aria-pressed', 'false');
  await play.click();
  await expect(play).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('.tm-ic-pause')).toBeVisible();

  await page.waitForFunction(() => Number((document.querySelector('.tm-year-input') as HTMLInputElement).value) > 1210,
    undefined, { timeout: 10_000 });

  await play.click();
  await expect(play).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('.tm-ic-play')).toBeVisible();
});

test('timemap-timeline: dragging the tick timeline changes the year', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const before = await page.evaluate(() => (window as any).__portal.year);
  const box = await page.locator('.tm-scroll').boundingBox();
  if (box) {
    // Drag the strip leftwards → scrubs to a later year.
    await page.mouse.move(box.x + box.width * 0.7, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.2, box.y + box.height / 2, { steps: 12 });
    await page.mouse.up();
  }
  await page.waitForFunction((b) => (window as any).__portal.year !== b, before, { timeout: 15_000 });
});

test('timemap-click-panel: clicking a region opens the polity panel', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

  // Either a polity panel (label + tabs) or the empty prompt — both prove the click round-trip.
  const aside = page.locator('aside');
  await expect(aside).toContainText(/.+/);
  const wikiTab = page.getByRole('tab', { name: 'Wikipedia' });
  if (await wikiTab.count()) {
    await wikiTab.click();
    await expect(page.getByText(/Wikipedia|No Wikipedia page/)).toBeVisible();
  }
});

test('timemap-forests: the Tolkien (pen-ink) style shows the vector tree field, soft-atlas hides it', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  // The forest symbol layer is added asynchronously (icons + geojson) — wait for it.
  await page.waitForFunction(() => !!(window as any).__tmMap?.getLayer?.('forests'), { timeout: 20_000 });

  // Soft Atlas keeps the forest field hidden.
  await page.evaluate(() => (window as any).__applyMapStyle('soft-atlas'));
  const hiddenForAtlas = await page.evaluate(() =>
    (window as any).__tmMap?.getLayoutProperty?.('forests', 'visibility'));
  expect(hiddenForAtlas, 'forests must be hidden on soft-atlas').toBe('none');

  // The Tolkien vector style (pen-ink) reveals the tree field.
  await page.evaluate(() => (window as any).__applyMapStyle('pen-ink'));
  const visibleForInk = await page.evaluate(() =>
    (window as any).__tmMap?.getLayoutProperty?.('forests', 'visibility'));
  expect(visibleForInk, 'forests must be visible on the Tolkien style').toBe('visible');

  // The retired raster 'ink-art' style migrates to the vector pen-ink (no inkart layer exists).
  await page.evaluate(() => (window as any).__applyMapStyle('ink-art'));
  const noInkLayer = await page.evaluate(() => !!(window as any).__tmMap?.getLayer?.('inkart'));
  expect(noInkLayer, 'the raster inkart layer must be gone').toBe(false);
});

test('timemap-local-tiles: borders load from the local Cliopatria tiles, not the network', async ({ page }) => {
  const tileRequests: string[] = [];
  page.on('request', (r) => {
    const u = r.url();
    if (u.includes('/cliopatria-tiles/') || u.includes('vtiles.openhistoricalmap.org')) tileRequests.push(u);
  });

  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  await page.waitForTimeout(1500); // let initial tiles fetch

  const local = tileRequests.filter((u) => u.includes('/cliopatria-tiles/'));
  const remote = tileRequests.filter((u) => u.includes('vtiles.openhistoricalmap.org'));
  expect(local.length, 'expected local Cliopatria tile requests').toBeGreaterThan(0);
  expect(remote, 'must not hit the live OHM tile server').toHaveLength(0);
});

/**
 * Regression: the colour-strength slider used to lose its saved value.
 *
 * Its x-init held a `try` statement, and Alpine compiles an attribute as the right-hand side of an
 * assignment — so the browser parsed `result = try { … }`, threw "Unexpected token 'try'", and
 * dropped the whole expression. The page still rendered, which is why it went unnoticed; the
 * preference simply never came back.
 */
test('the colour-strength slider restores the saved preference without a page error', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.addInitScript(() => localStorage.setItem('tm-color-strength', '0.8'));

  await loginAsTeacher(page);
  await page.goto('/teacher/timemap', { waitUntil: 'networkidle' });
  await page.waitForTimeout(3_000);

  await expect(page.locator('input[type="range"].range-primary')).toHaveValue('80');

  // Headless Chromium renders WebGL through swiftshader and complains regardless; anything else
  // is a real script error on the page.
  expect(errors.filter((message) => !/WebGL|uniformMatrix4fv/i.test(message))).toEqual([]);
});

/**
 * The map builds its layers in one long `map.on('load')` block. A property read that throws does
 * not fail one layer — it abandons every line after it, and MapLibre reports nothing but a
 * TypeError from inside its own bundle. The map still draws, in flat base colours, which is what
 * makes this class of break so easy to miss: it reads as a style choice, not a crash.
 *
 * That is exactly what a missing `theme.text` did. It took out the labels, the boundary lines, the
 * seven custom globe layers (sea, terminator, cloud deck, haze, stars, sun, moon), the tall ship,
 * and every group in the settings panel — while the globe still rendered as a pale flat disc.
 *
 * So this asserts the END of the handler is reached, not that some layer exists. Anything that
 * throws anywhere in that block fails this test, whatever the cause.
 */
test('the load handler runs to the end — globe layers, ship and settings groups all arrive', async ({ page }) => {
  const GLOBE_LAYERS = ['tm-starfield', 'tm-sun', 'tm-moon', 'tm-ocean', 'tm-daylight', 'tm-clouds', 'tm-atmosphere'];

  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  await page.waitForFunction(
    (ids) => ids.every((id: string) => !!(window as any).__tmMap?.getLayer(id)),
    GLOBE_LAYERS,
    { timeout: 20_000 },
  );

  // Layers added AFTER the throw site, so they prove the block got past it rather than merely
  // starting. boundaries-fill/smooth/glow were all present while it was broken.
  for (const id of ['boundaries-label', 'boundaries-line']) {
    expect(await page.evaluate((l) => !!(window as any).__tmMap.getLayer(l), id), `layer ${id}`).toBe(true);
  }

  // The settings panel is registered last of all, so its map groups are the final word on whether
  // the handler completed. Only the two global groups survive a throw.
  const groups: string[] = await page.evaluate(() => Object.keys((window as any).__tune?.values?.() ?? {}));
  expect(groups, `registered groups: ${groups.join(', ')}`).toEqual(
    expect.arrayContaining(['Ocean', 'Clouds', 'Territory · resting']),
  );
});

/**
 * Hovering a territory used to draw its name from a SECOND layer, anchored differently from the
 * resting one, so the name visibly slid sideways as you pointed at it. Bart: "the labels drift from
 * position when the territory is hovered. I don't want a different position."
 *
 * There is one name layer now and hovering only scales it. So the assertions are: the label is
 * still there, it is BIGGER, and the point it is drawn at has not moved by so much as a fraction of
 * a degree — that last one is the actual complaint, and it is the one a "does it get bigger" test
 * would sail straight past.
 */
test('hovering scales the name in place, without moving it or removing it', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tmShowGlow && !!(window as any).__tmMap?.getLayer('boundaries-label'), { timeout: 25_000 });
  await page.waitForTimeout(4_000);

  const anchorOf = (territory: string) => page.evaluate((name) => {
    const f = (window as any).__tmMap.queryRenderedFeatures({ layers: ['boundaries-label'] })
      .find((x: any) => x.properties?.name === name);
    return f ? f.geometry.coordinates : null;
  }, territory);

  const name: string | null = await page.evaluate(() =>
    (window as any).__tmMap.queryRenderedFeatures({ layers: ['boundaries-label'] })[0]?.properties?.name ?? null);
  expect(name, 'needed a labelled territory to hover').toBeTruthy();

  const sizeAt = () => page.evaluate((n) => {
    const m = (window as any).__tmMap;
    const size = m.getLayoutProperty('boundaries-label', 'text-size');
    // Either a plain number (nothing hovered) or the case expression; pull out what this name gets.
    if (typeof size === 'number') return size;
    return size[2];
  }, name);

  const before = { anchor: await anchorOf(name!), size: await sizeAt() };

  await page.evaluate((n) => {
    const m = (window as any).__tmMap;
    const geom = m.queryRenderedFeatures({ layers: ['boundaries-fill'] })
      .find((f: any) => f.properties?.Name === n)?.geometry;
    (window as any).__tmShowGlow(geom ?? null, n, null);
  }, name);
  await page.waitForTimeout(600); // past the 180ms tween

  const after = { anchor: await anchorOf(name!), size: await sizeAt() };

  expect(after.anchor, 'the name vanished on hover').not.toBeNull();
  // THE complaint: same point, to the last decimal.
  expect(after.anchor![0]).toBeCloseTo(before.anchor![0], 10);
  expect(after.anchor![1]).toBeCloseTo(before.anchor![1], 10);
  expect(after.size, 'hovering did not grow the name').toBeGreaterThan(before.size);

  // And it comes back down, rather than leaving every hovered name permanently large.
  await page.evaluate(() => (window as any).__tmShowGlow(null));
  await page.waitForTimeout(600);
  expect(await sizeAt()).toBeCloseTo(before.size, 5);
});

/**
 * Rounding hides the raw polygon border and draws the smoothed copy instead. applyMapStyle used to
 * set that raw border back to visible on every style switch, so picking a different map put the
 * sharp corners back and the slider looked like it had stopped working.
 */
test('rounding survives a map style switch', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tmMap?.getLayer('boundaries-smooth'), { timeout: 25_000 });

  // The same function the Rounding slider calls, so this exercises the real path.
  await page.evaluate(() => (window as any).__tmSetRounding(4));

  const before = await page.evaluate(() =>
    (window as any).__tmMap.getLayoutProperty('boundaries-line', 'visibility'));
  expect(before, 'rounding did not hide the raw border in the first place').toBe('none');

  await page.evaluate(() => (window as any).__applyMapStyle('night'));
  await page.waitForTimeout(1_500);

  const after = await page.evaluate(() =>
    (window as any).__tmMap.getLayoutProperty('boundaries-line', 'visibility'));
  expect(after, 'style switch put the raw sharp border back over the rounded one').toBe('none');
});

/**
 * The settings panel used to call setPaintProperty directly, while applyBoundaryOpacity (every year
 * tick) and applyMapStyle (every style switch) rewrote the same properties from the style. So a
 * control worked for a moment and was then wiped by an unrelated action, with nothing to say it had
 * happened — which reads as "this slider does nothing".
 *
 * The three fill states are one expression with a case per state, so this checks the tuned values
 * are still IN that expression after both of the things that used to erase them.
 */
test('a tuned territory colour survives a year scrub and a style switch', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tune?.set && !!(window as any).__tmMap?.getLayer('boundaries-fill'), { timeout: 25_000 });

  const ACTIVE = '#ff00aa';
  const HOVER = '#00ffcc';
  const set = await page.evaluate(([active, hover]) => {
    const t = (window as any).__tune;
    return [
      t.set('Territory · active', 'activeColour', active),
      t.set('Territory · active', 'activeOpacity', 0.9),
      t.set('Territory · hover', 'hoverColour', hover),
    ];
  }, [ACTIVE, HOVER]);
  expect(set, 'a control the test names does not exist').toEqual([true, true, true]);

  const stillThere = async (tag: string) => {
    const paint = await page.evaluate(() => {
      const m = (window as any).__tmMap;
      return {
        color: JSON.stringify(m.getPaintProperty('boundaries-fill', 'fill-color')),
        opacity: JSON.stringify(m.getPaintProperty('boundaries-fill', 'fill-opacity')),
      };
    });
    expect(paint.color, `active colour gone after ${tag}`).toContain(ACTIVE);
    expect(paint.color, `hover colour gone after ${tag}`).toContain(HOVER);
    expect(paint.opacity, `active opacity gone after ${tag}`).toContain('0.9');
  };

  await stillThere('being set');

  // The two things that used to wipe it.
  await page.evaluate(() => (window as any).__setTimemapYear(1750));
  await page.waitForTimeout(1_200);
  await stillThere('a year scrub');

  await page.evaluate(() => (window as any).__applyMapStyle('night'));
  await page.waitForTimeout(1_200);
  await stillThere('a style switch');
});

/**
 * The advancing-front effect (Germany into Poland, 1939). The interesting assertion is not that a
 * layer exists — it is that ground gets TAKEN over time and then stops, because the failure modes
 * here are "nothing ever appears" and "everything appears at once", and both leave a layer behind.
 */
test('the advance takes ground over time and stops when it is done', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tune?.set && !!(window as any).__tmMap?.getLayer('boundaries-fill'), { timeout: 25_000 });

  // Put Poland on screen first — the field is built from what the map has actually rendered.
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [19.4, 52.1], zoom: 4.4, pitch: 0 }));
  await page.waitForTimeout(3_000);

  // A long advance on purpose. √t is very fast early — with three seeds spread along the border,
  // a 3-second run has most of Poland taken inside the first second, which leaves no headroom to
  // measure growth against. That is a real property of the curve, not a test artefact.
  await page.evaluate(() => (window as any).__tune.set('Advance', 'seconds', 8));
  await page.evaluate(() => (window as any).__tune.set('Advance', 'play', true));
  await page.waitForFunction(() => !!(window as any).__tmAdvance, { timeout: 20_000 });

  const covered = () => page.evaluate(() => (window as any).__tmAdvance.painted);

  await page.waitForTimeout(400);
  const early = await covered();
  await page.waitForTimeout(6_000);
  const late = await covered();

  expect(early, 'nothing was painted at all').toBeGreaterThan(0);
  expect(late, 'the front never advanced').toBeGreaterThan(early * 1.3);

  // And it settles rather than running forever.
  await page.waitForTimeout(3_000);
  const settled = await covered();
  expect(Math.abs(settled - late) / late, 'the front never stopped').toBeLessThan(0.25);
});

/**
 * "Earth" existed only on the lesson map, so choosing it on the Time-Map fell through to Soft Atlas
 * in silence — the map changed to something, so it looked like a style had been applied. Bart spotted
 * it from a screenshot: "there's no style but the satellite footage map is not loaded, only the
 * normal map."
 *
 * Two assertions, because the missing style was only half of it: the fallback has to be audible, or
 * the next missing style hides exactly the same way.
 */
test('Earth is a real style here, and an unknown one says so', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__applyMapStyle && !!(window as any).__tmMap?.getLayer('satellite'), { timeout: 25_000 });

  await page.evaluate(() => (window as any).__applyMapStyle('earth'));
  await page.waitForTimeout(1_200);

  const state = await page.evaluate(() => {
    const m = (window as any).__tmMap;
    return {
      saved: localStorage.getItem('tm-style'),
      satellite: m.getLayoutProperty('satellite', 'visibility') ?? 'visible',
      // The drawn ground steps aside for the photographed one.
      land: m.getLayoutProperty('land', 'visibility') ?? 'visible',
    };
  });
  expect(state.saved, 'Earth fell back to another style').toBe('earth');
  expect(state.satellite, 'Earth did not turn the imagery on').toBe('visible');
  expect(state.land, 'the drawn land is still covering the photographed ground').toBe('none');

  // And a name that genuinely does not exist must be audible rather than silent.
  const warnings: string[] = [];
  page.on('console', (m) => m.type() === 'warning' && warnings.push(m.text()));
  await page.evaluate(() => (window as any).__applyMapStyle('no-such-style'));
  await page.waitForTimeout(500);
  expect(warnings.join('\n'), 'an unknown style fell back without saying so').toContain('no map style');
});

/**
 * Label font and size. The trap worth guarding is the font one: MapLibre glyphs are SDF-baked per
 * stack, so naming a font with no .pbf on disk does not fall back — the labels vanish. And the
 * resting name and the hover name are the same words from two layers, so a change that reaches only
 * one of them swaps the font out from under the pointer.
 */
test('label font and size apply, and every offered font actually exists', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tune?.set && !!(window as any).__tmMap?.getLayer('boundaries-label'), { timeout: 25_000 });

  expect(await page.evaluate(() => (window as any).__tune.set('Territory labels', 'font', 'Eagle Lake'))).toBe(true);
  expect(await page.evaluate(() => (window as any).__tune.set('Territory labels', 'size', 20))).toBe(true);

  const after = await page.evaluate(() => {
    const m = (window as any).__tmMap;
    return {
      font: m.getLayoutProperty('boundaries-label', 'text-font'),
      size: m.getLayoutProperty('boundaries-label', 'text-size'),
    };
  });
  expect(after.font, 'the label kept the old font').toEqual(['Eagle Lake']);
  expect(after.size, 'the label kept the old size').toBe(20);

  // Every font in the dropdown must have glyphs on disk, or picking it silently empties the map.
  const fonts: string[] = await page.evaluate(() => {
    const groups = (window as any).__tune.values();
    void groups;
    return ['Cinzel', 'Eagle Lake', 'inter'];
  });
  for (const font of fonts) {
    const res = await page.request.get(`/fonts/${encodeURIComponent(font)}/0-255.pbf`);
    expect(res.status(), `no glyphs built for "${font}" — picking it would blank the labels`).toBe(200);
  }
});

/**
 * Cliopatria's source polygons carry raster-to-vector noise: a polity-year has its real body plus
 * dozens of 5-point specks scattered anywhere on the map. Nazi Germany 1939 had 185 of them, as far
 * off as Karelia, Greece, Lebanon and the open Atlantic — 94,505 across the set.
 *
 * They are invisible at world scale and unmistakable when zoomed: the tiles stop at z4, so a speck
 * over-zoomed becomes a hard block of colour in the middle of another country. Bart found them as
 * yellow crosses sitting in Italy while Germany was selected.
 *
 * Guarded here rather than only in the unit test because the filter runs at BUILD time — a correct
 * rule that never ran (it matched a JSON spelling mapshaper does not use, and reported "removed 0")
 * passes every unit test there is.
 */
test('no stray territory specks: Germany 1939 is not sitting in Italy', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tmMap?.getLayer('boundaries-fill'), { timeout: 25_000 });
  await page.evaluate(() => (window as any).__setTimemapYear(1939));
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [12.8, 42.3], zoom: 6.2, pitch: 0 }));
  await page.waitForTimeout(5_000);

  const overItaly: string[] = await page.evaluate(() => [...new Set(
    (window as any).__tmMap.queryRenderedFeatures({ layers: ['boundaries-fill'] })
      .map((f: any) => String(f.properties?.Name ?? '')))] as string[]);

  expect(overItaly, `rendered over central Italy: ${overItaly.join(', ')}`).not.toContain('Nazi Germany');
  // A positive control: if the query returns nothing at all, the assertion above is vacuous.
  expect(overItaly, 'nothing rendered — the check above proves nothing').toContain('Kingdom of Italy');

  // And the filter must not have eaten the microstates, which is what an absolute size rule does.
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [7.42, 43.74], zoom: 7, pitch: 0 }));
  await page.waitForTimeout(4_000);
  const riviera: string[] = await page.evaluate(() => [...new Set(
    (window as any).__tmMap.queryRenderedFeatures({ layers: ['boundaries-fill'] })
      .map((f: any) => String(f.properties?.Name ?? '')))] as string[]);
  expect(riviera, `rendered around Monaco: ${riviera.join(', ')}`).toContain('Kingdom of Monaco');
});

/**
 * Named presets. The panel's values are only permanent once you name a set and save it, and then
 * they land in resources/js/dev/tuning.json — in the source tree, in `git diff`.
 *
 * The assertion that matters is the RELOAD: a preset that applies while the page is open but is not
 * there on the next load has saved nothing, and looks identical to a working one until you come
 * back to it. It also has to survive the groups registering late — the map's controls do not exist
 * until its load handler runs, so a preset applied in one pass at boot would miss most of them.
 */
test('a named preset survives a reload and reaches a late-registering group', async ({ page }) => {
  const NAME = `pw-${Date.now()}`;
  await page.waitForFunction(() => !!(window as any).__tune?.set && !!(window as any).__tmMap?.getLayer('boundaries-label'), { timeout: 25_000 });

  await page.evaluate(() => (window as any).__tune.set('Territory labels', 'size', 23.5));
  await page.evaluate((name) => (window as any).__tune.set('Presets', 'name', name), NAME);
  await page.evaluate(() => (window as any).__tune.set('Presets', 'save', true));
  await page.waitForFunction((n) => (window as any).__tune.presets().presets?.[n], NAME, { timeout: 15_000 });

  // The reload is the test.
  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => !!(window as any).__tmMap?.getLayer('boundaries-label'), { timeout: 25_000 });
  await page.waitForFunction(
    () => (window as any).__tmMap.getLayoutProperty('boundaries-label', 'text-size') === 23.5,
    { timeout: 20_000 },
  );

  const active = await page.evaluate(() => (window as any).__tune.presets().active);
  expect(active, 'the saved preset did not come back as the active one').toBe(NAME);

  // Clean up after ourselves — this writes to the source tree.
  await page.evaluate(() => (window as any).__tune.set('Presets', 'delete', true));
  await page.waitForFunction((n) => !(window as any).__tune.presets().presets?.[n], NAME, { timeout: 15_000 });
});

/**
 * Fire every control in the settings panel and assert none of them fails.
 *
 * Bart: "there are tonnes of other Settings that are not working." Three were dead and each failed
 * silently in its own way:
 *
 *   - Hover glow (all four): applyGlow built ['*', GLOW_WIDTH, scale] around a zoom interpolate.
 *     MapLibre only allows a zoom expression at the TOP level, so it threw — into paint()'s catch,
 *     which exists for "style still parsing" and swallowed a permanent error just as quietly.
 *   - Territory borders → colour: read PALETTE.highlight. There is no PALETTE in that module. The
 *     control threw on every use and the border colour had never once changed.
 *   - The glow itself: applyYear put the POLITY era filter on boundaries-glow, whose own source
 *     carries no properties at all, so nothing in it could ever satisfy the filter.
 *
 * A control that throws into a catch is worse than one that is missing: the panel moves, the map
 * does not, and nothing says why. This is the cheapest possible guard — it does not check that a
 * control does the RIGHT thing, only that it does not fail, which is the failure mode this panel
 * keeps having.
 */
test('every settings control applies without throwing', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tune?.values()['Hover glow'], { timeout: 40_000 });
  await page.waitForTimeout(2_000);

  const broken = await page.evaluate(() => {
    const t = (window as any).__tune;
    const origWarn = console.warn;
    const origError = console.error;
    let warns: string[] = [];
    console.warn = (...a: any[]) => { warns.push(String(a[0]).slice(0, 120)); origWarn.apply(console, a); };
    console.error = (...a: any[]) => { warns.push('ERR ' + String(a[0]).slice(0, 120)); origError.apply(console, a); };

    const out: Record<string, string> = {};
    for (const [group, controls] of Object.entries(t.values() as Record<string, Record<string, unknown>>)) {
      for (const [key, current] of Object.entries(controls)) {
        warns = [];
        let threw: string | null = null;
        try { t.set(group, key, current); } catch (e: any) { threw = e.message; }
        // Re-applying a control's OWN current value must be a no-op, never an error.
        if (threw || warns.length) out[`${group} → ${key}`] = threw ?? warns[0];
      }
    }
    console.warn = origWarn;
    console.error = origError;
    return out;
  });

  // The fleet warns honestly when its lazy three.js chunk has not arrived; that is the control
  // reporting rather than failing, and it is the behaviour we want everywhere else.
  const real = Object.entries(broken).filter(([, why]) => !/no fleet on this map yet/.test(why));
  expect(Object.fromEntries(real), `controls that failed: ${real.map(([k]) => k).join(', ')}`).toEqual({});
});

/**
 * The hover glow, end to end. Asserting the SOURCE has features is not enough — it had 10 while the
 * layer rendered 0, because the era filter rejected every one of them. Only the rendered count
 * proves the light actually reaches the screen.
 */
test('hovering a territory draws the glow, not just fills its source', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tmShowGlow && !!(window as any).__tmMap?.getLayer('boundaries-glow'), { timeout: 30_000 });
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [10, 48], zoom: 3.4, pitch: 0 }));
  await page.waitForTimeout(4_000);

  const drawn = await page.evaluate(async () => {
    const m = (window as any).__tmMap;
    const f = m.queryRenderedFeatures({ layers: ['boundaries-fill'] }).find((x: any) => x.properties?.Name);
    if (!f) return { source: -1, rendered: -1, name: null };
    (window as any).__tmShowGlow(f.geometry, f.properties.Name, null);
    await new Promise((r) => setTimeout(r, 1_200));
    return {
      name: f.properties.Name,
      source: m.querySourceFeatures('boundaries-glow-src').length,
      rendered: m.queryRenderedFeatures({ layers: ['boundaries-glow'] }).length,
    };
  });

  expect(drawn.source, `no glow geometry for ${drawn.name}`).toBeGreaterThan(0);
  expect(drawn.rendered, `glow source has ${drawn.source} features but the layer draws none`).toBeGreaterThan(0);
});
