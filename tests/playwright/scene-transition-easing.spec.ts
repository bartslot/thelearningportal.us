import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * The Animate tab's scene TRANSITION — Type, Duration and Easing — end to end.
 *
 * A teacher opens a scene, switches the inspector to Animate, and sets how this scene replaces
 * the one before it. Three controls, three separate questions, and this spec asks each of them
 * of the STUDENT player rather than of the panel:
 *
 *   1. does the choice survive the save (config.transition on the scene), and
 *   2. does each of the three reach the layer the student actually looks at.
 *
 * Everything is done through the real UI: the teacher clicks the easing tile, the student
 * presses Next. Nothing is written to the database by hand, so a control that only looks wired
 * up in the panel is caught here.
 *
 * HARNESS NOTES
 *  - Run WITHOUT SwiftShader (playwright.scene-easing.config.ts). The declared timing function
 *    survives SwiftShader fine, but the opacity SAMPLING in the last test needs real compositing,
 *    and mixing the two would leave a doubt about which lane produced a value.
 *  - The lesson is a throwaway copy (`lessons:test-copy`), because this spec WRITES: it changes
 *    a scene's transition settings. Never point it at a teacher's own lesson.
 *  - The wizard runs in whatever language the auto-login teacher has (Dutch here), so tabs are
 *    matched in both. The easing tile labels come from resources/js/scene/animations.js and are
 *    English in every locale.
 */

const PARENT = process.env.EASING_PARENT_LESSON ?? 'CF0AVG';
const SHOT = 'tests/playwright/results-scene-easing';

/** resources/js/easing.js, as Chrome serialises the curves. */
const EASE = {
  enter: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
  exit: 'cubic-bezier(0.55, 0.055, 0.675, 0.19)',
  move: 'cubic-bezier(0.645, 0.045, 0.355, 1)',
  pop: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
};
/** Easing tile label → the key stored on the scene → the curve the player must produce. */
const TILE = { Settle: 'enter', Smooth: 'move', Accelerate: 'exit', Overshoot: 'pop' } as const;

type Fixture = { id: number; code: string };
type Scene = { id: number; order: number; kind: string; chapter_name: string | null; image_url: string | null; config: any };

let lesson: Fixture;
let target: Scene;      // the scene whose transition the teacher edits
let previous: Scene;    // the scene the student is on when it arrives

test.beforeAll(async ({ browser }) => {
  const out = execFileSync('php', ['artisan', 'lessons:test-copy', PARENT, '--json'], { encoding: 'utf8' });
  lesson = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!);

  // Two CONSECUTIVE narration scenes that both carry a background image. A transition needs two
  // pictures to happen between; a picture replacing a flat colour is a different code path.
  const page = await browser.newPage();
  await page.goto(`/lesson/${lesson.code}`);
  const scenes: Scene[] = await page.evaluate(() => (window as any).LESSON.scenes);
  await page.close();

  const usable = (s: Scene) => s.kind === 'narration' && !!s.image_url && !!s.chapter_name;
  const i = scenes.findIndex((s, n) => n > 0 && usable(s) && usable(scenes[n - 1]));
  expect(i, `no two consecutive narration scenes with images in ${PARENT}`).toBeGreaterThan(0);
  previous = scenes[i - 1];
  target = scenes[i];
  expect(target.chapter_name, 'both scenes are in the same chapter, so the chapter list cannot '
    + 'step from one to the other').not.toBe(previous.chapter_name);
  console.log(`[fixture] lesson #${lesson.id} ${lesson.code} — scene ${target.order} `
    + `"${target.chapter_name}" arrives after scene ${previous.order} "${previous.chapter_name}"`);
});

// ── Teacher side ─────────────────────────────────────────────────────────────────────────────

/** Open the scene in the wizard editor and switch its inspector to the Animate tab. */
async function openAnimateTab(page: Page, scene: Scene) {
  // The rail marks every scene block with its own id (components/lesson/timeline.blade.php) and
  // the inspector is keyed to the selected scene, so both ends of the click are identified by
  // data rather than by a caption that changes with the UI language.
  const tile = page.locator(`[data-scene-block="${scene.id}"] button`).first();
  const inspector = page.locator(`[wire\\:key="scene-inspector-${scene.id}"]`);

  // The rail re-morphs on a 3s Livewire poll, and a click that lands mid-morph selects nothing —
  // while the inspector of the scene selected by DEFAULT looks identical, tabs and all. So this
  // waits for the inspector belonging to this scene, and reloads if a click was swallowed.
  for (let attempt = 0; attempt < 3 && !(await inspector.count()); attempt++) {
    await page.goto(`/teacher/lessons/${lesson.id}/wizard?step=4`);
    await tile.waitFor({ state: 'visible', timeout: 45_000 });
    for (let click = 0; click < 3 && !(await inspector.count()); click++) {
      await tile.click({ timeout: 5_000 }).catch(() => {});
      await page.waitForTimeout(1_500);
    }
  }
  await expect(inspector, 'the scene inspector never opened for this scene').toHaveCount(1);

  const tab = inspector.getByRole('tab', { name: /^(Animate|Animatie)$/ });
  await tab.click();
  await expect(page.getByText('Overshoot')).toBeVisible();
}

/** What the lesson actually stores for this scene's transition, read back from the player payload. */
async function storedTransition(page: Page): Promise<any> {
  await page.goto(`/lesson/${lesson.code}`);
  return page.evaluate((id) => {
    const s = (window as any).LESSON.scenes.find((x: any) => x.id === id);
    return (s?.config || {}).transition ?? null;
  }, target.id);
}

// ── Student side ─────────────────────────────────────────────────────────────────────────────

/** Every background layer that is showing a picture, with the transition it declares. */
const readLayers = (page: Page) => page.evaluate(() => {
  const kids = [...(document.getElementById('background-layer')?.children ?? [])] as HTMLElement[];
  return kids
    .filter(el => el.style.backgroundImage && el.style.backgroundImage !== 'none')
    .map(el => {
      const cs = getComputedStyle(el);
      const props = cs.transitionProperty.split(',').map(s => s.trim());
      const fns = cs.transitionTimingFunction.split(/,(?![^(]*\))/).map(s => s.trim());
      const durs = cs.transitionDuration.split(',').map(s => s.trim());
      const at = (p: string) => { const i = props.indexOf(p); return i === -1 ? null : { fn: fns[i], dur: durs[i] }; };
      return {
        image: el.style.backgroundImage.replace(/^url\("?|"?\)$/g, '').split('/').slice(-2).join('/'),
        opacity: Number(cs.opacity),
        transform: cs.transform,
        opacityEase: at('opacity'),
        transformEase: at('transform'),
      };
    });
});

/**
 * Start the lesson on `previous`, then move to `target` the way a student does — the chapter
 * list in the transport deck. Narration scenes have no Next button; the deck's chapter list is
 * the student's own way of stepping forward, and it goes through the same _playScene path.
 */
async function arriveAtTarget(page: Page) {
  await page.goto(`/lesson/${lesson.code}?scene=${previous.order - 1}`);
  await page.getByRole('button', { name: /start lesson/i }).click();
  await expect(page.locator('#background-layer > div').first()).toBeVisible();
  expect(await page.evaluate(() => document.visibilityState), 'a hidden page never fires rAF').toBe('visible');
  await expect.poll(async () => (await readLayers(page)).some(l => l.opacity > 0.5), { timeout: 15_000 }).toBe(true);

  await page.mouse.move(640, 700);                                   // summon the transport deck
  const chapters = page.locator('button[aria-expanded]').first();
  await expect(chapters).toBeVisible({ timeout: 10_000 });
  await chapters.click();
  await expect(chapters).toHaveAttribute('aria-expanded', 'true');
  // Chapter names are the lesson's own data, so they read the same in every UI language.
  await page.getByRole('button', { name: target.chapter_name! }).click();
}

test.describe('Animate tab — scene transition reaches the student', () => {

  test('the easing a teacher picks is what the background crossfades on', async ({ page }) => {
    // "Overshoot" is the loudest of the four curves — if any choice were to survive the trip from
    // the panel to the layer it would be this one.
    await openAnimateTab(page, target);
    await page.getByText('Overshoot').first().click();
    await page.waitForTimeout(1500);                                 // Livewire round-trip
    await page.screenshot({ path: `${SHOT}/teacher-picked-overshoot.png` });

    const stored = await storedTransition(page);
    expect(stored, 'the Animate tab saved nothing at all').toBeTruthy();
    expect(stored.ease, 'the tile did not even reach the database').toBe(TILE.Overshoot);
    expect(stored.type, 'the transition type changed unexpectedly').toBe('crossfade');

    await arriveAtTarget(page);
    const frames: any[][] = [];
    for (let i = 0; i < 10; i++) { frames.push(await readLayers(page)); await page.waitForTimeout(60); }
    await page.screenshot({ path: `${SHOT}/student-crossfade.png` });

    const curves = [...new Set(frames.flat().map(l => l.opacityEase?.fn).filter(Boolean))];
    console.log(`[measured] stored ease=${stored.ease} → layer opacity curves ${curves.join(' | ')}`);

    expect(
      curves.some(c => c === EASE.pop),
      `the teacher chose Overshoot (${EASE.pop}) but the background layers fade on `
      + `${curves.join(' / ')} — the Easing tile never reaches the player`,
    ).toBe(true);
  });

  test('the duration a teacher sets is what the background crossfades over', async ({ page }) => {
    // The control test. If this passes while the easing one fails, the panel is half-wired —
    // which is worse than an inert panel, because the teacher gets feedback from one slider and
    // concludes the other one works too.
    await openAnimateTab(page, target);
    // The scene-transition Duration slider is the 0–3 second one (a layer's own duration slider
    // is in milliseconds, 100–5000), so this can never grab the wrong range.
    const duration = page.locator('input[type=range][min="0"][max="3"]:visible');
    await expect(duration).toHaveCount(1);
    await duration.evaluate((el: HTMLInputElement) => {
      el.value = '2.5';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(1500);

    const stored = await storedTransition(page);
    expect(Number(stored.duration), 'the Duration slider saved nothing').toBeCloseTo(2.5, 1);

    await arriveAtTarget(page);
    const durations = [...new Set((await readLayers(page)).map(l => l.opacityEase?.dur).filter(Boolean))];
    console.log(`[measured] stored duration=${stored.duration}s → layer opacity durations ${durations.join(' | ')}`);
    expect(
      durations.includes('2.5s'),
      `the teacher set 2.5s but the layers fade over ${durations.join(' / ')}`,
    ).toBe(true);
  });

  test('a slide transition carries the easing the teacher picked', async ({ page }) => {
    // Scoping test: the player applies the chosen curve only on the branch that has transform
    // frames to animate. If this one passes while the crossfade one fails, the defect is
    // "crossfade ignores easing", not "the Animate tab is decorative".
    await openAnimateTab(page, target);
    const type = page.locator('select').filter({ has: page.locator('option[value="slide-left"]') });
    await expect(type).toHaveCount(1);
    await type.selectOption('slide-left');
    await page.waitForTimeout(1200);
    await page.getByText('Accelerate').first().click();
    await page.waitForTimeout(1500);

    const stored = await storedTransition(page);
    expect(stored.type).toBe('slide-left');
    expect(stored.ease).toBe(TILE.Accelerate);

    await arriveAtTarget(page);
    const frames: any[][] = [];
    for (let i = 0; i < 8; i++) { frames.push(await readLayers(page)); await page.waitForTimeout(60); }
    await page.screenshot({ path: `${SHOT}/student-slide.png` });

    const curves = [...new Set(frames.flat().map(l => l.transformEase?.fn).filter(Boolean))];
    console.log(`[measured] stored type=slide-left ease=${stored.ease} → transform curves ${curves.join(' | ')}`);
    expect(
      curves.some(c => c === EASE.exit),
      `the teacher chose a slide on Accelerate (${EASE.exit}) but the layer moves on ${curves.join(' / ')}`,
    ).toBe(true);
  });
});
