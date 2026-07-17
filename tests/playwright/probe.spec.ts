import { test } from '@playwright/test';
test('drag from text-center vs padding-edge', async ({ page }) => {
  await page.goto('/teacher/lessons/2/wizard?step=4');
  await page.waitForFunction(() => {
    const l = (window as any).__lessonTextLayer;
    return l && l._texts.length > 0;
  }, null, { timeout: 90_000 });
  await page.waitForTimeout(2000);

  const info = await page.evaluate(() => {
    const t = (window as any).__lessonTextLayer._texts[0];
    const n = document.querySelector(`[data-text-id="${t.id}"]`)!.getBoundingClientRect();
    return { id: t.id, x: t.x, y: t.y, rect: { l: n.left, t: n.top, w: n.width, h: n.height } };
  });

  // Attempt 1: grab the CENTER (on the text glyphs)
  await page.mouse.move(info.rect.l + info.rect.w / 2, info.rect.t + info.rect.h / 2);
  await page.mouse.down();
  await page.mouse.move(info.rect.l + info.rect.w / 2 + 120, info.rect.t + info.rect.h / 2 + 60, { steps: 10 });
  await page.mouse.up();
  await page.waitForTimeout(800);
  const afterCenter = await page.evaluate(() => {
    const t = (window as any).__lessonTextLayer._texts[0];
    return { x: t.x, y: t.y, selection: String(document.getSelection()).slice(0, 30) };
  });
  console.log('CENTER-DRAG', JSON.stringify({ before: { x: info.x, y: info.y }, afterCenter }));

  // Attempt 2: grab the top-left PADDING corner (off the glyphs)
  const i2 = await page.evaluate(() => {
    const t = (window as any).__lessonTextLayer._texts[0];
    const n = document.querySelector(`[data-text-id="${t.id}"]`)!.getBoundingClientRect();
    return { x: t.x, y: t.y, rect: { l: n.left, t: n.top } };
  });
  await page.mouse.move(i2.rect.l + 4, i2.rect.t + 4);
  await page.mouse.down();
  await page.mouse.move(i2.rect.l + 4 + 120, i2.rect.t + 4 + 60, { steps: 10 });
  await page.mouse.up();
  await page.waitForTimeout(800);
  const afterEdge = await page.evaluate(() => {
    const t = (window as any).__lessonTextLayer._texts[0];
    return { x: t.x, y: t.y };
  });
  console.log('EDGE-DRAG', JSON.stringify({ before: { x: i2.x, y: i2.y }, afterEdge }));
});
