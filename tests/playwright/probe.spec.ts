import { test } from '@playwright/test';
test('step5 play starts the sequencer', async ({ page }) => {
  const logs: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') logs.push(`${m.type()}: ${m.text().slice(0, 140)}`); });
  page.on('pageerror', (e) => logs.push(`PAGEERROR: ${String(e).slice(0, 200)}`));
  await page.goto('/teacher/lessons/4/wizard?step=5');
  await page.waitForFunction(() => !!(document.getElementById('lesson-canvas') as any)?.__lessonBridge, null, { timeout: 90_000 });
  await page.waitForTimeout(3000);
  // the amber circular play button
  const play = page.locator('button.btn-circle').first();
  await play.click();
  await page.waitForTimeout(8000);
  const state = await page.evaluate(() => {
    const audios = [...document.querySelectorAll('audio')].map(a => ({ playing: !a.paused, t: Math.round(a.currentTime * 10) / 10 }));
    const label = [...document.querySelectorAll('span,div')].map(e => e.textContent?.trim()).find(t => /^\d+:\d\d\s*\/\s*\d+:\d\d$/.test(t || ''));
    return { audios: audios.slice(0, 4), label };
  });
  console.log('PLAYSTATE', JSON.stringify(state));
  console.log('LOGS', JSON.stringify(logs.slice(0, 10)));
});
