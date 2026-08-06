import { test, expect, Page } from '@playwright/test';

/**
 * A map block tours its focus cities.
 *
 * The Silk Road map scene names four cities in order — "on the left, Venice … then Constantinople
 * … then Samarkand … and on the far right, Chang'an" — and the map used to answer that by putting
 * four identical pins on a continent-wide view and parking the camera there for the whole scene.
 * A class had to hunt for whichever city the narrator was describing.
 *
 * A voyage has always done this properly: numbered stops, visited in order. This asserts that a
 * plain map block now does the same (map-itinerary.js), and — the part that is easy to get wrong —
 * that it does NOT draw a route line between them. Marco Polo did not sail from Venice to Chang'an
 * in a straight line, and a line would teach a class that he did.
 */

/** A published lesson whose map scene pins two or more focus cities, found the way the fixtures do. */
async function findMapLesson(page: Page): Promise<{ code: string; sceneIndex: number; stops: number } | null> {
  const res = await page.request.get('/api/v1/catalog/manifest');
  if (!res.ok()) return null;
  const lessons: Array<{ code: string; status: string }> = (await res.json()).lessons ?? [];

  for (const l of lessons.filter((x) => x.status === 'published')) {
    const html = await (await page.request.get(`/lesson/${l.code}`)).text();
    const start = html.indexOf('"scenes":[');
    if (start === -1) continue;
    let depth = 0;
    let end = -1;
    for (let i = html.indexOf('[', start); i < html.length; i++) {
      if (html[i] === '[') depth++;
      else if (html[i] === ']' && --depth === 0) { end = i + 1; break; }
    }
    if (end === -1) continue;

    let scenes: Array<{ kind: string; config?: { annotations?: Array<{ type: string }> } }>;
    try { scenes = JSON.parse(html.slice(html.indexOf('[', start), end)); } catch { continue; }

    const idx = scenes.findIndex((s) =>
      s.kind === 'map' && (s.config?.annotations ?? []).filter((a) => a?.type === 'focus').length >= 2);
    if (idx !== -1) {
      const stops = (scenes[idx].config?.annotations ?? []).filter((a) => a?.type === 'focus').length;
      return { code: l.code, sceneIndex: idx, stops };
    }
  }
  return null;
}

/** Start the lesson muted and jump straight to its map scene. */
async function openMapScene(page: Page, code: string, sceneIndex: number) {
  await page.goto(`/lesson/${code}`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    const patch = () => document.querySelectorAll('audio,video').forEach((m) => {
      (m as HTMLMediaElement).muted = true; (m as HTMLMediaElement).volume = 0;
    });
    patch();
    setInterval(patch, 200);
  });
  await page.waitForTimeout(1200);

  await page.evaluate(async (idx) => {
    const el = document.querySelector('[x-data]') as any;
    const c = el._x_dataStack[0];
    await c.startLesson();
    await new Promise((r) => setTimeout(r, 400));
    c.goToChapter(idx);
  }, sceneIndex);

  await page.waitForFunction(
    () => getComputedStyle(document.getElementById('lesson-map-stage')!).display === 'block',
    { timeout: 20_000 },
  );
}

test('a map block visits its focus cities in order, numbered, with no route drawn between them', async ({ page }) => {
  const fixture = await findMapLesson(page);
  test.skip(!fixture, 'no published lesson has a map scene with two or more focus cities');

  await openMapScene(page, fixture!.code, fixture!.sceneIndex);

  // Every city gets a pin, numbered from 1 in the order the teacher pinned them.
  const host = page.locator('.lp-map-itinerary');
  await expect(host).toBeAttached({ timeout: 20_000 });
  const numbers = await page.locator('.lp-map-itinerary [data-number]').allTextContents();
  expect(numbers).toEqual(Array.from({ length: fixture!.stops }, (_, i) => String(i + 1)));

  // They arrive one at a time. The first is revealed after the opening overview; the last is not
  // yet. This is the whole point — an itinerary that appears all at once is just four pins again.
  await page.waitForFunction(
    () => (document.querySelector('.lp-map-itinerary')!.children[0] as HTMLElement).style.opacity === '1',
    { timeout: 20_000 },
  );
  const early = await page.evaluate(() =>
    [...document.querySelector('.lp-map-itinerary')!.children].map((p) => (p as HTMLElement).style.opacity));
  expect(early[0]).toBe('1');
  expect(early[early.length - 1]).toBe('0');

  // The camera actually moves, and it ends up somewhere different from where it opened.
  const opening = await page.evaluate(() => {
    const m = (window as any).__lessonMap;
    return m ? { lng: m.getCenter().lng, zoom: m.getZoom() } : null;
  });

  // Wait for the last stop to be revealed, i.e. the tour ran to the end.
  await page.waitForFunction(
    () => {
      const kids = [...document.querySelector('.lp-map-itinerary')!.children] as HTMLElement[];
      return kids[kids.length - 1].style.opacity === '1';
    },
    { timeout: 40_000 },
  );

  if (opening) {
    const arrived = await page.evaluate(() => {
      const m = (window as any).__lessonMap;
      return { lng: m.getCenter().lng, zoom: m.getZoom() };
    });
    expect(Math.abs(arrived.lng - opening.lng) + Math.abs(arrived.zoom - opening.zoom)).toBeGreaterThan(1);
  }

  // No route line. A voyage draws one; a map block of focus cities must not.
  const routeLayers = await page.evaluate(() => {
    const m = (window as any).__lessonMap;
    if (!m) return [];
    return m.getStyle().layers
      .filter((l: any) => /trail|route|voyage/i.test(l.id))
      .map((l: any) => l.id);
  });
  expect(routeLayers).toEqual([]);
});

test('pausing the lesson stops the tour where it stands', async ({ page }) => {
  const fixture = await findMapLesson(page);
  test.skip(!fixture, 'no published lesson has a map scene with two or more focus cities');

  await openMapScene(page, fixture!.code, fixture!.sceneIndex);
  await expect(page.locator('.lp-map-itinerary')).toBeAttached({ timeout: 20_000 });

  // Pause as soon as the first city is reached.
  await page.waitForFunction(
    () => (document.querySelector('.lp-map-itinerary')!.children[0] as HTMLElement).style.opacity === '1',
    { timeout: 20_000 },
  );
  const revealedAtPause = await page.evaluate(() => {
    const el = document.querySelector('[x-data]') as any;
    el._x_dataStack[0].setPlaybackPaused(true);
    return [...document.querySelector('.lp-map-itinerary')!.children]
      .filter((p) => (p as HTMLElement).style.opacity === '1').length;
  });

  // Long enough that an unpaused tour would have moved on to the next city.
  await page.waitForTimeout(6000);

  const revealedAfterWaiting = await page.evaluate(() =>
    [...document.querySelector('.lp-map-itinerary')!.children]
      .filter((p) => (p as HTMLElement).style.opacity === '1').length);
  expect(revealedAfterWaiting).toBe(revealedAtPause);

  // Resuming picks the tour back up rather than abandoning it.
  await page.evaluate(() => {
    const el = document.querySelector('[x-data]') as any;
    el._x_dataStack[0].setPlaybackPaused(false);
  });
  await page.waitForFunction(
    (was) => [...document.querySelector('.lp-map-itinerary')!.children]
      .filter((p) => (p as HTMLElement).style.opacity === '1').length > was,
    revealedAfterWaiting,
    { timeout: 30_000 },
  );
});
