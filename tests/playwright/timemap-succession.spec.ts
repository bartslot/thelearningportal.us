import { test, expect } from '@playwright/test';

/**
 * A COUNTRY OUTLIVES ITS GOVERNMENTS.
 *
 * Open a territory, move the year past the point where its polity is replaced, and the card must
 * still be showing that place — under the successor's name, having said so.
 *
 * Driven through the real click handler and the real year setter, because the bug was precisely
 * that those two disagreed: the click stored a polity id, and the year change invalidated it.
 */
test('the selection survives the polity being replaced', async ({ page }) => {
  test.setTimeout(200_000);
  await page.setViewportSize({ width: 1280, height: 860 });
  const errs: string[] = [];
  page.on('pageerror', (e) => errs.push(e.message.slice(0, 160)));

  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });
  await page.waitForTimeout(10_000);

  // Italy: Kingdom until 1946, Republic after. Framed so the peninsula is well clear of the chrome.
  await page.evaluate(() => {
    (window as any).__setTimemapYear(1930);
    (window as any).__tmMap.jumpTo({ center: [12.5, 42.5], zoom: 4.6 });
  });
  await page.waitForTimeout(6_000);

  const box = (await page.locator('.maplibregl-canvas').first().boundingBox())!;
  const spot = await page.evaluate(() => {
    const map: any = (window as any).__tmMap;
    const c = map.getCanvas();
    // Spiral out from the CENTRE, which is where the camera was pointed. Scanning from a corner
    // found Britain and quietly tested a polity that never changes — a green test proving nothing.
    const order = [0, 1, -1, 2, -2];
    for (const dy of order) {
      for (const dx of order) {
        const x = c.clientWidth / 2 + dx * (c.clientWidth / 14);
        const y = c.clientHeight / 2 + dy * (c.clientHeight / 14);
        const f = map.queryRenderedFeatures([x, y], { layers: ['boundaries-fill'] })[0];
        if (!f) continue;
        if (document.elementFromPoint(x, y)?.tagName !== 'CANVAS') continue;
        return { x, y, name: f.properties?.Name };
      }
    }
    return null;
  });
  expect(spot, 'nothing clickable on screen').toBeTruthy();
  console.log('clicked:', spot!.name);

  await page.mouse.click(box.x + spot!.x, box.y + spot!.y);
  await page.waitForTimeout(2_500);

  const opened = await page.evaluate(() => (window as any).__portal?.selectedRegion ?? null);
  expect(opened, 'the click did not select anything').toBeTruthy();

  // Now run the years forward past every succession this place goes through.
  const cardTitle = () => page.evaluate(() => document.querySelector('[data-card-title]')?.textContent?.trim()
    ?? document.querySelector('aside h2, aside h3')?.textContent?.trim() ?? null);

  // Seed with the name it opened under. Collecting only AFTER the year moves means the set holds
  // one name however many successions happened, and the assertion below can never fail.
  const seen: string[] = [];
  const labels = new Set<string>();
  const first = await cardTitle();
  if (first) labels.add(first);
  seen.push(`1930: ${first ?? '—'} (${opened})`);
  for (const year of [1946, 1950, 1960, 1990, 2010]) {
    await page.evaluate((y) => (window as any).__setTimemapYear(y), year);
    await page.waitForTimeout(2_600);
    const label = await cardTitle();
    const sel = await page.evaluate(() => (window as any).__portal?.selectedRegion ?? null);
    seen.push(`${year}: ${label ?? '—'} (${sel ?? 'none'})`);
    if (label) labels.add(label);
    // THE POINT OF THE WHOLE FEATURE: something is still selected at every year.
    expect(sel, `the selection was dropped at ${year}`).toBeTruthy();
  }
  console.log(seen.join('\n  '));

  // THE ABSOLUTE, not just the invariant: the place must actually have changed hands. Asserting
  // only that "something stayed selected" passes on a polity that never succeeds anything, which
  // is exactly how this test first went green while proving nothing.
  expect(labels.size, `the card never changed name across 1930-2010: ${[...labels].join(' / ')}`)
    .toBeGreaterThan(1);

  // And the card is still on screen rather than blanked.
  await expect(page.locator('aside').filter({ hasText: /Create Lesson|Maak les/ }).first()).toBeVisible();
  expect(errs, `page errors: ${errs.join(' | ')}`).toEqual([]);
});
