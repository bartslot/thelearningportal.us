import { test, expect, Page } from '@playwright/test';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { loginAsTeacher } from './support/auth';
import { decode, meanColour, detail, sharpness, brightFraction, saturatedHueFraction, Frame } from './support/pixels';

/**
 * Visual regression for the Earth style. Everything here exists because the ordinary tests could not
 * see a single one of the failures that actually happened tonight:
 *
 *   - A missing colour in theme.json abandoned the load handler, so all seven globe layers — sea,
 *     terminator, cloud deck, haze, stars, sun, moon — were never added. The map still DREW, in flat
 *     base colours, and every layer-count and console assertion passed.
 *   - "Earth" was not a style on this map, so choosing it silently fell back to Soft Atlas. The map
 *     changed to something, so it read as a style rather than as a missing one.
 *   - 94,505 raster specks rendered as blocks of territory colour inside other countries.
 *
 * All three are RENDERING failures. Not one of them touches the DOM, throws, or changes a layer
 * count. The only witness is the picture.
 *
 * WHY THIS IS NOT A SCREENSHOT DIFF. The scene is deliberately non-deterministic: the cloud deck
 * drifts on a wind field, the tall ship sails, the sun sits where the year on the slider puts it. A
 * golden-image comparison would be red on every run for reasons that are all correct, and would be
 * silenced within a week. So this asserts PROPERTIES a human would name when looking at the
 * picture — is the sea dark and blue, is the ground photographed rather than drawn, are there stars
 * outside the disc — with margins wide enough that legitimate motion cannot trip them.
 *
 * The drift is slow enough to make this stable: 2.24 px per 30 s, so the couple of seconds of
 * variance between runs moves the clouds by a fraction of a pixel.
 */

const BASELINE = resolve(process.cwd(), 'tests/playwright/earth-visual.baseline.json');

/** A place on the globe, as a patch of screen. Named by geography so the test reads as a picture. */
type Place = { lng: number; lat: number; size: number };

const PLACES = {
  // Cloud-free almost always, and violently textured in Blue Marble — dune shadow, rock, sand.
  // A drawn atlas renders it as one flat beige, which is the whole point of measuring here.
  sahara: { lng: 12, lat: 23, size: 90 },
  // Open ocean well away from any coast. The only structure over it is cloud, so its high-frequency
  // energy IS the cloud deck's sharpness.
  midAtlantic: { lng: -32, lat: 36, size: 140 },
} satisfies Record<string, Place>;

async function patchFor(page: Page, place: Place) {
  const centre = await page.evaluate(
    ({ lng, lat }) => {
      const p = (window as any).__tmMap.project([lng, lat]);
      return { x: p.x, y: p.y };
    },
    { lng: place.lng, lat: place.lat },
  );
  return { x: centre.x - place.size / 2, y: centre.y - place.size / 2, w: place.size, h: place.size };
}

/** Same camera, same year, every run — so the only thing that moves is what is meant to. */
async function stageEarth(page: Page): Promise<Frame> {
  await page.waitForFunction(
    () => !!(window as any).__tmMap?.getLayer('boundaries-fill') && !!(window as any).__applyMapStyle,
    { timeout: 40_000 },
  );
  await page.evaluate(() => (window as any).__applyMapStyle('earth'));
  await page.evaluate(() => (window as any).__setTimemapYear(1600));
  await page.evaluate(() => (window as any).__tmMap.jumpTo({
    center: [-12, 34], zoom: 2.4, pitch: 0, bearing: 0,
  }));
  // The imagery is tiled and fetched per zoom; without this the ground is still the placeholder.
  await page.waitForTimeout(9_000);
  return decode(await page.screenshot());
}

test.beforeEach(async ({ page }) => {
  test.setTimeout(180_000);
  await loginAsTeacher(page);
  await page.goto('/teacher/timemap');
});

test('the Earth style renders as Earth: photographed ground, dark sea, stars in the sky', async ({ page }) => {
  const frame = await stageEarth(page);

  // ── Stars. The whole globe-layer stack is added in one block, and the starfield is the first of
  // the seven. If the block was abandoned part-way, space is empty and this is the cheapest witness.
  // Top-left corner of the frame is off the disc at this camera.
  const space = { x: 4, y: 60, w: 220, h: 150 };
  const stars = brightFraction(frame, space, 40);
  expect(stars, 'no stars in space — the globe layer stack did not finish').toBeGreaterThan(0.0004);
  expect(luma(meanColour(frame, space)), 'space is not dark, so that patch is not space').toBeLessThan(40);

  // ── The sea. Earth is a dark navy ocean; Soft Atlas is a pale blue-green wash. This is what tells
  // a silent style fallback from a style: both are "a map", only one is this colour.
  const sea = await patchFor(page, PLACES.midAtlantic);
  const [sr, sg, sb] = meanColour(frame, sea);
  expect(sb, 'the sea is not blue — is this the Earth style at all?').toBeGreaterThan(sr);
  expect(sb, 'the sea is not blue').toBeGreaterThan(sg * 0.95);
  expect(luma([sr, sg, sb]), 'the sea is too pale for Earth — this looks like the atlas fallback').toBeLessThan(150);

  // ── The ground. Photographed terrain has texture; a drawn fill does not. The Sahara is the
  // clearest place on the planet to ask the question.
  const land = await patchFor(page, PLACES.sahara);
  const landDetail = detail(frame, land);
  expect(landDetail, 'the ground is flat — imagery never loaded, or a drawn style is showing').toBeGreaterThan(6);

  // ── Territory specks. The selected fill is a hard amber and has no business over open ocean.
  // A mean would never catch this: 94,000 specks still average away against a whole sea.
  const amber = saturatedHueFraction(frame, sea, [245, 197, 24]);
  expect(amber, 'amber territory colour is rendering over the mid-Atlantic').toBeLessThan(0.002);

  await page.screenshot({ path: 'tests/playwright/results/earth-visual.png' });
});

/**
 * Metrics, not assertions with opinions.
 *
 * These are the numbers to judge the tiled-cloud work by. `oceanSharpness` is edge energy over open
 * water, where the only structure is cloud — so it puts a figure on "blurred blobs with weird
 * edges". It should RISE when real tiled cloud lands and must not fall.
 *
 * The floors are deliberately far below the current readings. This test's job is to catch a
 * collapse — a layer that stopped drawing, imagery that stopped loading — not to police taste, and a
 * metric that fails on a judgement call is one somebody deletes.
 */
test('records the Earth render metrics, and catches a collapse', async ({ page }) => {
  const frame = await stageEarth(page);
  const sea = await patchFor(page, PLACES.midAtlantic);
  const land = await patchFor(page, PLACES.sahara);

  const metrics = {
    oceanSharpness: +sharpness(frame, sea).toFixed(3),
    landDetail: +detail(frame, land).toFixed(3),
    seaLuma: +luma(meanColour(frame, sea)).toFixed(3),
    starFraction: +brightFraction(frame, { x: 4, y: 60, w: 220, h: 150 }, 40).toFixed(5),
  };
  console.log('EARTH_METRICS ' + JSON.stringify(metrics));

  if (!existsSync(BASELINE) || process.env.UPDATE_EARTH_BASELINE) {
    writeFileSync(BASELINE, JSON.stringify(metrics, null, 2) + '\n');
    console.log(`EARTH_METRICS wrote baseline → ${BASELINE}`);
    return;
  }

  const base = JSON.parse(readFileSync(BASELINE, 'utf8'));
  // Half the recorded value. A cloud deck that stops drawing, or ground imagery that stops loading,
  // falls off a cliff; tuning does not.
  for (const key of ['oceanSharpness', 'landDetail'] as const) {
    expect(metrics[key], `${key} collapsed: ${base[key]} → ${metrics[key]}`).toBeGreaterThan(base[key] * 0.5);
  }
});

/** luma is re-exported through the pixels module; imported here for the assertions above. */
function luma(rgb: number[]): number {
  return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
}
