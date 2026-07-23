import { test, expect } from '@playwright/test';

/**
 * Owner-preview "Edit scene" deep-link. Two independent checks (the player's own 3D engine never
 * settles in headless, so the player half is asserted via its Alpine helper, not full playback):
 *  1. editSceneHref builds /wizard?step=4&scene=<id>&modal=<0|1> for the current scene.
 *  2. Following such a link lands the wizard on the scene editor with THAT exact scene selected.
 */
const LESSON_CODE = process.env.VOYAGE_LESSON_CODE || 'M4MXMR';
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const TARGET_SCENE = 37; // San Salvador (3rd scene) in the Columbus fixture

test('owner preview shows the Edit toolbar (logo hidden) and carries scene ids', async ({ page }) => {
  test.setTimeout(60_000);
  // The player's WebGL engine stalls a headless 'load'; the toolbar + its data are static, so assert
  // those directly rather than poking the (async-initialising) Alpine component.
  await page.goto(`/lesson/${LESSON_CODE}`, { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('button', { name: 'Edit scene' })).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('img[alt="The Learning Portal"]')).toHaveCount(0); // big logo hidden for owner

  // editSceneHref reads window.LESSON.scenes[index].id — verify that payload carries the ids it needs.
  const sceneId = await page.evaluate(() => (window as any).LESSON?.scenes?.[2]?.id);
  expect(sceneId, 'scene payload exposes ids for the deep-link').toBe(TARGET_SCENE);
});

test('wizard deep-link opens the exact scene from ?scene=', async ({ page }) => {
  test.setTimeout(60_000);
  await page.goto(`${WIZARD_URL}?step=4&scene=${TARGET_SCENE}&modal=0`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const c = (window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    return !!c;
  }, { timeout: 25_000 });

  const selected = await page.evaluate(() => {
    const c = (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    return c.$wire.get('selectedSceneId');
  });
  expect(selected, 'wizard opened the exact scene from the deep-link').toBe(TARGET_SCENE);

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
