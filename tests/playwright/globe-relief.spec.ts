import { test, expect } from '@playwright/test';

/**
 * The globe's relief shading and cloud shadows, measured rather than looked at.
 *
 * Every failure these layers have had was invisible — a mirrored sky, a terminator half a
 * continent out, a normal map leaning the wrong way. All of them render, and all of them look
 * plausible. So this reads real pixels out of a real WebGL context and asserts on the numbers.
 *
 * WHY PLAYWRIGHT AND NOT THE BROWSER PANE. Two reasons, both of which cost time to find:
 *
 *  - The pane is frequently hidden, and a hidden document never fires requestAnimationFrame, so
 *    MapLibre never renders and the style never finishes loading. Nothing errors; the page simply
 *    sits at "loading" forever.
 *  - MapLibre's context has no `preserveDrawingBuffer`, so anything reading the canvas after the
 *    frame gets a cleared buffer. The pixels only exist inside a render pass, which is why the
 *    harness probe is itself a custom layer calling gl.readPixels in its own `render`.
 *
 * Needs the harness server:  npx vite --config vite.harness.config.js
 */

const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5199/';

type Difference = { mean: number; max: number; samples: number };
type Report = {
  terminatorRidge: { himalaya: Difference; tibet: Difference; ocean: Difference };
  sunOverhead: { himalaya: Difference };
  zoomFade: { z4: number; z7: number; z9: number; z11: number };
  magnification: {
    devicePixelRatio: number;
    samples: { zoom: number; pixelsPerTexel: number; amplitude: number; detail: number }[];
  };
  screen: { metresPerPixel: number };
  cloudShadow: Shadow & { strength: number };
  cloudShadowSunInEast: Shadow;
  noonShadowColour: { shadowedPixels: number; warmerThanGroundBy: number };
  eclipse: {
    predictedSunLeft: number; umbraLngLat: [number, number];
    atUmbra: { mean: number; max: number };
    litPixels: number; penumbraShare: number; totalityShare: number;
    onAnOrdinaryDay: { mean: number; max: number };
  };
};
type Shadow = {
  shiftEastPx: number; shiftNorthPx: number; correlation: number; interior: boolean;
  measuredOffsetKm: number; predictedOffsetKm: number;
};

let report: Report;

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ viewport: { width: 900, height: 700 } });
  const failures: string[] = [];
  page.on('pageerror', (error) => failures.push(error.message));

  const response = await page.goto(HARNESS, { waitUntil: 'domcontentloaded' }).catch(() => null);
  expect(response?.ok(), `harness not reachable at ${HARNESS} — run: npx vite --config vite.harness.config.js`)
    .toBe(true);

  // SwiftShader is not fast, and the run reads two dozen full frames.
  await page.waitForFunction(() => (window as any).__measured === true, null, { timeout: 180_000 });
  report = await page.evaluate(() => (window as any).__report);
  expect(failures, 'the harness page raised no errors').toEqual([]);
  await page.close();
});

test.describe('mountain relief along the terminator', () => {
  test('shows on terrain and nowhere else', () => {
    // The whole claim of shading by the DIFFERENCE from the sphere's normal: flat ground is
    // untouched. If the Bay of Bengal moves, the shader is shading by the terrain normal itself,
    // which would relight the planet and fight imagery that already carries its own relief.
    // Open water must not move by even one 8-bit level. It is a strict bar on purpose: the first
    // version cleared it only approximately, because the encoder's flat-ground byte decodes to
    // 0.0039 rather than 0 and that residue is a constant tilt over every ocean on earth.
    const { himalaya, ocean } = report.terminatorRidge;
    expect(himalaya.mean).toBeGreaterThan(2);
    expect(ocean.max).toBeLessThan(1);
    expect(himalaya.mean).toBeGreaterThan(ocean.mean * 50);
  });

  test('rakes a ridge harder than the plateau behind it', () => {
    // Tibet is high but flat; the Himalayan front is the same altitude with slopes on it. Relief
    // that responded to HEIGHT rather than to SLOPE would light both the same.
    expect(report.terminatorRidge.himalaya.mean)
      .toBeGreaterThan(report.terminatorRidge.tibet.mean);
  });

  test('is strongest at the terminator, not under a high sun', () => {
    // Nothing in the shader arranges this. At the subsolar point the sphere's normal already
    // points at the sun, so tilting it can only cost a second-order cosine; at the terminator the
    // cosine is near zero and a tilt moves it to first order. This is the measurement that says
    // the difference formulation is doing the work rather than a plain diffuse term.
    expect(report.sunOverhead.himalaya.mean)
      .toBeLessThan(report.terminatorRidge.himalaya.mean / 2);
  });

  test('survives magnification and retires only when it is a wash', () => {
    // Retuned against the magnification table below. The first curve retired relief by z7.5 on the
    // theory that a magnified normal map stops working; measurement says its amplitude does not
    // decay at all, so z7 is still carrying the full effect.
    const { z4, z7, z9, z11 } = report.zoomFade;
    expect(z4).toBeGreaterThan(2);
    expect(z7).toBeGreaterThan(z4 * 0.8);
    expect(z9).toBeLessThan(0.2);
    expect(z11).toBe(0);
  });
});

test.describe('what magnification costs the relief', () => {
  /**
   * The measurement the tiled-LOD system sizes its tile budget on, and the one that had to be
   * redone twice.
   *
   * First attempt measured against the MERCATOR scale — but this is a globe, which MapLibre draws
   * at exactly half that, so every magnification figure was out by a factor of two, a whole LOD
   * level. Second attempt measured mean absolute shading change, which is blind to blur: relief
   * came out immortal, 11.1 at one pixel per texel and 12.3 at sixteen, and very nearly went out
   * as a resolution answer.
   *
   * Amplitude genuinely does not decay — a magnified normal map shades the ground by exactly the
   * same amount, just smoothly. What magnification destroys is structure at the pixel scale, so
   * that is what is measured now.
   */
  test('costs crispness and not presence', () => {
    const byMagnification = new Map(report.magnification.samples.map((s) => [s.pixelsPerTexel, s]));
    const one = byMagnification.get(1);
    const sixteen = byMagnification.get(16);
    expect(one, 'a 1:1 sample').toBeDefined();
    expect(sixteen, 'a 16:1 sample').toBeDefined();

    // Amplitude flat within a fifth across a sixteen-fold magnification.
    expect(sixteen!.amplitude).toBeGreaterThan(one!.amplitude * 0.8);
    // Detail down by roughly the magnification factor: a clean 1/n trade, no cliff.
    expect(sixteen!.detail).toBeLessThan(one!.detail * 0.2);
  });

  test('halves its detail for every doubling, with no threshold to find', () => {
    // The shape matters more than any single number: a smooth 1/n decay means there is no
    // "correct" target pixels-per-texel, only a cost/quality dial for the tiling system to set.
    const samples = [...report.magnification.samples].sort((a, b) => a.pixelsPerTexel - b.pixelsPerTexel);
    for (let i = 1; i < samples.length; i++) {
      const ratio = samples[i].detail / samples[i - 1].detail;
      expect(ratio, `detail ratio at ${samples[i].pixelsPerTexel} px/texel`).toBeGreaterThan(0.4);
      expect(ratio).toBeLessThan(0.75);
    }
  });
});

test.describe('cloud shadows on the ground', () => {
  test('darken the ground under the deck', () => {
    expect(report.cloudShadow.strength).toBeGreaterThan(1);
  });

  test('fall away from the sun rather than sitting under the cloud like a decal', () => {
    // The offset lookup is the whole difference between a shadow and a sticker. With the sun in
    // the west the shadow is thrown east, so the deck that cast it sits WEST of its own shadow —
    // negative dx — and moving the sun to the other side has to swing it the other way.
    const west = report.cloudShadow;
    const east = report.cloudShadowSunInEast;
    expect(west.interior, 'the correlation found a real peak, not the edge of its search').toBe(true);
    expect(east.interior).toBe(true);
    expect(west.shiftEastPx).toBeLessThan(-5);
    expect(east.shiftEastPx).toBeGreaterThan(5);
  });

  test('are thrown the distance the geometry says, not an arbitrary nudge', () => {
    // A shader that displaced shadows by a constant would pass every direction test above. This is
    // the one that says the offset is the real thing: walk from the ground toward the sun until
    // the deck, and the horizontal part of that walk is how far the shadow moves. The measured
    // figure runs a little long because the deck is DRAWN at 90 km too, so it carries its own
    // parallax away from the sub-camera point — a dozen kilometres at this zoom.
    for (const shadow of [report.cloudShadow, report.cloudShadowSunInEast]) {
      expect(shadow.predictedOffsetKm).toBeGreaterThan(100);
      const error = Math.abs(shadow.measuredOffsetKm - shadow.predictedOffsetKm);
      expect(error / shadow.predictedOffsetKm).toBeLessThan(0.15);
    }
  });

  test('are grey at noon, not a private sunset', () => {
    /**
     * The twilight band has to be keyed to the SUN's angle, not to the light that actually lands.
     * Key it to the latter and a cloud shadow — which halves the light — sits exactly on the peak
     * of the warm band and paints itself orange in the middle of the afternoon.
     *
     * This is measured because it is invisible to every other measurement here: the shadow has the
     * right DARKNESS throughout, so luminance says nothing. Reintroducing the bug moves this number
     * from -4.4 to +41.3.
     */
    const { shadowedPixels, warmerThanGroundBy } = report.noonShadowColour;
    expect(shadowedPixels).toBeGreaterThan(1000);
    expect(warmerThanGroundBy).toBeLessThan(1);
  });
});

test.describe('the moon\'s shadow on 12 August 2026', () => {
  test('goes total where the ephemeris says it will', () => {
    expect(report.eclipse.predictedSunLeft).toBeLessThan(0.05);
    // 255 is the whole dynamic range of the grey the shell is drawn over.
    expect(report.eclipse.atUmbra.mean).toBeGreaterThan(100);
  });

  test('is a patch on the planet, not a dimmer on the hemisphere', () => {
    /**
     * The measurement that says the moon's PARALLAX survived into the shader. The moon is only
     * sixty earth radii away, so its direction genuinely differs across the disc, and that is the
     * entire reason totality is a narrow track. Feed the shader one global moon direction and it
     * still renders a perfectly convincing eclipse — covering half the planet.
     *
     * The penumbra legitimately covers most of the lit face; a real one is thousands of kilometres
     * across. It is totality that has to be small.
     */
    expect(report.eclipse.litPixels).toBeGreaterThan(100_000);
    expect(report.eclipse.penumbraShare).toBeGreaterThan(0.05);
    expect(report.eclipse.penumbraShare).toBeLessThan(0.9);
    expect(report.eclipse.totalityShare).toBeLessThan(0.15);
    expect(report.eclipse.totalityShare).toBeGreaterThan(0);
  });

  test('leaves the planet completely alone on a date with no eclipse', () => {
    // Not "small" — exactly zero. Eclipses are rare, and a globe that dims slightly on every
    // ordinary afternoon would be a permanent, invisible wrongness.
    expect(report.eclipse.onAnOrdinaryDay.max).toBe(0);
  });
});
