import { test, expect } from '@playwright/test';

/**
 * The cloud deck must not visibly repeat.
 *
 * This is the acceptance test for the tiled source rather than a detail of it. The deck is built
 * from six harvested patches of real cloud, 626 km of sky each, spread over a planet 40,000 km
 * round. Something has to be repeated. The whole question is whether the eye can find it — and a
 * visible repeat over open ocean would be a worse artefact than the blur the source replaces, so
 * the change would be a net loss however much sharper it is.
 *
 * MEASURED, NOT LOOKED AT, for the ordinary reason: a periodicity at the edge of noticeable is
 * exactly the kind of thing two people disagree about and neither can settle. Autocorrelation
 * settles it. A repeat at period P puts a peak at lag P; a stochastic tiling has nothing but its
 * central peak.
 *
 * WHERE. Ireland and the North Atlantic — open ocean, and the place Bart photographed the blur
 * this replaces. Nothing but cloud is over it, so the deck is the only thing the measurement can be
 * reading.
 *
 * Needs the harness server:  npx vite --config vite.harness.config.js
 */

const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5198/';

type Axis = {
  recurrence: number; atLagPx: number; correlationThere: number; curve: number[];
};
type Repeat = {
  east: Axis; north: Axis; metresPerPixel: number;
  where: { lng: number; lat: number; zoom: number };
};

/**
 * How much the autocorrelation is allowed to rise back up after it has fallen.
 *
 * Zero for any aperiodic field. Positive only where something recurs — see the harness for why the
 * raw correlation could not tell the two cases apart.
 *
 * NOT a number picked to make this pass. Both ends were measured, by forcing the tiling to be plain
 * — one patch, one window, no rotation — and reading the same box:
 *
 *     plain tiling     east 0.681 (at 171 km)   north 0.295
 *     stochastic       east 0.028               north 0.060
 *
 * and the plain tiling's peak at 171 km is the lattice's own cell width at 53°N, 167 km, which is
 * the instrument finding the period it was supposed to find rather than merely returning a large
 * number. 0.15 sits in that gap with a factor of two either side. Anything nearer one end would be
 * a threshold shaped around the answer it was given.
 */
const RECURRENCE_LIMIT = 0.15;

let report: Repeat;

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ viewport: { width: 900, height: 700 } });
  const failures: string[] = [];
  page.on('pageerror', (error) => failures.push(error.message));

  const response = await page.goto(HARNESS, { waitUntil: 'domcontentloaded' }).catch(() => null);
  expect(response?.ok(), `harness not reachable at ${HARNESS} — run: npx vite --config vite.harness.config.js`)
    .toBe(true);

  await page.waitForFunction(
    () => (window as any).__measured === true || !!(window as any).__harnessError,
    null,
    { timeout: 180_000 },
  );
  const harnessError = await page.evaluate(() => (window as any).__harnessError);
  expect(harnessError, 'the harness reported an error before any measurement ran').toBeFalsy();

  report = await page.evaluate(() => (window as any).__cloudRepeat());
  expect(failures, `page errors: ${failures.join(', ')}`).toHaveLength(0);
  await page.close();
});

test.describe('the cloud deck over the North Atlantic', () => {
  test('is measured on cloud that is actually there', () => {
    // A flat difference image would make every correlation below meaningless, and would read as a
    // clean pass. The central peak is 1 by construction, so the guard is that the curve LEAVES it.
    expect(report.east.curve[0]).toBeCloseTo(1, 3);
    expect(report.north.curve[0]).toBeCloseTo(1, 3);
    expect(Math.min(...report.east.curve)).toBeLessThan(0.5);
  });

  test('does not repeat along either axis', () => {
    for (const axis of ['east', 'north'] as const) {
      expect(
        report[axis].recurrence,
        `${axis}: the autocorrelation rises ${report[axis].recurrence} above its own floor at lag `
        + `${report[axis].atLagPx} px `
        + `(${Math.round(report[axis].atLagPx * report.metresPerPixel / 1000)} km) — `
        + 'a curve that comes back up is the tiling showing itself',
      ).toBeLessThan(RECURRENCE_LIMIT);
    }
  });

  test('is looking at a patch of ocean big enough to contain a repeat', () => {
    /**
     * The measurement can only see a repeat whose period fits in the window it reads.
     *
     * A patch is 626 km. The window is 300 px, so unless a pixel covers less than about 2 km this
     * is measuring a strip narrower than one tile — where a repeat has no room to appear and the
     * test would pass because it could not see, which is the worst way for it to pass.
     */
    const windowKm = 300 * report.metresPerPixel / 1000;
    expect(windowKm, `the sample window is ${Math.round(windowKm)} km, under one 626 km patch`)
      .toBeGreaterThan(626);
  });
});
