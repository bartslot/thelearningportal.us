import { test, expect, Page } from '@playwright/test';

/**
 * Auth: uses the real login form with a playwright test account seeded into Supabase.
 * Playwright's browser context handles CSRF tokens and session cookies automatically.
 *
 * Auth setup: one-time seed (if not done):
 *   php artisan tinker --execute="App\Models\User::create(['name'=>'Playwright Teacher',
 *     'email'=>'teacher@playwright.test','password'=>bcrypt('playwright123'),'role'=>'teacher']);"
 *
 * WebGL: playwright.config.ts passes --enable-unsafe-swiftshader / --use-angle=swiftshader
 * so MapLibre can initialise in headless Chromium via software rendering (~6s load time).
 *
 * Server: start normally with `php artisan serve` (no .env changes needed).
 */
async function loginAsTeacher(page: Page): Promise<void> {
  // When APP_AUTO_LOGIN=true (local dev), every request is auto-authenticated as a seeded teacher,
  // so the /login form immediately redirects away — there's no form to fill. Detect that and skip.
  await page.goto('/login');
  if (!page.url().includes('/login')) return; // auto-login already signed us in
  await page.fill('#email', 'teacher@playwright.test');
  await page.fill('#password', 'playwright123');
  await page.click('button[type=submit]');
  // Wait for redirect away from /login (successful auth lands on teacher dashboard)
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 10_000 });
}

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
  await expect(page.locator('.tm-readout')).toContainText('years ago');
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
 * Hovering a territory adds a second name layer on top of the resting one. They draw the same words
 * in the same place, from different sources, so a territory that already had a label showed it
 * TWICE, half a line apart. The first attempt at this aligned the two so the duplicate at least sat
 * straight — which is not a fix, it is a tidier bug.
 */
test('hovering a named territory does not print its name twice', async ({ page }) => {
  await page.waitForFunction(() => !!(window as any).__tmMap?.getLayer('boundaries-label'), { timeout: 25_000 });
  await page.waitForTimeout(4_000);

  const name: string | null = await page.evaluate(() => {
    const m = (window as any).__tmMap;
    const f = m.queryRenderedFeatures({ layers: ['boundaries-label'] })[0];
    return f?.properties?.name ?? null;
  });
  expect(name, 'needed at least one labelled territory to hover').toBeTruthy();

  // Drive showGlow the way a hover does.
  await page.evaluate((territory) => {
    const m = (window as any).__tmMap;
    const geom = m.queryRenderedFeatures({ layers: ['boundaries-fill'] })
      .find((f: any) => f.properties?.Name === territory)?.geometry;
    (window as any).__tmShowGlow(geom ?? null, territory, null);
  }, name);
  // The filter needs a render pass before queryRenderedFeatures reflects it.
  await page.waitForTimeout(1_500);

  // The hover layer is showing this name, so the resting layer must not be.
  const resting = await page.evaluate((territory) =>
    (window as any).__tmMap.queryRenderedFeatures({ layers: ['boundaries-label'] })
      .filter((f: any) => f.properties?.name === territory).length, name);
  expect(resting, `"${name}" still drawn by boundaries-label while hovered`).toBe(0);

  // And it comes back when the pointer leaves, or hovering would permanently erase names.
  await page.evaluate(() => (window as any).__tmShowGlow(null));
  await page.waitForTimeout(1_500);
  const restored = await page.evaluate((territory) =>
    (window as any).__tmMap.queryRenderedFeatures({ layers: ['boundaries-label'] })
      .filter((f: any) => f.properties?.name === territory).length, name);
  expect(restored, `"${name}" never came back after the hover ended`).toBeGreaterThan(0);
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
