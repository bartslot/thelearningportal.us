import { test } from '@playwright/test';

const HARNESS = process.env.HARNESS_URL ?? 'http://localhost:5198/';

test.skip(!process.env.WHERE, 'set WHERE=x,y');

test('what latitude is this pixel', async ({ browser }) => {
  test.setTimeout(300_000);
  const page = await browser.newPage({ viewport: { width: 1000, height: 800 } });
  await page.goto(`${HARNESS}?imagery=1`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => (window as any).__measured === true, null, { timeout: 240_000 });
  const [x, y] = process.env.WHERE!.split(',').map(Number);
  const out = await page.evaluate(({ x, y }) => {
    const w = window as any;
    w.__map.jumpTo({ center: [-25, 20], zoom: 1.6, bearing: 0, pitch: 0 });
    const at = (px: number, py: number) => {
      const p = w.__map.unproject([px, py]);
      return { lng: Math.round(p.lng * 10) / 10, lat: Math.round(p.lat * 10) / 10 };
    };
    return { centre: at(x, y), up: at(x, y - 30), down: at(x, y + 30), left: at(x - 40, y) };
  }, { x, y });
  console.log(JSON.stringify(out));
  await page.close();
});
