import { test, expect } from '@playwright/test';

/**
 * Landfall map pins are the gallery's own images (first 3) — so images added to the gallery show on
 * the map, not a separate "stop images" list.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';
const SCENE = 39; // Hispaniola / La Navidad — carries several gallery images

test('landfall pins mirror the gallery images', async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto(WIZARD_URL);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !!(window as any).Livewire?.all?.().find((x: any) => x.name === 'wizard.step3-scene-configurator'));
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), SCENE);
  // Wait for the polaroid pins to render (voyage module lazy-loads on first voyage scene).
  await page.waitForFunction(() =>
    [...document.querySelectorAll('#lesson-map-preview img')].some((i) => (i as HTMLElement).style.width === '96px'),
    { timeout: 30_000 });

  const result = await page.evaluate(() => {
    const c = (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator');
    const gal = c.$wire.get('selectedScene')?.config?.gallery?.images || [];
    const galUrls = gal.map((im: any) => (typeof im === 'string' ? im : im.url)).filter(Boolean);
    // Pins are the 96px polaroid <img> elements in the voyage HUD.
    const pins = [...document.querySelectorAll('#lesson-map-preview img')]
      .filter((i) => (i as HTMLElement).style.width === '96px')
      .map((i) => (i as HTMLImageElement).src);
    return { galUrls, pins };
  });

  expect(result.galUrls.length, 'scene has gallery images').toBeGreaterThan(0);
  const expectedPins = result.galUrls.slice(0, 3);
  expect(result.pins.length, 'pin count = first-3 of the gallery').toBe(expectedPins.length);
  // Every pin is one of the gallery images (order-independent to tolerate cloudinary URL forms).
  for (const src of result.pins) {
    expect(result.galUrls.some((u: string) => u === src), `pin ${src} is a gallery image`).toBe(true);
  }
});
