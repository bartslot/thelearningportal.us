import { expect, test } from '@playwright/test';

/**
 * On the landing page the dot IS the cursor. Two things break that illusion and both had:
 * the system pointer reappearing over buttons and links, and a dot dim enough to read as grey
 * rather than as a light source.
 */

test('no system cursor anywhere on the landing page, including over buttons', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const cursors = await page.evaluate(() => {
    const shell = document.querySelector('.landing-shell');
    if (!shell) return { shell: null as string | null, interactive: [] as string[] };
    const nodes = [...shell.querySelectorAll('a, button, [role="button"], label, input')].slice(0, 25);
    return {
      shell: getComputedStyle(shell).cursor,
      interactive: [...new Set(nodes.map((n) => getComputedStyle(n).cursor))],
    };
  });

  expect(cursors.shell).toBe('none');
  // The failure this guards: one `cursor: pointer` survivor puts the hand back exactly where the
  // dot matters most.
  expect(cursors.interactive.filter((c) => c !== 'none')).toEqual([]);
});

test('the dot is fully white and carries a glow', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const dot = await page.evaluate(() => {
    const el = document.querySelector('.landing-cursor');
    if (!el) return null;
    const s = getComputedStyle(el);
    return {
      opacity: s.opacity,
      width: Math.round(el.getBoundingClientRect().width),
      background: s.backgroundColor,
      hasGlow: s.boxShadow !== 'none' && s.boxShadow.length > 0,
      animated: s.animationName !== 'none',
    };
  });

  expect(dot).not.toBeNull();
  expect(dot!.opacity).toBe('1');
  expect(dot!.width).toBeGreaterThanOrEqual(20);
  expect(dot!.background).toBe('rgb(255, 255, 255)');
  expect(dot!.hasGlow).toBe(true);
  expect(dot!.animated).toBe(true);
});

test('the glow holds still under reduced motion', async ({ browser }) => {
  // A pulsing light at the centre of vision is the worst case for motion sensitivity.
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();
  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const s = await page.evaluate(() => {
    const el = document.querySelector('.landing-cursor');
    const cs = el ? getComputedStyle(el) : null;
    return cs ? { animation: cs.animationName, glow: cs.boxShadow !== 'none' } : null;
  });

  expect(s!.animation).toBe('none');
  expect(s!.glow).toBe(true);   // still lit, just not breathing
  await context.close();
});
