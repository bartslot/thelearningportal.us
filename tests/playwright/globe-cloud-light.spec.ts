import { test, expect } from '@playwright/test';

/**
 * The cloud deck's lighting, term by term.
 *
 * "I want the sun to shine on the clouds" is not a thing a screenshot can settle, and a lighting
 * model assembled all at once cannot be taken apart again when one part of it is wrong. So all four
 * terms are zero-is-off, each is turned on alone against a common baseline, and what each one does
 * is read in pixels.
 *
 * TWO OF THESE ARE PHYSICS CLAIMS, and those are the assertions worth having. The other two are
 * only "does something happen", which is worth much less but still worth catching when a uniform
 * silently stops being set.
 *
 * Not volumetric, and deliberately. A raymarched slab was built and measured on this branch at
 * 21 fps close up on an M3 Pro against 60 for the shell — structural, up to 112 density evaluations
 * a pixel, against a school Chromebook. This is a lit surface: cloud coverage read as a heightfield,
 * which is what makes the ground's own relief pipeline reusable on it.
 *
 * Needs the harness server:  npx vite --config vite.harness.config.js
 */

const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5198/';

type Lighting = {
  samples: number;
  shape: { meanChange: number; spread: number; flatSpread: number };
  beerPowder: {
    meanChange: number; thinRatio: number; thickRatio: number;
    flatThinRatio: number; flatThickRatio: number;
  };
  forward: { meanChange: number; bySunAngle: Record<string, number> };
  selfShadow: { meanChange: number };
};

let light: Lighting;

test.beforeAll(async ({ browser }) => {
  // The default 60 s is not enough and the shortfall is invisible in the report: the hook times out
  // and every assertion below is listed as skipped, which reads like a suite that was not run
  // rather than one that ran out of time. This hook waits for the whole measurement suite and then
  // drives twenty more frames of its own — four terms plus a seven-step sweep of the sun.
  test.setTimeout(300_000);
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
  expect(await page.evaluate(() => (window as any).__harnessError)).toBeFalsy();

  light = await page.evaluate(() => (window as any).__cloudLighting());
  expect(failures, `page errors: ${failures.join(', ')}`).toHaveLength(0);
  await page.close();
});

test('there is cloud in the sample at all', () => {
  // Every ratio below divides by the deck's own brightness. Over an empty sky they would all be
  // 1.000 and every assertion would pass on nothing.
  expect(light.samples).toBeGreaterThan(5_000);
  expect(light.shape.flatSpread).toBeGreaterThan(5);
});

test('the normal from the mask gradient adds structure', () => {
  // Shaded by the DIFFERENCE from the sphere's own normal, exactly as the ground's relief is, so it
  // adds shape without relighting the deck: the spread rises and the mean barely moves.
  expect(light.shape.spread).toBeGreaterThan(light.shape.flatSpread * 1.08);
  expect(Math.abs(light.shape.meanChange)).toBeLessThan(light.shape.flatSpread * 0.25);
});

/**
 * THE ONE THAT SEPARATES CLOUD FROM ROCK.
 *
 * Cloud is dominated by multiple scattering, so thickness makes it BRIGHTER — the opposite of a
 * solid, where only the angle to the light matters and thickness does nothing at all. A Lambert
 * term on the same normal would leave thin rim and thick core in exactly the same ratio to the
 * unlit deck, and it would look like grey terrain.
 *
 * So this is not "the term changed something". It is: thin edges must lose brightness that thick
 * centres keep. The unlit deck is measured in the same two bins as a control, and it comes back
 * 1.000 and 1.000 — which is what makes the difference below attributable to the term rather than
 * to the two bins being different sky.
 */
test('is lit as a scattering medium, not as a solid: thin edges darken, thick centres do not', () => {
  expect(light.beerPowder.flatThinRatio).toBeCloseTo(1, 2);
  expect(light.beerPowder.flatThickRatio).toBeCloseTo(1, 2);

  expect(
    light.beerPowder.thinRatio,
    `thin cloud kept ${light.beerPowder.thinRatio} of its brightness — the powder term is not biting`,
  ).toBeLessThan(0.7);

  /**
   * AND A FLOOR, which this spec did not have and needed.
   *
   * Reselecting the patches on contrast moved this to -0.07: not dim, INVERTED — the thinnest
   * quarter of the deck was coming out darker than the surface under it, because a deck at zero
   * brightness still writes alpha and subtracts from the ground. Thin cloud scatters skylight and is
   * never darker than the sea; it read as a dark veil where there should have been white wisps.
   *
   * Every assertion here passed through it. "Thin is darker than thick" is satisfied just as well by
   * a hole in the sky, so the bound that was missing is the one saying it is still cloud.
   */
  expect(
    light.beerPowder.thinRatio,
    'thin cloud is darker than the surface beneath it — the deck is subtracting light, not scattering it',
  ).toBeGreaterThan(0.05);
  expect(light.beerPowder.thickRatio).toBeGreaterThan(0.85);
  expect(
    light.beerPowder.thickRatio - light.beerPowder.thinRatio,
    'thin and thick were affected equally, which is what Lambert on a cloud normal looks like',
  ).toBeGreaterThan(0.25);
});

/**
 * The silver lining, and WHERE it lands.
 *
 * Droplets are far larger than the wavelength, so they throw light forward: a cloud between you and
 * the sun is brighter at its rim than anywhere on its lit face. The scattering angle is fixed by
 * where the sun is relative to the CAMERA, not by where in the frame you look — so the sun is
 * walked round the planet and the light the term adds is read at each step.
 *
 * It has to RISE as the sun goes behind, and peak at the terminator. Past that the ground is night
 * and the night floor takes it, which is correct: what is left there is a rim, not a glow.
 */
test('forward scattering rises as the sun goes behind, and peaks at the terminator', () => {
  const at = (deg: number) => light.forward.bySunAngle[String(deg)];

  expect(at(0)).toBeGreaterThan(0);
  expect(at(30)).toBeGreaterThan(at(0));
  expect(at(60)).toBeGreaterThan(at(30));
  expect(at(90)).toBeGreaterThan(at(60));

  // The peak is the terminator, and it is a real one — not the curve still climbing off the end.
  expect(at(90)).toBeGreaterThan(at(110));
  expect(
    at(90) / Math.max(0.01, at(0)),
    'the term barely varies with the sun, so it is a uniform lift rather than a phase function',
  ).toBeGreaterThan(2);
});

test('self-shadowing only ever takes light away', () => {
  expect(light.selfShadow.meanChange).toBeLessThan(-1);
  // And not so much that it has stopped being a shadow and become a dimmer. Measured at 25 km of
  // reach it took 33 luma off the deck almost evenly, which is the failure this bound is for.
  expect(
    light.selfShadow.meanChange,
    'the self-shadow is darkening the whole deck rather than shading its sunward edges',
  ).toBeGreaterThan(-30);
});
