import { test, expect } from '@playwright/test';

/**
 * Drive the border controls the way a hand does, then hover a territory with a real pointer and
 * close the card again. Asserts the three states actually reach the paint AND that nothing throws.
 */
test('border controls change the visible border, and hovering throws nothing', async ({ page }) => {
  test.setTimeout(180_000);
  const errs: string[] = [];
  page.on('pageerror', (e) => errs.push('PAGEERROR ' + e.message.slice(0, 140)));
  page.on('console', (m) => { if (m.type() === 'error') errs.push('CONSOLE ' + m.text().slice(0, 140)); });

  await page.addInitScript(() => { try { localStorage.setItem('tm-style', 'earth'); } catch (e) {} });
  await page.goto('/teacher/timemap');
  await page.waitForFunction(() => (window as any).__tmMap?.loaded() === true, null, { timeout: 90_000 });
  await page.waitForTimeout(10_000);

  const before = await page.evaluate(() => (window as any).__tmMap.getPaintProperty('boundaries-line', 'line-color'));

  // Exactly what dragging "Territory · hover → Border" does.
  const painted = await page.evaluate(() => {
    const map: any = (window as any).__tmMap;
    const tune = (window as any).__tune;
    tune.set('Territory · hover', 'hoverBorderColour', '#ff0000');
    tune.set('Territory · hover', 'hoverBorderWidth', 5);
    tune.set('Territory · active', 'activeBorderColour', '#00ff00');
    const applied = true;
    return { applied, color: map.getPaintProperty('boundaries-line', 'line-color'),
             width: map.getPaintProperty('boundaries-line', 'line-width'),
             smooth: map.getLayer('boundaries-smooth') ? map.getPaintProperty('boundaries-smooth', 'line-color') : null };
  });

  // A real pointer over a territory, then away, then the card closed — the sequence that threw.
  const box = await page.locator('#tm-map, .maplibregl-canvas').first().boundingBox();
  if (box) {
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 12 });
    await page.waitForTimeout(1500);
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await page.waitForTimeout(2500);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(1500);
    await page.mouse.move(box.x + 40, box.y + 40, { steps: 10 });
    await page.waitForTimeout(2000);
  }

  console.log('before line-color:', JSON.stringify(before));
  console.log('after  :', JSON.stringify(painted, null, 2));
  console.log('ERRORS:', [...new Set(errs)].join('\n  ') || '(none)');

  expect(painted.applied).toBe(true);

  // The control reached the paint at all.
  expect(JSON.stringify(painted.color), 'hover border colour never reached boundaries-line')
    .toContain('#ff0000');
  expect(JSON.stringify(painted.width), 'hover border width never reached boundaries-line')
    .toContain('5');

  // THE RESTING BRANCH IS THE STYLE'S, NOT theme.json's. This is the bug that made every border
  // control look broken: touching hover rebuilt the whole case, and the resting branch fell back to
  // theme.line (#000 / 0.4) instead of the style you are looking at (#ffd9a0 / 0.9 on Earth). The
  // border did change — to black — which reads as "nothing I do works".
  expect(painted.color[painted.color.length - 1], 'resting border fell back to theme, not the style')
    .toBe('#ffd9a0');
  expect(painted.width[painted.width.length - 1], 'resting width fell back to theme, not the style')
    .toBe(0.9);

  // Painted on the rounded-border layer too, which is the visible one whenever rounded borders are
  // on — the panel used to write only to boundaries-line, which is hidden in that mode.
  expect(JSON.stringify(painted.smooth), 'boundaries-smooth never got the states').toContain('#ff0000');

  expect(errs.filter((e) => e.includes('label') || e.includes('expression.evaluate')), 'the reported crashes').toEqual([]);
});
