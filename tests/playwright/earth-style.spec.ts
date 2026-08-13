import { test, expect } from '@playwright/test';

/**
 * The Earth style actually renders.
 *
 * Not assertable from the Browser pane: it runs pages hidden, MapLibre never parses its style
 * there, and the map reports zero layers forever. Four sessions have rediscovered that; this spec
 * exists so the answer comes from a browser that is actually painting.
 *
 * What it guards is the failure this style was built out of — layers that were merged, tested and
 * never imported by anything. A screenshot cannot see that. The layer ids can.
 */
const LESSON = process.env.PW_LESSON_ID ?? '3';

/**
 * What the LESSON MAP's surface asks for — not the whole stack.
 *
 * Checked with map.getLayer(), NEVER by scanning map.getStyle().layers — getStyle() serialises the
 * style SPEC, and a custom layer is runtime-only, so it is absent from that list even while it is
 * attached and drawing. Reading the wrong one reported an empty globe twice and sent me chasing a
 * bug that was in the test.
 *
 * This used to be all seven, from when the globe was a style you picked and got in full. It is now
 * resolved against SURFACES.lessonMap, whose budget is 1.5 MB against the Time-Map's 8: `sun` and
 * `clouds` are dropped as out of scope on every device, and addGlobeLayers never creates them, so
 * their textures are never fetched. cloud-patches.webp alone is 1.8 MB — over this surface's
 * single-asset ceiling by itself.
 *
 * Asserting five here rather than seven is therefore the point, not a concession: the two absences
 * below are what proves the budget is being honoured.
 */
const GLOBE_LAYERS = ['tm-atmosphere', 'tm-daylight', 'tm-moon', 'tm-ocean', 'tm-starfield'];

/** Dropped by the surface budget. Their presence would mean the plan was ignored. */
const OUT_OF_BUDGET_LAYERS = ['tm-sun', 'tm-clouds'];

/**
 * Open a scene that actually has a map on it, then wait for that map.
 *
 * Both tests used to go straight to waiting for `__lessonMap` after the page load, which only ever
 * worked if the lesson happened to open on a map scene. Lesson 3 opens on a Quiz, so the wait timed
 * out at two minutes and the failure read as "the globe is broken" when nothing had been asked to
 * render one. Selecting the scene is part of the journey being tested, not setup around it.
 */
const openMapScene = async (page: import('@playwright/test').Page) => {
  await page.goto(`/teacher/lessons/${LESSON}/wizard?step=4`);

  // The rail is Livewire-rendered and its blocks arrive after the first paint.
  const mapScene = page.locator('[data-scene-block]').filter({ hasText: /map/i }).first();
  await mapScene.waitFor({ state: 'visible', timeout: 60_000 });
  await mapScene.click();

  await page.waitForFunction(
    () => (window as any).__lessonMap?.isStyleLoaded?.() === true,
    null,
    { timeout: 120_000 },
  );
};

test('picking Earth adds the globe layers to the map', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message.slice(0, 160)));

  await openMapScene(page);

  // No style is picked first, because there is nothing to pick. The five drawn atlases and the
  // swatch picker were removed; the lesson map has one ground and it is the Earth, so the globe
  // must be there on the load path every teacher takes, with no action to trigger it.

  // By the ids their own modules export. Waiting on the ids rather than on a pixel means
  // a layer that loads but draws nothing still fails here, which is the honest bar for "wired".
  await expect
    .poll(async () =>
      page.evaluate((ids: string[]) =>
        ids.filter((id) => !!(window as any).__lessonMap.getLayer(id)).sort(), GLOBE_LAYERS),
      { timeout: 90_000, intervals: [2000] })
    .toEqual(GLOBE_LAYERS);

  // The budget was applied, not merely computed. A layer that is absent has not been fetched —
  // which is the whole saving, since the download is what binds on school wifi.
  const overspent = await page.evaluate((ids: string[]) =>
    ids.filter((id) => !!(window as any).__lessonMap.getLayer(id)), OUT_OF_BUDGET_LAYERS);
  expect(overspent, 'a layer outside the lesson-map budget was added anyway').toEqual([]);

  // And the panel has something on the other end of its switches, which it never had before.
  expect(await page.evaluate(() => typeof (window as any).__tmSetLayer)).toBe('function');

  // The panel can say WHY a row is not on offer. Without this the greying has no source and the
  // rows would silently all look available.
  expect(await page.evaluate(() => typeof (window as any).__globeAvailability)).toBe('function');
  const clouds = await page.evaluate(() => (window as any).__globeAvailability(['clouds']).clouds);
  // `scope`, never `device` — this surface does not ask for clouds on any machine, and blaming the
  // teacher's GPU would send them looking for a fault that is not there.
  expect(clouds.reason).toBe('scope');

  expect(errors, `page errors: ${errors.join(' | ')}`).toEqual([]);

  await page.screenshot({ path: 'tests/playwright/earth-style.png', fullPage: false });
});

/**
 * There is no way left to switch the globe off.
 *
 * This replaces "switching to Earth live also brings the layers in", which clicked Night and then
 * Earth on the swatch picker. Both the picker and setLessonMapStyle are gone, so that test was
 * asserting a control nobody can reach.
 *
 * What is worth keeping is the other half of the same guarantee. The load path is covered above;
 * this covers the thing that would quietly undo it — a style gate creeping back in and stripping
 * the layers on some later redraw. So it provokes the redraws (a year change, a resize) and
 * insists they are all still attached afterwards.
 */
test('the globe survives the redraws that used to switch it off', async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await openMapScene(page);
  await page.evaluate((ids) => { (window as any).__GLOBE_IDS = ids; }, GLOBE_LAYERS);

  const tmLayers = () => page.evaluate(() =>
    (window as any).__GLOBE_IDS.filter((id: string) => !!(window as any).__lessonMap.getLayer(id)).length);

  await expect.poll(tmLayers, { timeout: 90_000, intervals: [2000] }).toBe(GLOBE_LAYERS.length);

  // A year change re-runs the filters and the late-layer paint; a resize re-runs the style pass.
  // Both are the moments a reintroduced gate would fire on.
  await page.evaluate(() => (window as any).__lessonMap?.setYear?.(1500));
  await page.waitForTimeout(3000);
  await page.setViewportSize({ width: 1100, height: 800 });
  await page.waitForTimeout(3000);

  expect(await tmLayers()).toBe(GLOBE_LAYERS.length);

  // And no teacher-facing control exists that could take them away.
  await expect(page.locator('[wire\\:click^="setLessonMapStyle"]')).toHaveCount(0);
});
