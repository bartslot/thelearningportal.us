/**
 * The Script panel's play bar, after a real narration round trip.
 *
 * A claim from the narration lane says the bar never swaps its record button for Play once the
 * audio comes back, leaving the teacher with no way to hear what was just made. This spec goes
 * after that claim in a real browser, in the two states a teacher can actually be in:
 *
 *   1. FIRST narration — a scene with no recording at all ("No narration yet"), written from
 *      scratch and narrated. This is the branch where the panel has no WaveSurfer yet and has to
 *      build one (reloadAudio → mountWave).
 *   2. RE-narration — a scene that already has audio, whose words are edited, so the bar goes
 *      Play → Re-narrate → (audio arrives) → Play again.
 *
 * Both press the real button and wait for the real queued Azure job: exactly two text-to-speech
 * calls per run, always against a throwaway copy of a lesson, never a teacher's own.
 *
 *   npx playwright test script-panel-play-after-narrate --project=chromium \
 *     --output=tests/playwright/results/script-panel-play
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

/** Evidence lands outside the output dir, which Playwright sweeps when a test passes. */
const SHOTS = 'tests/playwright/results/script-panel-play-shots';
const shot = (name: string) => path.join(SHOTS, name);
mkdirSync(SHOTS, { recursive: true });

/** The lesson to copy. A code, because ids are database-local. */
const PARENT = process.env.SCRIPT_PANEL_PARENT ?? '2CM0GW';

const DUTCH_SCRIPT =
  'De stormvloed van 1953 kwam in de nacht en niemand had de mensen gewaarschuwd. '
  + 'Het water brak door de dijken en liep binnen een paar uur over het hele eiland. '
  + 'Meer dan achttienhonderd mensen kwamen om in het donker.';

function artisan<T>(code: string): T {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  const line = out.trim().split('\n').reverse().find((l) => l.trim().startsWith('{') || l.trim().startsWith('['));
  if (!line) throw new Error(`artisan returned no JSON:\n${out}`);
  return JSON.parse(line.trim()) as T;
}

type SceneRow = { id: number; kind: string; audio_path: string | null; audio_locale: string | null; bytes: number; script_len: number };

const sceneRow = (id: number) =>
  artisan<SceneRow>(`
    $s = App\\Models\\Scene::find(${id});
    echo json_encode([
      'id' => $s->id, 'kind' => $s->kind, 'audio_path' => $s->audio_path,
      'audio_locale' => $s->audio_locale,
      'bytes' => $s->audio_path && Storage::disk('public')->exists($s->audio_path)
        ? Storage::disk('public')->size($s->audio_path) : 0,
      'script_len' => mb_strlen((string) $s->script_segment),
    ]);
  `);

const scenesOf = (id: number) =>
  artisan<Array<{ id: number; order: number; kind: string; has_audio: boolean }>>(`
    $l = App\\Models\\Lesson::findOrFail(${id});
    echo json_encode($l->scenes()->orderBy('order')->get()->map(fn ($s) => [
      'id' => $s->id, 'order' => $s->order, 'kind' => $s->kind, 'has_audio' => (bool) $s->audio_path,
    ])->values());
  `);

/** Labels, in whichever of the five interface languages the signed-in teacher happens to use. */
const RECORD_BUTTON = /^(Narrate|Re-narrate|Enregistrer la voix|Renarrer|Inspreken|Opnieuw inspreken|Vertonen|Neu vertonen|Registra la voce|Rinarra)$/i;
const ADD_NARRATION = /^(Add narration|Ajouter la narration|Vertelstem toevoegen|Erzählstimme hinzufügen|Aggiungi narrazione)$/i;
const PLAY_NARRATION = /^(Play narration|Écouter la narration|Vertelstem afspelen|Erzählstimme abspielen|Riproduci la narrazione)$/i;

/** The bottom dock's editor, and only it. */
const dockOf = (page: Page) => page.locator('[x-data^="scriptEditor"]');

/** What the panel's own Alpine component believes, read live. */
const readPanel = (page: Page) =>
  page.evaluate(() => {
    const roots = [...document.querySelectorAll('[x-data]')]
      .filter((el) => (el.getAttribute('x-data') || '').startsWith('scriptEditor'));
    return roots.map((el) => {
      const d = (window as any).Alpine.$data(el);
      return { hasAudio: d.hasAudio, dirty: d.dirty, regenerating: d.regenerating, ready: d.ready, dur: d.dur };
    });
  });

/** Wait for the queued narration job, and say whether a worker is even running when it stalls. */
async function waitForAudio(sceneId: number, notBefore = '') {
  await expect
    .poll(() => {
      const s = sceneRow(sceneId);
      if (s.audio_path && s.audio_path !== notBefore) return 'done';
      const queued = artisan<{ n: number }>("echo json_encode(['n' => DB::table('jobs')->count()]);").n;
      return queued > 0 ? 'queued' : 'working';
    }, { timeout: 240_000, intervals: [2000], message: 'narration never came back — is a queue worker running?' })
    .toBe('done');
}

test.describe.serial('Script panel: Play after narrating', () => {
  let copy: { id: number; code: string };

  test.beforeAll(() => {
    const out = execFileSync('php', ['artisan', 'lessons:test-copy', PARENT, '--json'], { encoding: 'utf8' });
    copy = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!);
  });

  test('a scene narrated for the FIRST time offers Play, not another record', async ({ page }) => {
    test.setTimeout(300_000);

    const target = scenesOf(copy.id).find((s) => s.kind === 'narration')!;
    expect(target, 'the copy has no narration scene').toBeTruthy();

    // Put the scene back to never-narrated, which is the state the report describes: the panel
    // shows "No narration yet" and has no waveform to reload into.
    artisan(`
      App\\Models\\Scene::where('id', ${target.id})->update([
        'audio_path' => null, 'audio_locale' => null, 'audio_alignment' => null, 'script_segment' => '',
      ]);
      echo json_encode(['ok' => true]);
    `);

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target.id}`, { waitUntil: 'domcontentloaded' });

    const dock = dockOf(page);
    const add = dock.getByRole('button', { name: ADD_NARRATION });
    const line = dock.locator('[data-line]').first();
    await expect.poll(async () => (await add.count()) + (await line.count()), { timeout: 30_000 }).toBeGreaterThan(0);
    if (await add.count()) await add.first().click();

    await line.waitFor();
    await line.click();
    await page.keyboard.type(DUTCH_SCRIPT);

    // Before: no recording, so the bar offers the labelled record button and nothing else.
    await expect(dock.getByRole('button', { name: PLAY_NARRATION })).toBeHidden();
    await dock.getByRole('button', { name: RECORD_BUTTON }).click();
    await page.screenshot({ path: shot('first-narrating.png') });

    await expect
      .poll(() => sceneRow(target.id).script_len, { timeout: 20_000, intervals: [1000], message: 'pressing Narrate did not save the script' })
      .toBeGreaterThan(100);

    await waitForAudio(target.id);
    const scene = sceneRow(target.id);
    expect(scene.bytes).toBeGreaterThan(10_000);

    // What the teacher can now DO: a play control, actually visible and actually enabled.
    const play = dock.getByRole('button', { name: PLAY_NARRATION });
    await expect(play, 'the Script panel never offered Play after the first narration').toBeVisible({ timeout: 90_000 });
    await expect(play).toBeEnabled({ timeout: 30_000 });
    await expect(dock.getByRole('button', { name: RECORD_BUTTON }), 'the record button lingered next to Play').toBeHidden();
    await expect(dock.getByText(/No narration yet|Nog geen opname|Aucune narration|Noch keine Aufnahme|Nessuna narrazione/i)).toBeHidden();

    const panel = (await readPanel(page))[0];
    expect(panel.hasAudio).toBe(true);
    expect(panel.dirty).toBe(false);
    expect(panel.regenerating).toBe(false);

    await page.screenshot({ path: shot('first-narrated-play-offered.png') });

    // And pressing it really starts the track: the clock moves.
    await play.click();
    await expect.poll(async () => (await readPanel(page))[0].dur, { timeout: 15_000 }).toBeGreaterThan(3);
  });

  test('a RE-narrated scene goes Play → Re-narrate → Play again', async ({ page }) => {
    test.setTimeout(300_000);

    const target = scenesOf(copy.id).filter((s) => s.kind === 'narration' && s.has_audio).pop()!;
    expect(target, 'the copy has no already-narrated narration scene').toBeTruthy();
    const before = sceneRow(target.id);

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target.id}`, { waitUntil: 'domcontentloaded' });

    const dock = dockOf(page);
    const play = dock.getByRole('button', { name: PLAY_NARRATION });
    const line = dock.locator('[data-line]').first();
    await line.waitFor();

    // A scene that already has audio opens ON Play.
    await expect(play, 'an already-narrated scene did not open on Play').toBeVisible({ timeout: 30_000 });

    // Editing the words makes the recording stale, so the bar has to offer Re-narrate instead.
    await line.click();
    await page.keyboard.press('End');
    await page.keyboard.type(' De dijken hielden het niet.');
    await expect(dock.getByRole('button', { name: RECORD_BUTTON })).toBeVisible();
    await expect(play).toBeHidden();

    await dock.getByRole('button', { name: RECORD_BUTTON }).click();
    await expect
      .poll(() => sceneRow(target.id).script_len, { timeout: 20_000, intervals: [1000], message: 'pressing Re-narrate did not save the edit' })
      .toBeGreaterThan(before.script_len);

    await waitForAudio(target.id, before.audio_path ?? '');
    expect(sceneRow(target.id).bytes).toBeGreaterThan(10_000);

    await expect(play, 'the Script panel never came back to Play after re-narrating').toBeVisible({ timeout: 90_000 });
    await expect(dock.getByRole('button', { name: RECORD_BUTTON })).toBeHidden();
    await page.screenshot({ path: shot('renarrated-play-offered.png') });
  });
});

/**
 * The reported case, replayed on its own fixture.
 *
 * The claim was raised against a copy of the Dutch Abel Tasman lesson (a voyage lesson with one
 * narration scene), narrated in Dutch from an empty script. Same lesson, same scene, same flow —
 * and the same question: when the audio lands, can the teacher hear it?
 */
test.describe.serial('Script panel: the reported Dutch case', () => {
  const DUTCH_PARENT = process.env.SCRIPT_PANEL_NL_PARENT ?? 'WMDIF8';
  let copy: { id: number; code: string };

  test.beforeAll(() => {
    const out = execFileSync('php', ['artisan', 'lessons:test-copy', DUTCH_PARENT, '--json'], { encoding: 'utf8' });
    copy = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!);
  });

  test('the Dutch scene from the report ends on Play, not on another record button', async ({ page }) => {
    test.setTimeout(300_000);

    const target = scenesOf(copy.id).find((s) => s.kind === 'narration')!;
    expect(target, `the copy of ${DUTCH_PARENT} has no narration scene`).toBeTruthy();

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target.id}`, { waitUntil: 'domcontentloaded' });

    const dock = dockOf(page);
    const add = dock.getByRole('button', { name: ADD_NARRATION });
    const line = dock.locator('[data-line]').first();
    await expect.poll(async () => (await add.count()) + (await line.count()), { timeout: 30_000 }).toBeGreaterThan(0);
    if (await add.count()) await add.first().click();

    await line.waitFor();
    await line.click();
    await page.keyboard.type(
      'Abel Tasman werkte niet voor een koning maar voor de Verenigde Oostindische Compagnie. '
      + 'In augustus 1642 vertrok hij uit Batavia met twee schepen om het onbekende zuidland te zoeken. '
      + 'De reis duurde tien maanden en bracht hem langs Mauritius naar het zuiden.',
    );

    await dock.getByRole('button', { name: RECORD_BUTTON }).click();
    await expect
      .poll(() => sceneRow(target.id).script_len, { timeout: 20_000, intervals: [1000] })
      .toBeGreaterThan(100);

    await waitForAudio(target.id);
    const scene = sceneRow(target.id);
    expect(scene.audio_locale).toBe('nl');
    expect(scene.bytes).toBeGreaterThan(10_000);

    // The exact assertions the report made, in the direction they should point.
    const play = dock.getByRole('button', { name: PLAY_NARRATION });
    await expect(play, 'the Script panel never offered Play after narrating').toBeVisible({ timeout: 60_000 });
    await expect(dock.getByRole('button', { name: RECORD_BUTTON })).toBeHidden();

    const panel = (await readPanel(page))[0];
    expect(panel).toMatchObject({ hasAudio: true, dirty: false, regenerating: false });
    expect(panel.dur).toBeGreaterThan(3);

    await page.screenshot({ path: shot('nl-reported-case-play-offered.png') });
  });
});
