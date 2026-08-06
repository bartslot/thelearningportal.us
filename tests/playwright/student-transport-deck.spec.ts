/**
 * The student experience, end to end, on the transport deck.
 *
 * Nobody had watched this in a browser. Everything below is driven the way a class drives it —
 * press Start, answer the quiz, press K, press C, open the chapter list, hover the bottom edge —
 * and every assertion is on something a student can actually see or hear.
 *
 * Two lessons, because a lesson has no single type and the deck must behave the same in all of
 * them: one that OPENS ON A QUIZ and later sails a voyage leg (Abel Tasman), and one plainly
 * narrated with maps and galleries (Anne Frank).
 *
 * ── Harness notes, learned the hard way ────────────────────────────────────────────────────
 *
 * 1. HMR reloads the player mid-lesson. Another session editing app.css sends `full-reload` down
 *    the Vite socket and the page snaps back to its title screen — which reads exactly like a
 *    player bug. Do NOT "fix" this by blocking `@vite/client`: the player imports voyage-tour.js
 *    dynamically, and without the client that import 404s on its `?t=` URL, so every voyage scene
 *    is silently skipped ("voyage tour failed to load"). That looks like an even worse bug. The
 *    right cut is `routeWebSocket`: forward every HMR message except `full-reload`.
 *
 * 2. The deck is hidden with `opacity` on an ancestor, so Playwright's own visibility check calls
 *    invisible chrome "visible". `deckOpacity()` folds the ancestor opacities together instead —
 *    that is what the eye gets.
 *
 * 3. The pointer counts. Chrome stays up while the pointer is in the top 88px or bottom 176px, and
 *    clicking "Start lesson" leaves it in the bottom band. Park it mid-stage before expecting the
 *    deck to go away, the way a hand leaves the trackpad.
 *
 * 4. Never match a control by its English label. The dev auto-login teacher's `locale` is shared
 *    state; other suites set it to fr/nl and leave it there, so "Chapters" was "Chapitres" in one
 *    run and "Pauzeren" in the next. Every selector here is structural (`data-tooltip-key`,
 *    `aria-expanded`, `aria-pressed`). "Start lesson" is the one exception — it is hardcoded
 *    English in the title screen, untranslated.
 *
 * 5. Nothing here asserts on a CSS transition mid-flight, so the default `chromium` project's
 *    SwiftShader flags are fine — the voyage map needs WebGL anyway.
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test';

// Overridable, because a rebuilt catalogue renames every lesson; `lessonWith()` falls back to
// asking the catalogue for one that actually carries the scene kinds a test needs.
const VOYAGE_CODE = process.env.DECK_VOYAGE_LESSON_CODE ?? 'LYTVSK';    // Abel Tasman
const NARRATED_CODE = process.env.DECK_NARRATED_LESSON_CODE ?? 'CF0AVG'; // Anne Frank

const SHOTS = 'tests/playwright/report/student-deck';

// A 1280×720 stage: the centre is clear of both edge zones, 700 is inside the bottom one.
const CENTRE = { x: 640, y: 320 };
const BOTTOM_EDGE = { x: 640, y: 700 };

// Deck controls, by structure rather than by label (harness note 4).
const PLAY_PAUSE = 'button[data-tooltip-key="K"]';
const MUTE = 'button[data-tooltip-key="M"]';
const SUBTITLES = 'button[data-tooltip-key="C"]';
const CHAPTERS = '#lesson-stage button[aria-expanded]';
const CHAPTER_ITEMS = '#lesson-stage div.mb-3.overflow-y-auto button';
const VOLUME = 'input.lp-volume';
const CAPTION = '[aria-live="polite"] p';

type Snapshot = {
  phase: string;
  index: number;
  kind: string | null;
  chapter: string;
  isPlaying: boolean;
  paused: boolean;
  audioPlaying: boolean;
  captionsOn: boolean;
  captionText: string;
  muted: boolean;
  volume: number;
  chapters: number;
  currentIsGame: boolean;
  atLandfall: boolean;
  audio: { time: number; paused: boolean; volume: number } | null;
};

/**
 * Read the player's own state.
 *
 * Alpine ships inside Livewire here and never lands on `window`, so the component is reached
 * through the stage element's data stack — the very object the template's expressions see.
 */
async function read(page: Page): Promise<Snapshot> {
  return page.evaluate(() => {
    const d = (document.getElementById('lesson-stage') as any)._x_dataStack[0];
    const a = d._audio;
    return {
      phase: d.phase,
      index: d._sceneIndex,
      kind: d.lesson?.scenes?.[d._sceneIndex]?.kind ?? null,
      chapter: d.currentChapterName ?? '',
      isPlaying: d.isPlaying,
      paused: d.playbackPaused,
      audioPlaying: d.audioPlaying,
      captionsOn: d.captionsOn,
      captionText: d.captionText ?? '',
      muted: d.audioMuted,
      volume: d.volumeLevel,
      chapters: d.chapters?.length ?? 0,
      currentIsGame: d.currentIsGame,
      atLandfall: d.showMapContinue,
      audio: a ? { time: a.currentTime, paused: a.paused, volume: a.volume } : null,
    };
  });
}

/** What the eye gets: the deck's opacity with every ancestor's opacity folded in. */
async function deckOpacity(page: Page): Promise<number> {
  return page.evaluate((sel) => {
    let node = document.querySelector(sel) as HTMLElement | null;
    if (!node) return -1;
    let opacity = 1;
    for (; node && node !== document.body; node = node.parentElement as HTMLElement) {
      opacity *= Number(getComputedStyle(node).opacity);
    }
    return Number(opacity.toFixed(3));
  }, PLAY_PAUSE);
}

/** Pick a lesson that really contains these scene kinds, preferring the pinned code. */
async function lessonWith(request: APIRequestContext, kinds: string[], preferred: string) {
  const kindsOf = async (code: string) => {
    const html = await (await request.get(`/lesson/${code}`)).text();
    const start = html.indexOf('"scenes":[');
    if (start === -1) return [] as string[];
    // Scan to the matching bracket: scene configs nest arrays, so a lazy regex stops too early.
    const open = html.indexOf('[', start);
    let depth = 0;
    let end = -1;
    for (let i = open; i < html.length; i++) {
      if (html[i] === '[') depth++;
      else if (html[i] === ']' && --depth === 0) { end = i + 1; break; }
    }
    if (end === -1) return [] as string[];
    return (JSON.parse(html.slice(open, end)) as Array<{ kind: string }>).map((s) => s.kind);
  };

  const has = (found: string[]) => kinds.every((k) => found.includes(k));
  if (has(await kindsOf(preferred))) return preferred;

  const manifest = await (await request.get('/api/v1/catalog/manifest')).json();
  for (const lesson of (manifest.lessons ?? []).filter((l: any) => l.status === 'published')) {
    if (has(await kindsOf(lesson.code))) return lesson.code as string;
  }
  throw new Error(`No published lesson contains all of [${kinds.join(', ')}] — nothing to test.`);
}

/** Let HMR through, except the message that would reload the lesson out from under us. */
async function survivesHotReload(page: Page) {
  await page.routeWebSocket(/:5173\//, (ws) => {
    const server = ws.connectToServer();
    ws.onMessage((m) => server.send(m as any));
    server.onMessage((m) => {
      if (typeof m === 'string' && m.includes('full-reload')) return;
      ws.send(m as any);
    });
  });
}

/** Load a lesson's player and stop at the title screen, exactly where a class starts. */
async function openPlayer(page: Page, code: string) {
  await survivesHotReload(page);
  await page.goto(`/lesson/${code}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(
    () => (document.getElementById('lesson-stage') as any)?._x_dataStack?.[0]?.phase === 'TITLE_SCREEN',
    null,
    { timeout: 45_000 },
  );
}

async function pressStart(page: Page) {
  // "Start lesson" is hardcoded English on the title screen (harness note 4).
  await page.getByRole('button', { name: /Start lesson/i }).click();
  await page.waitForFunction(
    () => (document.getElementById('lesson-stage') as any)._x_dataStack[0].phase === 'INTRO',
    null,
    { timeout: 45_000 },
  );
}

/** Open the chapter list and click the nth entry, the way a student changes chapter. */
async function jumpToChapter(page: Page, nth: number) {
  await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);          // summon the deck
  const chapters = page.locator(CHAPTERS);
  await expect(chapters).toBeVisible({ timeout: 45_000 });
  await chapters.click();
  await page.locator(CHAPTER_ITEMS).nth(nth).click();
}

/** The queue index of the first chapter whose scene is of this kind. */
async function chapterOfKind(page: Page, kind: string, occurrence = 0) {
  return page.evaluate(([k, n]) => {
    const d = (document.getElementById('lesson-stage') as any)._x_dataStack[0];
    const matches = d.chapters
      .map((c: any, i: number) => ({ i, kind: d.lesson.scenes[c.index]?.kind }))
      .filter((x: any) => x.kind === k);
    return matches[n as number]?.i ?? -1;
  }, [kind, occurrence] as const);
}

/**
 * Answer the question on screen the way a student who was paying attention would: correctly.
 *
 * Two reasons it has to be the RIGHT answer rather than the first button. The options are
 * reshuffled for every player, so clicking blind scores somewhere between 0 and full marks and
 * the score screen can't be asserted at all — one run really did come back 0/4. And a lesson
 * whose points never move is exactly the failure this test exists to catch.
 *
 * The correct text comes from the same payload the page was rendered with, then it is matched
 * against what is printed on the buttons — no reaching into the overlay's private shuffle map.
 *
 * The read-gate is deliberate (two seconds plus reading time before the answers unlock), so this
 * waits on the buttons becoming enabled rather than on a clock.
 */
async function answerCurrentQuestion(page: Page) {
  const live = page.locator('#lesson-game-overlay button[data-opt]:not([disabled])');
  await expect(live.first()).toBeVisible({ timeout: 45_000 });

  const correctText = await page.evaluate(() => {
    const host = document.getElementById('lesson-game-overlay')!;
    const asked = (host.querySelector('.text-2xl') as HTMLElement)?.innerText.trim() ?? '';
    const lesson = (window as any).LESSON;
    const pool = [
      ...(lesson.scenes ?? []).flatMap((s: any) => s.quiz_questions ?? []),
      ...(lesson.quiz_questions ?? []),
    ];
    const q = pool.find((x: any) => String(x.question).trim() === asked);
    return q ? String(q.options[Number(q.correct_index)]) : null;
  });
  expect(correctText, 'the question on screen should come from the lesson payload').toBeTruthy();

  const chosen = live.filter({ hasText: correctText! }).first();
  // Note the slot before clicking: answering disables every option, so a `:not([disabled])`
  // locator stops matching the very button it just pressed.
  const slot = await chosen.getAttribute('data-opt');
  await chosen.click();
  // Right answers are marked right, immediately — that is the whole feedback loop.
  await expect(page.locator(`#lesson-game-overlay button[data-opt="${slot}"]`))
    .toHaveClass(/qz-correct/, { timeout: 10_000 });
  // Answering IS the navigation: the card holds on its feedback, then the next one arrives with
  // a gate of its own. A fresh unlocked card, or the score screen, ends the wait.
  await page.waitForFunction(
    () => {
      const host = document.getElementById('lesson-game-overlay')!;
      return !!host.querySelector('[data-final-score]')
        || !!host.querySelector('button[data-opt]:not([disabled])')
        || host.children.length === 0;
    },
    null,
    { timeout: 60_000 },
  );
}

test.describe('Student: the lesson player and its transport deck', () => {
  test.describe.configure({ mode: 'serial' });
  test.slow();

  test('the opening quiz can be read, answered, scored and handed back', async ({ page, request }) => {
    const code = await lessonWith(request, ['game', 'voyage'], VOYAGE_CODE);
    await openPlayer(page, code);
    await pressStart(page);

    // This lesson opens straight onto a quiz card — a scene kind, not a lesson type.
    const card = page.locator('#lesson-game-overlay');
    const options = card.locator('button[data-opt]');
    await expect(options.first()).toBeVisible({ timeout: 45_000 });
    await expect(card).toContainText('?');                        // a real question, not a spinner
    expect(await options.count()).toBeGreaterThanOrEqual(2);
    expect((await read(page)).currentIsGame).toBe(true);

    // The answers are locked while the class reads them, and release on their own.
    await expect(options.first()).toBeDisabled();
    await expect(card.locator('button[data-opt]:not([disabled])').first())
      .toBeVisible({ timeout: 45_000 });
    await page.screenshot({ path: `${SHOTS}/01-quiz-card.png` });

    // Answer the whole quiz.
    for (let i = 0; i < 12; i++) {
      if (await page.locator('[data-final-score]').count()) break;
      await answerCurrentQuestion(page);
    }

    // The score screen: three stars for a clean sheet, "n / n correct", and points counted up
    // from zero. A class that answered everything right must be told so.
    const finalScore = page.locator('[data-final-score]');
    await expect(finalScore).toBeVisible({ timeout: 30_000 });
    const total = await page.evaluate(() => {
      const d = (document.getElementById('lesson-stage') as any)._x_dataStack[0];
      return (d.lesson.scenes.find((s: any) => s.kind === 'game')?.quiz_questions
        ?? d.lesson.quiz_questions ?? []).length;
    });
    await expect(card).toContainText(new RegExp(`${total}\\s*/\\s*${total}`));
    expect(await card.locator('svg.qz-star.fill-warning').count(), 'all correct is three stars')
      .toBe(3);
    await expect.poll(async () => Number(await finalScore.innerText()), { timeout: 15_000 })
      .toBeGreaterThanOrEqual(total * 10);
    await page.screenshot({ path: `${SHOTS}/02-score-screen.png` });

    // …and it hands the class back to the lesson, leaving nothing painted over the next scene.
    const before = (await read(page)).index;
    await page.locator('#lesson-game-overlay [data-done]').click();
    await expect.poll(async () => (await read(page)).index, { timeout: 30_000 }).toBeGreaterThan(before);
    await expect.poll(async () => page.locator('#lesson-game-overlay').innerHTML(), { timeout: 15_000 })
      .toBe('');
  });

  test('narration plays, the deck gets out of the way, and comes back', async ({ page, request }) => {
    const code = await lessonWith(request, ['narration'], NARRATED_CODE);
    await openPlayer(page, code);
    await pressStart(page);
    await jumpToChapter(page, await chapterOfKind(page, 'narration'));

    // Narration is really running: the audio element advances.
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 30_000 }).toBe(true);
    const started = (await read(page)).audio!.time;
    await expect.poll(async () => (await read(page)).audio!.time, { timeout: 15_000 })
      .toBeGreaterThan(started + 0.5);
    expect((await read(page)).chapter, 'the chapter line names the scene').not.toBe('');

    // Hand back the trackpad and the stage goes clean.
    await page.mouse.move(CENTRE.x, CENTRE.y);
    await expect.poll(() => deckOpacity(page), { timeout: 15_000 }).toBe(0);
    await page.screenshot({ path: `${SHOTS}/03-deck-hidden-while-playing.png` });

    // Reach for the bottom of the screen and it comes back — without pausing anything.
    await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);
    await expect.poll(() => deckOpacity(page), { timeout: 15_000 }).toBe(1);
    expect((await read(page)).paused, 'hovering must not pause the lesson').toBe(false);
    await page.screenshot({ path: `${SHOTS}/04-deck-on-edge-hover.png` });

    // Pause from the deck and it stays up wherever the pointer goes.
    const playingLabel = await page.locator(PLAY_PAUSE).getAttribute('aria-label');
    await page.locator(PLAY_PAUSE).click();
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(true);
    expect((await read(page)).audio!.paused, 'the narration stops with the stage').toBe(true);
    // The label flips with the state. Compared, not spelled out — the UI language is shared state.
    expect(await page.locator(PLAY_PAUSE).getAttribute('aria-label')).not.toBe(playingLabel);
    await page.mouse.move(CENTRE.x, CENTRE.y);
    await expect.poll(() => deckOpacity(page), { timeout: 15_000 }).toBe(1);
    await page.screenshot({ path: `${SHOTS}/05-deck-paused.png` });

    // And resume puts it back to work.
    await page.locator(PLAY_PAUSE).click();
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(false);
    await expect.poll(async () => (await read(page)).audio!.paused, { timeout: 10_000 }).toBe(false);
  });

  test('K, Space, M, C and the arrow keys do what a student expects', async ({ page, request }) => {
    const code = await lessonWith(request, ['narration'], NARRATED_CODE);
    await openPlayer(page, code);
    await pressStart(page);
    await jumpToChapter(page, await chapterOfKind(page, 'narration'));
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 30_000 }).toBe(true);
    await page.mouse.move(CENTRE.x, CENTRE.y);

    // K pauses, K resumes.
    await page.keyboard.press('k');
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(true);
    expect((await read(page)).audio!.paused).toBe(true);
    await page.keyboard.press('k');
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(false);

    // Space does the same.
    await page.keyboard.press('Space');
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(true);
    await page.keyboard.press('Space');
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(false);

    // M silences the lesson, and the narration element goes with it.
    await page.keyboard.press('m');
    await expect.poll(async () => (await read(page)).muted, { timeout: 10_000 }).toBe(true);
    expect((await read(page)).audio!.volume).toBe(0);
    await page.keyboard.press('m');
    await expect.poll(async () => (await read(page)).muted, { timeout: 10_000 }).toBe(false);
    await expect.poll(async () => (await read(page)).audio!.volume, { timeout: 10_000 })
      .toBeGreaterThan(0);

    // ↓ and ↑ step the volume, and the narration follows the deck.
    const level = (await read(page)).volume;
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await expect.poll(async () => (await read(page)).volume, { timeout: 10_000 }).toBeLessThan(level);
    const stepped = await read(page);
    expect(stepped.audio!.volume).toBeCloseTo(stepped.volume, 2);
    await page.keyboard.press('ArrowUp');
    await expect.poll(async () => (await read(page)).volume, { timeout: 10_000 })
      .toBeGreaterThan(stepped.volume);

    // C writes the narration along the bottom of the stage, in time with the voice.
    await page.keyboard.press('c');
    await expect.poll(async () => (await read(page)).captionsOn, { timeout: 10_000 }).toBe(true);
    // Read "has words" and "is on screen" in one go. Split across two assertions this flakes:
    // the bubble is hidden whenever `captionText` is empty, and it empties for a frame between
    // cues and again when a scene hands over to the next one.
    const captionOnScreen = () => page.evaluate((sel) => {
      const p = document.querySelector(sel) as HTMLElement | null;
      return !!p && p.offsetParent !== null && p.innerText.trim().length > 0;
    }, CAPTION);
    await expect.poll(captionOnScreen, { timeout: 30_000 }).toBe(true);
    await page.screenshot({ path: `${SHOTS}/06-captions-on.png` });

    // …and C again takes them away.
    await page.keyboard.press('c');
    await expect.poll(async () => (await read(page)).captionsOn, { timeout: 10_000 }).toBe(false);
    await expect.poll(captionOnScreen, { timeout: 10_000 }).toBe(false);

    // F goes fullscreen and F comes back. Headless takes its time about it, hence the polling.
    await page.keyboard.press('f');
    await expect.poll(() => page.evaluate(() => !!document.fullscreenElement), { timeout: 20_000 })
      .toBe(true);
    await page.keyboard.press('f');
    await expect.poll(() => page.evaluate(() => !!document.fullscreenElement), { timeout: 20_000 })
      .toBe(false);
  });

  test('the deck volume slider and subtitle button work by pointer too', async ({ page, request }) => {
    const code = await lessonWith(request, ['narration'], NARRATED_CODE);
    await openPlayer(page, code);
    await pressStart(page);
    await jumpToChapter(page, await chapterOfKind(page, 'narration'));
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 30_000 }).toBe(true);
    await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);

    // Slide it down. The narration follows immediately, mid-sentence.
    const slider = page.locator(VOLUME);
    await slider.fill('0.35');
    await expect.poll(async () => (await read(page)).volume, { timeout: 10_000 }).toBeCloseTo(0.35, 2);
    expect((await read(page)).audio!.volume).toBeCloseTo(0.35, 2);

    // All the way down is mute, and back up un-mutes — the deck's own rule.
    await slider.fill('0');
    await expect.poll(async () => (await read(page)).muted, { timeout: 10_000 }).toBe(true);
    expect(await page.locator(MUTE).getAttribute('aria-label')).toBeTruthy();
    await slider.fill('0.8');
    await expect.poll(async () => (await read(page)).muted, { timeout: 10_000 }).toBe(false);
    expect((await read(page)).volume).toBeCloseTo(0.8, 2);

    // The subtitles button is a plain toggle, and says so to a screen reader.
    const cc = page.locator(SUBTITLES);
    const wasOn = (await cc.getAttribute('aria-pressed')) === 'true';
    await cc.click();
    await expect(cc).toHaveAttribute('aria-pressed', String(!wasOn));
    await cc.click();
    await expect(cc).toHaveAttribute('aria-pressed', String(wasOn));
  });

  test('the chapter list opens, names the scenes and jumps', async ({ page, request }) => {
    const code = await lessonWith(request, ['narration'], NARRATED_CODE);
    await openPlayer(page, code);
    await pressStart(page);
    await page.mouse.move(BOTTOM_EDGE.x, BOTTOM_EDGE.y);

    const chapters = page.locator(CHAPTERS);
    await expect(chapters).toHaveAttribute('aria-expanded', 'false');
    await chapters.click();
    await expect(chapters).toHaveAttribute('aria-expanded', 'true');

    const items = page.locator(CHAPTER_ITEMS);
    const count = await items.count();
    expect(count, 'the list holds one entry per narrative chapter').toBeGreaterThan(3);
    expect(count).toBe((await read(page)).chapters);
    await page.screenshot({ path: `${SHOTS}/07-chapter-list.png` });

    // Jump well down the list and land on exactly that chapter.
    const target = await chapterOfKind(page, 'narration', 1);
    const name = (await items.nth(target).innerText()).split('\n').pop()!.trim();
    const before = (await read(page)).index;
    await items.nth(target).click();
    await expect.poll(async () => (await read(page)).chapter, { timeout: 30_000 }).toBe(name);
    expect((await read(page)).index).not.toBe(before);
    // The list closes behind the jump — it is a menu, not a sidebar.
    await expect(chapters).toHaveAttribute('aria-expanded', 'false');
    // And the new scene starts speaking for itself.
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 30_000 }).toBe(true);
  });

  /**
   * KNOWN DEFECT — annotated `test.fail()` so the suite stays green while it is open, and goes
   * RED the day it is fixed (Playwright reports an unexpected pass). Delete the annotation then.
   *
   * Jumping out of a map/gallery/voyage scene through the chapter list leaves that scene's stage
   * painted over everything after it. `_playScene` (lesson-player.js:1170) only ever ENTERS a
   * stage — `_teardownStageScene` is called from `_advanceFromMap` (the Continue button),
   * `_playVideoScene` and the end of the lesson, never on the way into a plain narrated scene.
   * See tests/playwright/report/student-deck/05-deck-paused.png: the chapter line reads "A family
   * in Frankfurt" and its narration is audibly playing, while the previous scene's atlas, its
   * amber Continue button and MapLibre's zoom buttons sit on top of the whole player.
   */
  test('leaving a map scene by the chapter list clears its stage', async ({ page, request }) => {
    test.fail();   // inside the body on purpose: at describe level it would mark every test below
    const code = await lessonWith(request, ['map', 'narration'], NARRATED_CODE);
    await openPlayer(page, code);
    await pressStart(page);

    // Only meaningful if the lesson really opens on a map, which this one does.
    await expect.poll(async () => (await read(page)).kind, { timeout: 30_000 }).toBe('map');
    await expect(page.locator('#lesson-map-stage canvas')).toBeVisible({ timeout: 60_000 });

    await jumpToChapter(page, await chapterOfKind(page, 'narration'));
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 30_000 }).toBe(true);
    await page.screenshot({ path: `${SHOTS}/11-stale-map-stage.png` });

    // The narrated scene owns the stage now, so the atlas and its Continue must be gone.
    await expect(page.locator('#lesson-map-stage')).toBeHidden({ timeout: 10_000 });
  });

  test('a sailing voyage leg IS playback, and pause freezes the ship', async ({ page, request }) => {
    const code = await lessonWith(request, ['voyage'], VOYAGE_CODE);
    await openPlayer(page, code);
    await pressStart(page);

    // A voyage lesson is a slide deck: → leaves the opening quiz. (The chapter control is hidden
    // on a quiz scene by design, so this is the student's only way off it.)
    if ((await read(page)).currentIsGame) {
      await page.keyboard.press('ArrowRight');
      await expect.poll(async () => (await read(page)).currentIsGame, { timeout: 45_000 }).toBe(false);
    }

    // Take the second leg: the first is short enough that it can land before we get a look at it.
    const leg = await chapterOfKind(page, 'voyage', 1);
    expect(leg, 'the lesson should hold at least two voyage chapters').toBeGreaterThanOrEqual(0);
    await jumpToChapter(page, leg);
    await expect.poll(async () => (await read(page)).kind, { timeout: 45_000 }).toBe('voyage');
    await expect(page.locator('#lesson-map-stage canvas')).toBeVisible({ timeout: 90_000 });

    // Silence the narration (Esc is the player's own stop key). The ship is still under way, and
    // that alone has to count as playback: the deck reports playing and clears off the stage.
    await page.keyboard.press('Escape');
    await expect.poll(async () => (await read(page)).audioPlaying, { timeout: 15_000 }).toBe(false);
    const sailing = await read(page);
    expect(sailing.atLandfall, 'still at sea, not yet at the landfall').toBe(false);
    expect(sailing.isPlaying, 'a sailing ship is playback even with no narration').toBe(true);
    await page.mouse.move(CENTRE.x, CENTRE.y);
    await expect.poll(() => deckOpacity(page), { timeout: 15_000 }).toBe(0);
    await page.screenshot({ path: `${SHOTS}/08-voyage-sailing-clean.png` });

    // Pause mid-ocean: the chrome comes back even though no audio was ever playing.
    await page.keyboard.press('k');
    await expect.poll(async () => (await read(page)).paused, { timeout: 10_000 }).toBe(true);
    expect((await read(page)).isPlaying).toBe(false);
    await expect.poll(() => deckOpacity(page), { timeout: 15_000 }).toBe(1);
    await page.screenshot({ path: `${SHOTS}/09-voyage-paused-mid-ocean.png` });

    // And the ship really stopped. This leg takes about nine seconds to make its landfall; a
    // deliberate wait with nothing happening is the assertion here, not a poll for something.
    await page.waitForTimeout(9_000);
    expect((await read(page)).atLandfall, 'the paused ship must not reach its landfall').toBe(false);

    // Resume and the leg finishes: the ship sails on and arrives.
    await page.keyboard.press('k');
    await expect.poll(async () => (await read(page)).atLandfall, { timeout: 45_000 }).toBe(true);
    await page.screenshot({ path: `${SHOTS}/10-voyage-landfall.png` });
  });
});
