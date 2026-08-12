import { test, expect } from '@playwright/test';
import { loginAsTeacher } from './support/auth';

/**
 * The hand-authored territory layer, checked where it actually has to work: on the map.
 *
 * The unit tests already cover the geometry and the provenance record. What they cannot cover is
 * the claim this feature is actually making — that a territory we drew ourselves BEHAVES like an
 * ordinary one. That is a claim about MapLibre: about whether the year filter reaches it, whether
 * the label pass sees it, whether a click on it opens the panel. Every one of those is a place a
 * separate code path could quietly have been added, and a unit test would never notice.
 *
 * The acceptance case is Teutoburg Forest, 9 CE. Cliopatria's earliest Germanic polity is the Goths
 * at 30 CE, so before this layer that year had nothing at all to shade in northern Germania.
 */

const TEUTOBURG = 9;
const MARCOMANNIC_WARS = 170;

/** Germania at a teaching zoom, not the globe. Tiles stop at z4, so errors are magnified here. */
const overGermania = async (page: any, zoom = 4.4) =>
  page.evaluate((z: number) => (window as any).__tmMap.jumpTo({ center: [10.5, 51.5], zoom: z, pitch: 0 }), zoom);

/** Every territory name currently placed by the map's one label layer. */
const labelsOnScreen = (page: any): Promise<string[]> =>
  page.evaluate(() => (window as any).__tmMap
    .queryRenderedFeatures({ layers: ['boundaries-label'] })
    .map((f: any) => String(f.properties?.name ?? '')));

const renderedNames = (page: any, layer: string): Promise<string[]> =>
  page.evaluate((l: string) => [...new Set(
    (window as any).__tmMap.queryRenderedFeatures({ layers: [l] })
      .map((f: any) => String(f.properties?.Name ?? '')),
  )] as string[], layer);

test.beforeEach(async ({ page }) => {
  await loginAsTeacher(page);
  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 25_000 });
  await page.waitForFunction(() => !!(window as any).__tmMap?.getLayer('hand-fill'), { timeout: 25_000 });
});

test('Teutoburg Forest, 9 CE: the Germanic peoples are on the map at all', async ({ page }) => {
  await page.evaluate((y) => (window as any).__setTimemapYear(y), TEUTOBURG);
  await overGermania(page);
  await page.waitForTimeout(3_000);

  const names = await renderedNames(page, 'hand-fill');

  expect(names, `rendered at 9 CE: ${names.join(', ')}`).toContain('Cherusci');
  expect(names).toContain('Chatti');
  expect(names).toContain('Suebi');

  await page.screenshot({ path: 'tests/playwright/results/hand-territories-9ce.png' });
});

test('the Marcomannic Wars have their belligerents, and Bohemia is empty before the migration', async ({ page }) => {
  await page.evaluate((y) => (window as any).__setTimemapYear(y), MARCOMANNIC_WARS);
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [15.5, 49.5], zoom: 4.4, pitch: 0 }));
  await page.waitForTimeout(3_000);
  expect(await renderedNames(page, 'hand-fill')).toEqual(expect.arrayContaining(['Marcomanni', 'Quadi']));

  // 30 BCE: the Marcomanni had not yet moved into Bohemia under Maroboduus, so the shape we draw
  // there must not be on screen. This is the assertion that a lazy "always visible" layer fails —
  // the one above passes just as happily whether or not the year filter reaches this source at all.
  await page.evaluate(() => (window as any).__setTimemapYear(-30));
  await page.waitForTimeout(3_000);
  expect(await renderedNames(page, 'hand-fill')).not.toContain('Marcomanni');
});

test('a hand-authored territory is labelled by the same pass as every other one', async ({ page }) => {
  await page.evaluate((y) => (window as any).__setTimemapYear(y), TEUTOBURG);
  await overGermania(page);

  // boundaries-label is the ONE name layer on this map, fed by refreshLabels from the rendered
  // fills. A name appearing there is proof the label pass queried our source, not a second one.
  await expect.poll(() => labelsOnScreen(page), {
    message: 'the territory name never reached the label layer',
    timeout: 20_000,
  }).toContain('Cherusci');
});

/**
 * The Chatti had a fill and no name, and only the Chatti.
 *
 * MapLibre resolves label collisions from the TOP of the layer stack down, so the last symbol layer
 * added wins. markers-label was added after boundaries-label, which meant the hand-placed "GERMANIA"
 * point — put on this map precisely BECAUSE there were no Germanic territories — outranked the
 * territory that replaced it. It hid exactly one name, because the Chatti is the only one whose
 * centroid lands near that marker, which is why it looked like a one-off rather than a rule.
 *
 * Asserting all five names at once is what makes this a regression test rather than a coincidence:
 * a fix that helps the Chatti by pushing some other name out would fail it.
 */
test('every seeded people is named, not just filled', async ({ page }) => {
  await page.evaluate((y) => (window as any).__setTimemapYear(y), TEUTOBURG);
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [11, 51.4], zoom: 4.6, pitch: 0 }));

  // POLLED, not slept on. The label pass is debounced behind moveend AND the border tiles settling,
  // so a fixed wait is a bet on how loaded the machine is — this test failed with an EMPTY label
  // list the first time the whole suite ran together, which says nothing about the labels at all.
  for (const people of ['Cherusci', 'Chatti', 'Suebi']) {
    await expect.poll(() => labelsOnScreen(page), {
      message: `${people} has a fill but no name`,
      timeout: 25_000,
    }).toContain(people);
  }
});

test('clicking one opens the panel with its sources, its grades and what they disagree about', async ({ page }) => {
  await page.evaluate((y) => (window as any).__setTimemapYear(y), TEUTOBURG);
  // Zoomed onto the Cherusci label arc itself, so the centre of the canvas is inside the shape.
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [9.47, 52.27], zoom: 5, pitch: 0 }));
  await page.waitForTimeout(3_000);

  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2);

  const aside = page.locator('aside');
  await expect(aside).toContainText('Cherusci');

  // The judgement is never behind a disclosure: a teacher deciding whether to use this has to be
  // told how firm it is without opening anything.
  await expect(aside).toContainText('Where this border comes from');
  await expect(aside.getByText(/atlas prints the name/i)).toBeVisible();

  // The dates come from the FEATURE. The enrichment endpoint reads Cliopatria's era table, which
  // has no Cherusci, so a panel wired to it would show an empty lifespan here.
  await expect(aside).toContainText('30 BCE');
  await expect(aside).toContainText('300 CE');

  await page.getByRole('button', { name: /Sources \(\d+\)/ }).click();
  await expect(aside).toContainText('Barrington Atlas');
  await expect(aside).toContainText('Scholarly atlas');       // the grade, in plain language
  await expect(aside).toContainText('Drawn from this one');   // which one the shape came from
  await expect(aside).toContainText('Where the sources disagree');

  await page.screenshot({ path: 'tests/playwright/results/hand-territory-provenance.png' });
});

test('the Suebi say out loud that they are a guess; the Cherusci do not', async ({ page }) => {
  // Confidence has to be VISIBLE, or recording it honestly in the data achieves nothing.
  //
  // toBeVisible, NOT toContainText. The warning is an x-show, so it stays in the DOM with
  // display:none when it does not apply — and toContainText reads textContent, which includes
  // hidden nodes. Written that way, the negative half of this test passed on a panel that was
  // showing the warning on every single territory. Asked me to look twice, which is the point.
  const openAt = async (lng: number, lat: number, zoom: number) => {
    await page.evaluate(([x, y, z]) => (window as any).__tmMap.jumpTo({ center: [x, y], zoom: z, pitch: 0 }), [lng, lat, zoom]);
    await page.waitForTimeout(2_500);
    const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
    await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2);
    await page.waitForTimeout(800);
  };
  const warning = page.locator('aside').getByText('Low confidence.');

  await page.evaluate((y) => (window as any).__setTimemapYear(y), TEUTOBURG);

  // The Suebi shape covers the middle Elbe; the others do not reach here.
  await openAt(12.2, 52.1, 5.5);
  await expect(page.locator('aside')).toContainText('Suebi');
  await expect(warning).toBeVisible();

  await openAt(9.47, 52.27, 5.5);
  await expect(page.locator('aside')).toContainText('Cherusci');
  await expect(warning).toBeHidden();
});

test('the dialog shows the same year it sends', async ({ page }) => {
  // It did not. `window.__portal` is not an Alpine dependency, so an x-text reading it evaluated
  // once at first render and never again: the dialog said "0 CE" while correctly posting 9. A
  // number shown to a teacher that differs from the number sent is worse than showing none, and
  // only a test that reads BOTH can tell — the report test alone passed throughout.
  const posted: any[] = [];
  page.on('request', (r) => {
    if (r.url().includes('/teacher/timemap/report') && r.method() === 'POST') posted.push(JSON.parse(r.postData() ?? '{}'));
  });

  await page.evaluate(() => (window as any).__setTimemapYear(-30));
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [9.47, 52.27], zoom: 5, pitch: 0 }));
  await page.waitForTimeout(3_000);

  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2);
  await expect(page.locator('aside')).toContainText('Cherusci');

  await page.getByRole('button', { name: 'Report', exact: true }).click();
  await expect(page.locator('dialog')).toContainText('30 BCE');

  await page.locator('dialog textarea').fill('Checking the year travels with it.');
  await page.getByRole('button', { name: 'Send report' }).click();
  await expect(page.locator('dialog')).toContainText('Thank you', { timeout: 10_000 });

  expect(posted[0].year, 'the year shown and the year sent are different numbers').toBe(-30);
});

test('the edge is softer than a Cliopatria border, and softest where confidence is low', async ({ page }) => {
  // The map saying in paint what the panel says in words. Read off the layer rather than a
  // screenshot, because a blur of 1.4 against 3.2 is not something a pixel diff can be trusted on.
  const blur = await page.evaluate(() => (window as any).__tmMap.getPaintProperty('hand-line', 'line-blur'));
  const dash = await page.evaluate(() => (window as any).__tmMap.getPaintProperty('hand-line', 'line-dasharray'));

  expect(JSON.stringify(blur), 'the hand-drawn edge is not confidence-driven').toContain('low');
  expect(dash, 'the hand-drawn edge is not dashed').toBeTruthy();

  const cliopatriaBlur = await page.evaluate(() => (window as any).__tmMap.getPaintProperty('boundaries-line', 'line-blur'));
  expect(Number(cliopatriaBlur) || 0, 'a Cliopatria border should be the crisp one').toBeLessThan(1);
});

test('a teacher can report it, and the report carries the year they were looking at', async ({ page }) => {
  const posted: any[] = [];
  page.on('request', (r) => {
    if (r.url().includes('/teacher/timemap/report') && r.method() === 'POST') posted.push(JSON.parse(r.postData() ?? '{}'));
  });

  await page.evaluate((y) => (window as any).__setTimemapYear(y), TEUTOBURG);
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [9.47, 52.27], zoom: 5, pitch: 0 }));
  await page.waitForTimeout(3_000);

  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2);
  await expect(page.locator('aside')).toContainText('Cherusci');

  await page.getByRole('button', { name: 'Report', exact: true }).click();
  await page.locator('dialog textarea').fill('The eastern edge should reach the Elbe.');
  await page.getByRole('button', { name: 'Send report' }).click();

  await expect(page.locator('dialog')).toContainText('Thank you', { timeout: 10_000 });

  expect(posted, 'nothing was posted').toHaveLength(1);
  // THE assertion. Without the year, the reported border cannot be put back on screen and the
  // report is a complaint nobody can reproduce.
  expect(posted[0].year).toBe(TEUTOBURG);
  expect(posted[0].territory_id).toBe('cherusci');
  expect(posted[0].source).toBe('hand');
  expect(posted[0].comment).toContain('Elbe');
});

test('the report control is on an imported territory too, not just on ours', async ({ page }) => {
  // A Cliopatria error is every bit as worth hearing about, and a control that appears on only one
  // kind of territory is a per-branch difference — a defect unless it was intended, and it was not.
  await page.evaluate(() => (window as any).__setTimemapYear(1500));
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [2.5, 47], zoom: 4.5, pitch: 0 }));
  await page.waitForTimeout(4_000);

  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2);

  const aside = page.locator('aside');
  await expect(aside).toContainText('Something not right?');
  // …and no provenance block, because an imported border has none of ours to show.
  await expect(aside).not.toContainText('Where this border comes from');
});

test('the settings panel owns the edge, and a year scrub does not wipe it', async ({ page }) => {
  // The trap this codebase has already sprung twice: a control calls setPaintProperty, and the
  // app's own repaint overwrites it a moment later with nothing to say it happened.
  await page.waitForFunction(() => !!(window as any).__tune?.set, { timeout: 25_000 });

  expect(await page.evaluate(() => (window as any).__tune.set('Hand-drawn territories', 'handOpacityLow', 0.9))).toBe(true);
  expect(await page.evaluate(() => (window as any).__tune.set('Hand-drawn territories', 'handBlurMedium', 5))).toBe(true);

  const survives = async (tag: string) => {
    const paint = await page.evaluate(() => ({
      opacity: JSON.stringify((window as any).__tmMap.getPaintProperty('hand-line', 'line-opacity')),
      blur: JSON.stringify((window as any).__tmMap.getPaintProperty('hand-line', 'line-blur')),
    }));
    expect(paint.opacity, `edge opacity gone after ${tag}`).toContain('0.9');
    expect(paint.blur, `edge softness gone after ${tag}`).toContain('5');
  };

  await survives('being set');

  await page.evaluate(() => (window as any).__setTimemapYear(1750));
  await page.waitForTimeout(1_200);
  await survives('a year scrub');

  await page.evaluate(() => (window as any).__applyMapStyle('night'));
  await page.waitForTimeout(1_500);
  await survives('a style switch');
});
