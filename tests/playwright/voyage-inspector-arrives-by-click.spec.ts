import { test, expect, Page } from '@playwright/test';

/**
 * Arriving at a voyage scene the way a teacher does: by clicking it in the rail.
 *
 * Every other voyage spec deep-links straight to a voyage scene (see the VOYAGE_WIZARD_URL the
 * global setup builds — it appends `&scene=<first voyage scene>`), so the voyage inspector is in
 * the server-rendered HTML on first paint. A teacher never arrives that way. They open the editor,
 * land on scene 1, and click along the rail — and then the inspector is inserted by a Livewire
 * morph instead, which is a different thing entirely: markup a morph brings in is inert, so an
 * inspector that needs a page-global to have been defined by an inline <script> gets nothing.
 *
 * The failure that named this: the itinerary's `x-data` called a function declared in a <script>
 * beside it. On first paint the script ran and everything worked; arriving by click it did not, so
 * Alpine threw twice per scene switch, the stop list never became draggable, and the editor could
 * be left unable to send another Livewire request at all — the waypoint counter stopped counting
 * and the address bar stayed on whichever scene and step it was last on.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;

/** The same lesson, opened the way a teacher opens it: no scene deep-link. */
const lessonUrl = (): string => WIZARD_URL.replace(/[?&]scene=\d+/, '').replace(/[?&]modal=\d+/, '');

/** Rail thumbs, in order, with enough about each to tell a voyage scene from an ordinary one. */
async function railThumbs(page: Page): Promise<Array<{ id: string; voyage: boolean }>> {
  return page.locator('[data-scene-id]:visible').evaluateAll((els) =>
    els.map((el) => ({
      id: (el as HTMLElement).dataset.sceneId!,
      // The voyage thumbs are the only ones that say Route or Overview on their face.
      voyage: /\b(Route|Overview)\b/.test(el.textContent ?? ''),
    })),
  );
}

const waypointBadge = (page: Page) => page.locator('text=/Waypoint\\s+\\d+\\s*\\/\\s*\\d+/').first();

test('a voyage scene reached by clicking the rail has a working inspector', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 200)));
  page.on('console', (m) => {
    if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 200));
  });

  await page.goto(lessonUrl());
  await page.waitForLoadState('networkidle').catch(() => {});

  const thumbs = await railThumbs(page);
  const ordinary = thumbs.find((t) => !t.voyage);
  const voyages = thumbs.filter((t) => t.voyage);
  test.skip(!ordinary || voyages.length < 2, 'needs one ordinary scene and two voyage scenes in the rail');

  // Start somewhere that is NOT a voyage, so the voyage inspector genuinely has to be brought in
  // by a morph. Landing on one would render it server-side and prove nothing.
  await page.locator(`[data-scene-id="${ordinary!.id}"]`).click();
  await expect(waypointBadge(page)).toHaveCount(0);
  errors.length = 0;

  // Now walk the voyage scenes. The counter has to follow, the URL has to follow, and the stop
  // list has to be draggable — the last of which is what actually needs Alpine to have survived.
  const seen: string[] = [];
  for (const v of voyages.slice(0, 3)) {
    await page.locator(`[data-scene-id="${v.id}"]`).click();
    await expect(waypointBadge(page)).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => new URL(page.url()).searchParams.get('scene'), { timeout: 15_000 }).toBe(v.id);
    seen.push((await waypointBadge(page).textContent())!.trim());
  }

  // A different scene reads as a different waypoint: the counter is live, not a leftover.
  expect(new Set(seen).size).toBeGreaterThan(1);
  expect(errors, 'switching to a voyage scene logged errors').toEqual([]);

  await page.getByRole('tab', { name: 'Route' }).click();
  const stops = page.locator('[data-stop-leg]');
  await expect(stops.first()).toBeVisible({ timeout: 15_000 });

  // Sortable.get() is SortableJS's own answer to "are you managing this list?". Without a mounted
  // instance the rows still render and still look right — they just cannot be dragged, which is
  // the half of this failure no screenshot can see.
  const draggable = await page.evaluate(() => {
    const list = document.querySelector('[data-stop-list]');
    return !!(list && (window as unknown as { Sortable?: { get(el: Element): unknown } }).Sortable?.get(list));
  });
  expect(draggable, 'the itinerary never became draggable').toBe(true);

  expect(errors, 'the voyage inspector logged errors when reached by clicking').toEqual([]);
});
