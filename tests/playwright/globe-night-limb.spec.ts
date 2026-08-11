import { test, expect } from '@playwright/test';

/**
 * The night limb must be black.
 *
 * In the NASA photograph of the earth from orbit the lit crescent has a bright rim of air above it
 * and the dark side simply ends — no glow, no halo, nothing on the unlit limb at all. Ours ringed
 * the whole planet, because the haze band was gated on the angle between a parcel of air and the
 * sun rather than on whether the earth was standing in the way. Air twenty degrees past the
 * terminator still scored a fifth of full brightness, all the way round.
 *
 * WHY THIS TEST IS ABSOLUTE AND NOT RELATIVE. The reading is excess luma over the harness's own
 * background colour, so "black" is a claim about a number near zero rather than about the night
 * side being darker than the day side. That distinction is the whole point: a uniform brightening
 * of the entire band — which is exactly the defect — leaves a day/night RATIO looking healthy while
 * the halo is plainly there. This programme has already lost months to a twenty-degree moon that
 * every relative assertion was happy with.
 *
 * Both limbs are read from ONE frame, at the same grazing angle, with the camera 90° from the
 * subsolar point. So the only thing that differs between the two numbers is whether the sun reaches
 * that air.
 *
 * Needs the harness server:  npx vite --config vite.harness.config.js --port 5191 --strictPort
 */

const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5191/';

type LimbProfile = {
  litLimb: number;
  unlitLimb: number;
  ratio: number | null;
  /** 36 buckets of 10°, bucket 0 pointing at the sun. */
  byAngle: number[];
  disc: { centre: { x: number; y: number }; radius: number };
  canvas: { width: number; height: number };
};

let profile: LimbProfile;

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ viewport: { width: 900, height: 700 } });
  const failures: string[] = [];
  page.on('pageerror', (error) => failures.push(error.message));

  const response = await page.goto(HARNESS, { waitUntil: 'domcontentloaded' }).catch(() => null);
  expect(
    response?.ok(),
    `harness not reachable at ${HARNESS} — run: npx vite --config vite.harness.config.js --port 5191 --strictPort`,
  ).toBe(true);

  await page.waitForFunction(() => (window as any).__limbProfile !== undefined, null, { timeout: 60_000 });
  // The map has to have finished loading before a capture means anything; the harness sets
  // __measured when its own suite completes, which is the cheapest signal that frames are flowing.
  await page.waitForFunction(
    () => (window as any).__measured === true || (window as any).__harnessError !== undefined,
    null,
    { timeout: 120_000 },
  );
  const harnessError = await page.evaluate(() => (window as any).__harnessError);
  expect(harnessError, 'the harness reported an error before the limb could be measured').toBeUndefined();

  profile = await page.evaluate(() => (window as any).__limbProfile());
  // eslint-disable-next-line no-console
  console.log('night-limb profile:', JSON.stringify(profile, null, 2));
  expect(failures, `page errors: ${failures.join('; ')}`).toHaveLength(0);
  await page.close();
});

test('the lit limb still has a band, so a black night limb is not just a dark frame', () => {
  // Guards the obvious way to pass this suite by accident: switching the layer off entirely. If
  // this is near zero every reading below is satisfied by a layer that draws nothing.
  expect(profile.litLimb).toBeGreaterThan(40);
});

test('the unlit limb adds no light at all', () => {
  /**
   * The measured before and after, in luma over the void, 0-255:
   *
   *   sector from the sun    0-90°   90-100°   100-110°   110-260°
   *   angular gate (was)      83.4     50.6       17.1        0
   *   shadow gate (now)       83.4     82.4        0.0        0
   *
   * 17.1 is the halo. It is a fifth of full brightness, a hundred degrees from the sun — air the
   * planet is standing in front of — and it ran right around the disc over the poles.
   */
  expect(profile.unlitLimb).toBe(0);
});

test('the band stops within ten degrees of the terminator', () => {
  // Buckets are 10° wide from the subsolar point. Everything from 100° out must be dead, not dim:
  // this is the assertion that a softer, more physical twilight would fail, and it is deliberate.
  // Bart's reference is the NASA photograph, where the dark limb carries no glow whatever.
  const pastTerminator = profile.byAngle.slice(10, 26);
  expect(Math.max(...pastTerminator)).toBe(0);
});

test('a sliver of twilight survives, so the terminator is not a hard edge', () => {
  // The 90-100° bucket. Zero here would mean the gate had been tightened into a cliff.
  expect(profile.byAngle[9]).toBeGreaterThan(20);
});
