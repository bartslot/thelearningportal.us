import { test, expect, Page } from '@playwright/test';

/**
 * Explorer voyages on the Time-Map (resources/js/timemap/voyages.{js,json}): era-filtered arrowed
 * sea paths; clicking one opens the info panel via the voyage's Wikidata QID.
 *
 * Uses window.__tmMap (exposed by initTimeMap) for layer/feature introspection.
 */
async function loginAsTeacher(page: Page): Promise<void> {
  await page.goto('/login');
  if (!page.url().includes('/login')) return; // APP_AUTO_LOGIN dev shortcut already signed us in
  await page.fill('#email', 'teacher@playwright.test');
  await page.fill('#password', 'playwright123');
  await page.click('button[type=submit]');
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 10_000 });
}

test.beforeEach(async ({ page }) => {
  await loginAsTeacher(page);
  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 30_000 });
});

test('voyages: da Gama route renders at 1497 and opens the info panel', async ({ page }) => {
  // Drive the real year input so the era filters re-apply exactly as they do for a teacher.
  await page.fill('input[type="number"]', '1497');
  await page.dispatchEvent('input[type="number"]', 'change');
  // The default view centres on Europe; the route rounds Africa — bring it into the viewport
  // (queryRenderedFeatures only sees rendered tiles).
  await page.evaluate(() => (window as any).__tmMap.jumpTo({ center: [15, 0], zoom: 2 }));
  await page.waitForTimeout(1500);

  const probe = await page.evaluate(() => {
    const m = (window as any).__tmMap;
    return {
      layers: ['voyage-hit', 'voyage-line', 'voyage-arrows', 'voyage-label'].map((id) => !!m.getLayer(id)),
      rendered: m.queryRenderedFeatures(undefined, { layers: ['voyage-line'] }).map((f: any) => f.properties.name),
    };
  });
  expect(probe.layers).toEqual([true, true, true, true]);
  expect(probe.rendered).toContain('Vasco da Gama');
  expect(probe.rendered).not.toContain('James Cook'); // 1768-1771 window, hidden in 1497

  // Click the route at the Malindi waypoint — mid-viewport, clear of the timeline overlay that
  // covers the bottom of the screen (the spline passes exactly through waypoints). project()
  // returns MAP-CONTAINER pixels; the mouse needs viewport pixels, so add the container offset.
  const pt = await page.evaluate(() => {
    const p = (window as any).__tmMap.project([40.1, -3.2]);
    const rect = (window as any).__tmMap.getContainer().getBoundingClientRect();
    return { x: rect.left + p.x, y: rect.top + p.y };
  });
  await page.mouse.click(pt.x, pt.y);

  await page.waitForFunction(() => (window as any).__portal?.selectedRegion === 'Q951383', { timeout: 5_000 });
  await expect(page.locator('aside h2')).toContainText('Vasco da Gama');
});

test('voyages: hidden outside their visibility window', async ({ page }) => {
  await page.fill('input[type="number"]', '1300');
  await page.dispatchEvent('input[type="number"]', 'change');
  await page.waitForTimeout(1200);

  const rendered = await page.evaluate(() =>
    (window as any).__tmMap.queryRenderedFeatures(undefined, { layers: ['voyage-line'] }).length);
  expect(rendered).toBe(0);
});
