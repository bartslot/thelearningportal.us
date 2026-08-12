import { test } from '@playwright/test';

/**
 * Photographs of the globe from the measurement harness. A camera, not a test — it asserts nothing.
 *
 * It exists because the two defects that mattered most on this branch were both found by LOOKING,
 * on a build whose every measurement was green: a lattice drawing hard triangles across the Atlantic
 * while non-repetition read 0.028, and a planet gone overcast from limb to limb. Numbers caught
 * neither, because neither was the property being measured.
 *
 * Uses the harness rather than the app so it needs no PHP, and `?imagery=1` so the ground is real
 * satellite rather than the flat grey the measurements want.
 *
 *   HARNESS_URL=http://localhost:5204/ SHOT_DIR=/tmp/shots \
 *     npx playwright test globe-shots.capture
 */
const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5198/';
const DIR = process.env.SHOT_DIR;
/** Cloud-layer options to override, as JSON — for photographing one term at a time. */
const OVERRIDE = process.env.SHOT_CLOUDS ? JSON.parse(process.env.SHOT_CLOUDS) : {};
/** Daylight-layer options to override — chiefly cloudShadow, to tell deck from shadow. */
const GROUND = process.env.SHOT_DAYLIGHT ? JSON.parse(process.env.SHOT_DAYLIGHT) : {};
const SUFFIX = process.env.SHOT_SUFFIX ?? '';

test.skip(!DIR, 'set SHOT_DIR to photograph');

const CAMERAS = [
  { name: 'globe', center: [-25, 20], zoom: 1.6 },
  { name: 'atlantic', center: [-25, 40], zoom: 3.4 },
  { name: 'ireland', center: [-9, 53], zoom: 5.2 },
];

test('photograph the globe', async ({ browser }) => {
  test.setTimeout(300_000);
  const page = await browser.newPage({ viewport: { width: 1000, height: 800 } });
  await page.goto(`${HARNESS}?imagery=1`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () => (window as any).__measured === true || !!(window as any).__harnessError,
    null,
    { timeout: 240_000 },
  );

  for (const camera of CAMERAS) {
    await page.evaluate(({ center, zoom, override, ground }) => {
      const w = window as any;
      // A fixed sun, so two runs differ only by what changed in the code. Low in the west puts the
      // terminator in shot on the globe frame, which is where the deck is worth looking at.
      w.__clouds.setOptions({ sun: w.__sunFor(center[0] - 55, 15), animate: false, ...override });
      w.__daylight.setOptions({ sun: w.__sunFor(center[0] - 55, 15), ...ground });
      w.__map.jumpTo({ center, zoom, bearing: 0, pitch: 0 });
    }, { ...camera, override: OVERRIDE, ground: GROUND });
    // The imagery is fetched per zoom; without this the ground is still the previous level.
    await page.waitForTimeout(6_000);
    await page.evaluate(() => (window as any).__map.triggerRepaint());
    await page.waitForTimeout(1_500);
    await page.screenshot({ path: `${DIR}/${camera.name}${SUFFIX}.png` });
  }
  await page.close();
});
