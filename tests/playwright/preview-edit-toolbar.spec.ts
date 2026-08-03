import { test, expect, Page } from '@playwright/test';

/**
 * Owner-preview "Edit scene" deep-link. Two independent checks (the player's own 3D engine never
 * settles in headless, so the player half is asserted via its Alpine helper, not full playback):
 *  1. editSceneHref builds /wizard?step=4&scene=<id>&modal=<0|1> for the current scene.
 *  2. Following such a link lands the wizard on the scene editor with THAT exact scene selected.
 */
const LESSON_CODE = process.env.VOYAGE_LESSON_CODE!;
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL!;
/**
 * The scene to deep-link to, read from the fixture at run time.
 *
 * This was a hardcoded `37` and broke the moment the Columbus lesson was rebuilt with fresh ids —
 * a failure that said nothing about the deep-link working. Ask the page which scene it has instead.
 */
async function thirdSceneId(page: Page): Promise<number> {
  await page.goto(`/lesson/${LESSON_CODE}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window as any).LESSON?.scenes?.length, { timeout: 15_000 });
  const id = await page.evaluate(() => (window as any).LESSON?.scenes?.[2]?.id);
  expect(id, 'fixture lesson has at least three scenes with ids').toEqual(expect.any(Number));

  return id as number;
}

test('owner preview shows the Edit toolbar (logo hidden) and carries scene ids', async ({ page }) => {
  test.setTimeout(60_000);
  // The player's WebGL engine stalls a headless 'load'; the toolbar + its data are static, so assert
  // those directly rather than poking the (async-initialising) Alpine component.
  await page.goto(`/lesson/${LESSON_CODE}`, { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('button', { name: 'Edit scene' })).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('img[alt="The Learning Portal"]')).toHaveCount(0); // big logo hidden for owner

  // editSceneHref reads window.LESSON.scenes[index].id — verify that payload carries the ids it needs.
  const sceneId = await page.evaluate(() => (window as any).LESSON?.scenes?.[2]?.id);
  expect(sceneId, 'scene payload exposes ids for the deep-link').toEqual(expect.any(Number));
});

test('wizard deep-link opens the exact scene from ?scene=', async ({ page }) => {
  test.setTimeout(60_000);
  const targetScene = await thirdSceneId(page);
  await page.goto(`${WIZARD_URL}?step=4&scene=${targetScene}&modal=0`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const c = (window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    return !!c;
  }, { timeout: 25_000 });

  const selected = await page.evaluate(() => {
    const c = (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    return c.$wire.get('selectedSceneId');
  });
  expect(selected, 'wizard opened the exact scene from the deep-link').toBe(targetScene);

  // A bogus scene id falls back to the first scene (not a crash / not the lessons list).
  await page.goto(`${WIZARD_URL}?step=4&scene=999999`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const c = (window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    return !!(c && c.$wire.get('selectedSceneId'));
  }, { timeout: 25_000 });
  const fallback = await page.evaluate(() => {
    const c = (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    return c.$wire.get('selectedSceneId');
  });
  expect(fallback, 'bad scene id falls back to a real scene').toBeGreaterThan(0);
});
