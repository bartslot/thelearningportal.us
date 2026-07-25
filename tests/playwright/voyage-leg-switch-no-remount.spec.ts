import { test, expect } from '@playwright/test';

/**
 * Switching between legs of the SAME voyage must NOT rebuild the map — the tour plays the new leg on
 * the existing instance, preserving zoom / centre / globe-flat projection and the loaded tiles.
 */
const WIZARD_URL = process.env.VOYAGE_WIZARD_URL || '/teacher/lessons/3/wizard';

test('switching voyage legs plays live on the same map (no re-mount)', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 160)); });

  await page.goto(WIZARD_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!(window as any).renderVoyageTour && !!(window as any).Livewire);

  // Pick the first two VOYAGE legs by their actual kind (from the bootstrap scene JSON) — the
  // lesson also has non-voyage intro scenes (a quiz + a gallery), so a positional index is wrong.
  const { intro, legA, legB } = await page.evaluate(() => {
    const scenes = JSON.parse(document.getElementById('step3-scenes-data')?.textContent || '[]');
    const voyages = scenes.filter((s: any) => s.kind === 'voyage').map((s: any) => s.id);
    const nonVoyage = scenes.find((s: any) => s.kind !== 'voyage')?.id;
    return { intro: nonVoyage, legA: voyages[0], legB: voyages[1] };
  });

  // The wizard may persist a VOYAGE scene as selected, so the map can mount during hydration —
  // before we wrap renderVoyageTour. Land on the non-voyage intro first to tear any such map down,
  // so the counter below only sees the mount we trigger.
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), intro);
  await page.waitForTimeout(1500);

  // Wrap via a getter/setter: the lazy voyage-tour chunk re-assigns window.renderVoyageTour on load,
  // which clobbers a plain assignment — the accessor keeps our counter wrapping whatever it sets.
  await page.evaluate(() => {
    const w = window as any;
    w.__mounts = 0;
    let real = w.renderVoyageTour;
    const wrap = (fn: any) => function (this: any, ...a: any[]) { w.__mounts++; const i = fn.apply(this, a); w.__cap = i; return i; };
    let wrapped = wrap(real);
    Object.defineProperty(w, 'renderVoyageTour', {
      configurable: true,
      get: () => wrapped,
      set: (fn) => { real = fn; wrapped = wrap(fn); },
    });
  });

  // Land on the first leg (this mount is expected).
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), legA);
  await page.waitForTimeout(2500);
  const mountsAfterFirst = await page.evaluate(() => (window as any).__mounts);
  expect(mountsAfterFirst, 'first voyage leg mounted once').toBeGreaterThanOrEqual(1);
  // Tag the current map object — a rebuild would create a fresh (untagged) one.
  await page.evaluate(() => { (window as any).__cap.map.__legTag = 'kept'; });

  // Switch to the adjacent leg — must NOT re-mount (keeps the loaded map/tiles; each leg still
  // applies its OWN view, so projection may legitimately differ between legs).
  await page.evaluate((id) => (window as any).Livewire.all().find((x: any) => x.name === 'wizard.step3-scene-configurator').$wire.selectScene(id), legB);
  await page.waitForTimeout(2500);

  expect(await page.evaluate(() => (window as any).__mounts), 'leg switch did not rebuild the map').toBe(mountsAfterFirst);
  expect(await page.evaluate(() => (window as any).__cap?.map?.__legTag), 'it is the SAME map object, not a rebuilt one').toBe('kept');

  expect(errors, errors.join('\n')).toHaveLength(0);
});
