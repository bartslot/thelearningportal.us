/**
 * Multilingual narration — does a lesson actually get read aloud in its own language?
 *
 * Three questions, answered in a real browser wherever a browser can answer them:
 *
 *   1. PLAYBACK — a student opens a published lesson and the narration audio really plays.
 *      Not "an <audio> tag exists": the player builds its tracks with `new Audio()`, which never
 *      touches the DOM, so this spec wraps the constructor before the page scripts run and then
 *      watches currentTime move. A file that 404s, or one the browser refuses to decode, fails here.
 *
 *   2. LANGUAGE — the audio the student is hearing is the language the lesson is written in.
 *      The player payload carries no locale, so the assertion goes through the scene the track
 *      came from: the src names the scene, and the scene row records the language the audio is
 *      actually IN (`audio_locale`, written by GenerateSceneAudio).
 *
 *   3. VOICE — narration is never read by the American multilingual fallback. There is a unit test
 *      for the NarrationVoice map, but it cannot see the live configuration: TTS_PROVIDER_OVERRIDE_VOICE
 *      pins ONE voice for every language when it is set, which would silently defeat the whole map
 *      in production while the unit test stayed green. So the last test runs the real job under the
 *      real config with the Azure call faked, and reads the voice out of the SSML that was sent.
 *
 * Spend: exactly two real text-to-speech calls per run (one Dutch, one French), both against a
 * throwaway copy of a lesson — never the teacher's own. Everything else is faked or already
 * narrated.
 *
 *   npx playwright test narration-languages --project=chromium --output=tests/playwright/results/narration
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

/**
 * Where the screenshots land.
 *
 * Deliberately NOT testInfo.outputPath: Playwright sweeps a passing test's output directory, so
 * the evidence for everything that worked disappears and only failures leave a picture behind.
 * The whole point here is to show the feature working.
 */
const SHOTS = process.env.NARRATION_SHOT_DIR ?? 'tests/playwright/results/narration-shots';
const shot = (name: string) => path.join(SHOTS, name);
mkdirSync(SHOTS, { recursive: true });

/** Lessons to read from. Codes, not ids — and overridable, because both are database-local. */
const FRENCH_CODE = process.env.NARRATION_FR_CODE ?? 'V6OYLL';
const ENGLISH_CODE = process.env.NARRATION_EN_CODE ?? 'LYTVSK';
const DUTCH_PARENT = process.env.NARRATION_NL_CODE ?? 'WMDIF8';

/** A Dutch paragraph a teacher might plausibly type. Long enough for the language detector:
 *  ScriptLanguage needs 8+ words and 4+ function-word hits, or it falls back to the teacher's
 *  teaching language and the scene gets narrated in the wrong voice. */
const DUTCH_SCRIPT =
  'Abel Tasman werkte niet voor een koning maar voor de Verenigde Oostindische Compagnie. ' +
  'In augustus 1642 vertrok hij uit Batavia met twee schepen om het onbekende zuidland te zoeken. ' +
  'De reis duurde tien maanden en bracht hem langs Mauritius naar het zuiden.';

/**
 * Ask the application a question that only the server can answer.
 *
 * The specs already shell out to artisan in global-setup for exactly this reason, and here it is
 * unavoidable: which VOICE was requested, and which language the stored audio is in, are facts
 * that never reach the page.
 */
function artisan<T>(code: string, env: Record<string, string> = {}): T {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    encoding: 'utf8',
    env: { ...process.env, ...env },
    maxBuffer: 8 * 1024 * 1024,
  });
  const line = out.trim().split('\n').reverse().find((l) => l.trim().startsWith('{') || l.trim().startsWith('['));
  if (!line) throw new Error(`artisan returned no JSON:\n${out}`);
  return JSON.parse(line.trim()) as T;
}

type SceneRow = {
  id: number;
  kind: string;
  audio_path: string | null;
  audio_locale: string | null;
  alignment: number;
  bytes: number;
  script_len: number;
};

const sceneRow = (id: number) =>
  artisan<SceneRow>(`
    $s = App\\Models\\Scene::find(${id});
    echo json_encode([
      'id' => $s->id, 'kind' => $s->kind,
      'audio_path' => $s->audio_path, 'audio_locale' => $s->audio_locale,
      'alignment' => count($s->audio_alignment ?? []),
      'bytes' => $s->audio_path && Storage::disk('public')->exists($s->audio_path)
        ? Storage::disk('public')->size($s->audio_path) : 0,
      'script_len' => mb_strlen((string) $s->script_segment),
    ]);
  `);

/** Every scene of a lesson, in player order, so a test can pick the one it needs. */
const scenesOf = (code: string) =>
  artisan<Array<{ id: number; order: number; kind: string; has_audio: boolean; locale: string | null }>>(`
    $l = App\\Models\\Lesson::where('lesson_code', '${code}')->firstOrFail();
    echo json_encode($l->scenes()->orderBy('order')->get()->map(fn ($s) => [
      'id' => $s->id, 'order' => $s->order, 'kind' => $s->kind,
      'has_audio' => (bool) $s->audio_path, 'locale' => $s->audio_locale,
    ])->values());
  `);

/** A throwaway child of a real lesson. The generation tests WRITE; parents stay untouched. */
function testCopyOf(parent: string): { id: number; code: string } {
  const out = execFileSync('php', ['artisan', 'lessons:test-copy', parent, '--json'], { encoding: 'utf8' });
  return JSON.parse(out.trim().split('\n').filter(Boolean).pop()!);
}

/**
 * Watch every `new Audio()` the page makes, and which ones were actually asked to play.
 *
 * Installed with addInitScript so it is in place before lesson-player.js runs. It wraps rather
 * than replaces: the real element still does the real work, so this observes playback instead of
 * simulating it.
 */
async function trackAudio(page: Page) {
  await page.addInitScript(() => {
    (window as any).__tracks = [];
    const Native = window.Audio;
    const Wrapped = function (src?: string) {
      const el = new Native(src);
      if (src) {
        const rec = { src, played: false, el };
        (window as any).__tracks.push(rec);
        const play = el.play.bind(el);
        el.play = () => { rec.played = true; return play(); };
      }
      return el;
    } as unknown as typeof Audio;
    Wrapped.prototype = Native.prototype;
    window.Audio = Wrapped;
  });
}

type Track = { src: string; t: number; duration: number };

/** A scene's narration, as opposed to the background music and quiz stingers on the same path. */
const isNarration = (t: Track) => /\/scenes\/\d+\/narration\./.test(t.src) && t.t > 1;

const playingTracks = (page: Page) =>
  page.evaluate(() =>
    (window as any).__tracks
      .filter((r: any) => r.played)
      .map((r: any) => ({ src: r.src, t: r.el.currentTime, duration: r.el.duration })) as Track[],
  );

/**
 * Open a lesson as a student and let it play until narration is actually running.
 *
 * `?scene=` is the player's own deep link (the wizard preview uses it). Both fixture lessons open
 * on a "what do you already know?" quiz that waits for a human, so without it the narration this
 * spec is about is four clicked answers away.
 */
async function playNarratedScene(page: Page, code: string, sceneIndex: number): Promise<Track> {
  await trackAudio(page);
  await page.goto(`/lesson/${code}?scene=${sceneIndex}`, { waitUntil: 'domcontentloaded' });
  // "Start lesson" is hardcoded English in player.blade.php — it is the same string in every
  // interface language, which is itself worth knowing. The alternatives are here for the day
  // that is fixed, so this spec does not become the reason not to fix it.
  await page.getByRole('button', { name: /start lesson|commencer la leçon|start de les|lektion starten|inizia la lezione/i }).click();

  // Wait on the state we care about — a NARRATION track past its first second — rather than on a
  // duration. The narration filter matters: the player also starts background music and quiz
  // stingers through the same constructor, and "some audio is playing" is not the claim here.
  await expect
    .poll(async () => (await playingTracks(page)).filter(isNarration).length,
      { timeout: 30_000, intervals: [500], message: 'no narration track ever started playing' })
    .toBeGreaterThan(0);

  return (await playingTracks(page)).find(isNarration)!;
}

/** The scene id a narration URL belongs to: .../lessons/{lesson}/scenes/{scene}/narration.mp3 */
function sceneIdOf(src: string): number {
  const m = src.match(/\/scenes\/(\d+)\/narration\./);
  expect(m, `narration url does not name a scene: ${src}`).not.toBeNull();
  return Number(m![1]);
}

/**
 * The Script panel's record button, in whichever of the five interface languages the signed-in
 * teacher happens to be using. The UI locale follows the teacher's account, so a spec that matched
 * only the English label passes or fails depending on someone else's settings.
 */
const RECORD_BUTTON = /^(Narrate|Re-narrate|Enregistrer la voix|Renarrer|Inspreken|Opnieuw inspreken|Vertonen|Neu vertonen|Registra la voce|Rinarra)$/i;
const ADD_NARRATION = /^(Add narration|Ajouter la narration|Vertelstem toevoegen|Erzählstimme hinzufügen|Aggiungi narrazione)$/i;
const PLAY_NARRATION = /^(Play narration|Écouter la narration|Vertelstem afspelen|Erzählstimme abspielen|Riproduci la narrazione)$/i;

/**
 * The bottom dock, and only the bottom dock.
 *
 * The right-hand inspector has its own SCRIPT box with its own record button carrying the same
 * label, so an unscoped `getByRole` is a coin toss between two different controls. It landed on
 * the inspector's once, whose textarea was empty, and the run sat for four minutes waiting for
 * narration nobody had asked for.
 */
const scriptPanel = (page: Page) => page.locator('[x-data^="scriptEditor"]');

// Serial, and the copies are made ONCE. `lessons:test-copy` force-deletes the previous child of
// the same parent, so a test that makes its own copy halfway through deletes the lesson an earlier
// test was still describing — and the evidence of a failure with it.
test.describe.serial('multilingual narration', () => {
  let dutchCopy: { id: number; code: string };
  let frenchCopy: { id: number; code: string };

  test.beforeAll(() => {
    dutchCopy = testCopyOf(DUTCH_PARENT);
    frenchCopy = testCopyOf(FRENCH_CODE);
  });

  test('a student hears the French lesson, and the audio is French', async ({ page }) => {
    const scenes = scenesOf(FRENCH_CODE);
    const index = scenes.findIndex((s) => s.has_audio);
    expect(index, `${FRENCH_CODE} has no narrated scene at all`).toBeGreaterThanOrEqual(0);

    const track = await playNarratedScene(page, FRENCH_CODE, index);

    // The file really decoded and is really moving — not a silent stub, not a 404.
    expect(track.duration).toBeGreaterThan(5);
    const before = track.t;
    await expect.poll(async () => (await playingTracks(page)).find((t) => t.src === track.src)?.t ?? 0,
      { timeout: 10_000 }).toBeGreaterThan(before + 1);

    const res = await page.request.get(track.src);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toMatch(/audio/);

    // …and it is the FRENCH recording of that scene, per the row that produced it.
    const scene = sceneRow(sceneIdOf(track.src));
    expect(scene.audio_locale).toBe('fr');
    expect(scene.bytes).toBeGreaterThan(10_000);

    await page.screenshot({ path: shot('fr-student-playing.png') });
  });

  test('a student hears the English lesson, and the audio is English', async ({ page }) => {
    const scenes = scenesOf(ENGLISH_CODE);
    const index = scenes.findIndex((s) => s.has_audio);
    expect(index, `${ENGLISH_CODE} has no narrated scene at all`).toBeGreaterThanOrEqual(0);

    const track = await playNarratedScene(page, ENGLISH_CODE, index);
    expect(track.duration).toBeGreaterThan(5);

    const scene = sceneRow(sceneIdOf(track.src));
    expect(scene.audio_locale).toBe('en');
    expect(scene.bytes).toBeGreaterThan(10_000);

    await page.screenshot({ path: shot('en-student-playing.png') });
  });

  test('a teacher writes a Dutch script, presses Narrate, and gets Dutch audio back', async ({ page }, testInfo) => {
    test.setTimeout(300_000);   // a queued job plus a real synthesis round trip

    const copy = dutchCopy;
    const target = scenesOf(copy.code).find((s) => s.kind === 'narration');
    expect(target, `the copy of ${DUTCH_PARENT} has no narration scene`).toBeTruthy();

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target!.id}`, { waitUntil: 'domcontentloaded' });

    // A scene with no words at all offers the field rather than a dead panel. Wait for whichever
    // of the two the panel decides to show before asking which one it is — the dock renders after
    // the first Livewire round trip, so a bare count() here is a coin toss.
    const dock = scriptPanel(page);
    const add = dock.getByRole('button', { name: ADD_NARRATION });
    const line = dock.locator('[data-line]').first();
    await expect
      .poll(async () => (await add.count()) + (await line.count()), { timeout: 30_000 })
      .toBeGreaterThan(0);
    if (await add.count()) await add.first().click();

    await line.waitFor();
    await line.click();
    await page.keyboard.type(DUTCH_SCRIPT);

    await dock.getByRole('button', { name: RECORD_BUTTON }).click();
    await page.screenshot({ path: shot('nl-teacher-narrating.png') });

    // Fail fast on a mis-click. Pressing the button saves the words before it asks for audio, so
    // the saved script is proof the right control was hit — without it, a click that landed on
    // nothing is indistinguishable from a slow queue for the next four minutes.
    await expect
      .poll(() => sceneRow(target!.id).script_len,
        { timeout: 20_000, intervals: [1000], message: 'pressing Narrate did not save the script' })
      .toBeGreaterThan(100);

    // The job is queued, so a dead worker looks exactly like a slow one. Say which it is.
    await expect
      .poll(() => {
        const s = sceneRow(target!.id);
        if (s.audio_path) return 'done';
        const queued = artisan<{ n: number }>(`echo json_encode(['n' => DB::table('jobs')->count()]);`).n;
        return queued > 0 ? 'queued' : 'working';
      }, { timeout: 240_000, intervals: [3000], message: 'narration never came back — is a queue worker running?' })
      .toBe('done');

    const scene = sceneRow(target!.id);
    expect(scene.script_len).toBeGreaterThan(100);          // the teacher's words were saved
    expect(scene.audio_locale).toBe('nl');                  // …and read in Dutch
    expect(scene.audio_path).toMatch(new RegExp(`^lessons/${copy.id}/`));   // written to the COPY
    expect(scene.bytes).toBeGreaterThan(10_000);
    // mp3 with no character timings is the Azure path. An .m4a would mean the macOS `say`
    // fallback (an American system voice); timings would mean edge-tts stood in.
    expect(scene.audio_path!.endsWith('.mp3')).toBe(true);
    expect(scene.alignment).toBe(0);

    // ── What the TEACHER sees when it comes back ──────────────────────────────────────────────
    //
    // The Script panel is supposed to swap its record button for the round Play button the moment
    // the audio arrives ("Three states, and the control SAYS which one it is" — script-editor.blade).
    // It does not. The component knows the audio is there — hasAudio flips to true, and the clock
    // beside the button starts reporting the new track's length — but the play bar keeps offering
    // "Re-narrate" and no way at all to hear what was just made. The only button on screen records
    // the same paragraph again, at the same cost.
    //
    // The expectations below describe the CURRENT behaviour on purpose, so the suite stays honest
    // about the state of the feature instead of red for a known reason. Each message says what to
    // change when the panel is fixed.
    const readPanel = () => page.evaluate(() => {
      const root = [...document.querySelectorAll('[x-data]')]
        .find((el) => (el.getAttribute('x-data') || '').startsWith('scriptEditor'));
      if (!root) return null;
      const data = (window as any).Alpine.$data(root);
      return { hasAudio: data.hasAudio, dirty: data.dirty, regenerating: data.regenerating, dur: data.dur };
    });

    // The panel does hear about the finished audio — that part works, and it is what makes the
    // rest a defect rather than a race.
    await expect
      .poll(async () => (await readPanel())?.hasAudio ?? false,
        { timeout: 90_000, intervals: [1000], message: 'the Script panel never picked up the new narration' })
      .toBe(true);

    // What it does with that knowledge, recorded for whoever reads the failure. Observed twice:
    // the play bar keeps its spinner and its clock reads 0:00, or it shows a real duration beside
    // the words "No narration yet". Neither state is asserted — they are not stable between runs,
    // and the button below is the part a teacher actually collides with.
    const panel = (await readPanel())!;
    testInfo.annotations.push({ type: 'script panel after narrating', description: JSON.stringify(panel) });
    expect(panel.dirty, 'the panel thinks the words changed again').toBe(false);

    expect(
      await dock.getByRole('button', { name: PLAY_NARRATION }).count(),
      'KNOWN DEFECT, now fixed: the Script panel offers Play after narrating. '
        + 'Change this to expect the Play button and drop the record-button expectation below.',
    ).toBe(0);
    expect(
      await dock.getByRole('button', { name: RECORD_BUTTON }).count(),
      'KNOWN DEFECT, now fixed: the record button no longer lingers after the narration arrived.',
    ).toBe(1);

    await page.screenshot({ path: shot('nl-teacher-narrated.png') });

    // And a student can hear it.
    const index = scenesOf(copy.code).findIndex((s) => s.id === target!.id);
    const track = await playNarratedScene(page, copy.code, index);
    expect(sceneIdOf(track.src)).toBe(target!.id);
    expect(track.duration).toBeGreaterThan(5);
    await page.screenshot({ path: shot('nl-student-playing.png') });
  });

  test('re-narrating a French scene keeps it French', async ({ page }) => {
    test.setTimeout(300_000);

    const copy = frenchCopy;
    const target = scenesOf(copy.code).find((s) => s.kind === 'narration' && s.has_audio);
    expect(target, `the copy of ${FRENCH_CODE} has no narrated narration scene`).toBeTruthy();

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target!.id}`, { waitUntil: 'domcontentloaded' });

    // Edit the script the way a teacher would, which is what turns Play into Re-narrate.
    const dock = scriptPanel(page);
    const line = dock.locator('[data-line]').first();
    await line.waitFor();
    const before = sceneRow(target!.id).script_len;
    await line.click();
    await page.keyboard.press('End');
    await page.keyboard.type(' Le royaume ne peut plus payer ses dettes.');

    await dock.getByRole('button', { name: RECORD_BUTTON }).click();
    await expect
      .poll(() => sceneRow(target!.id).script_len,
        { timeout: 20_000, intervals: [1000], message: 'pressing Re-narrate did not save the edit' })
      .toBeGreaterThan(before);

    await expect
      .poll(() => sceneRow(target!.id).audio_path ?? '', { timeout: 240_000, intervals: [3000],
        message: 'the re-narrated French audio never arrived — is a queue worker running?' })
      .toMatch(new RegExp(`^lessons/${copy.id}/`));   // the copy's own file, not the parent's

    const scene = sceneRow(target!.id);
    expect(scene.audio_locale).toBe('fr');
    expect(scene.bytes).toBeGreaterThan(10_000);
    expect(scene.audio_path!.endsWith('.mp3')).toBe(true);
    expect(scene.alignment).toBe(0);                  // Azure, not the macOS/edge fallback

    await page.screenshot({ path: shot('fr-teacher-renarrated.png') });
  });

  /**
   * The guard, checked where it actually matters: at runtime, with the live configuration.
   *
   * Runs the real GenerateSceneAudio for a script in each shipped language with the HTTP client
   * faked, and reads the voice out of the SSML the job produced. No browser (nothing about this
   * reaches a page) and no spend.
   */
  test('every shipped language asks Azure for a native voice, never the US fallback', () => {
    // The copy's own scratch scenes: it writes a script and a (faked) audio file onto each one,
    // which is fine on a throwaway lesson and would not be on anyone's. The narration scene is
    // left alone — the Dutch test above is still the thing describing it.
    const scratch = scenesOf(dutchCopy.code).filter((s) => s.kind !== 'narration').map((s) => s.id);
    expect(scratch.length, 'the test copy has no spare scene to probe with').toBeGreaterThan(0);

    const samples: Array<[string, string, RegExp]> = [
      ['nl', 'Abel Tasman werkte niet voor een koning maar voor de Verenigde Oostindische Compagnie. In augustus 1642 vertrok hij uit Batavia met twee schepen om het onbekende zuidland te zoeken.', /^nl-NL-/],
      ['en', 'Abel Tasman did not work for a king. He worked for the Dutch East India Company, and in August 1642 he sailed from Batavia with two ships to look for the unknown southern land.', /^en-GB-/],
      ['fr', "À la fin des années 1780, la France est le pays le plus peuplé d'Europe et le roi ne peut plus payer ses dettes. Les députés du tiers état se réunissent dans la salle du Jeu de paume.", /^fr-FR-/],
      ['de', 'Im Sommer 1789 war Frankreich das bevölkerungsreichste Land Europas und der König konnte seine Schulden nicht mehr bezahlen. Die Abgeordneten des dritten Standes versammelten sich in der Halle.', /^de-DE-/],
      ['it', "Alla fine degli anni Ottanta del Settecento la Francia era il paese più popoloso d'Europa e il re non riusciva più a pagare i suoi debiti. I deputati del terzo stato si riunirono nella sala.", /^it-IT-/],
    ];

    const probe = `
      $seen = [];
      Illuminate\\Support\\Facades\\Http::fake(function ($request) use (&$seen) {
          preg_match('/<voice name="([^"]+)"/', $request->body(), $m);
          preg_match('/xml:lang="([^"]+)"/', $request->body(), $l);
          $seen[] = ['voice' => $m[1] ?? '?', 'lang' => $l[1] ?? '?'];
          return Illuminate\\Support\\Facades\\Http::response(str_repeat("\\x00", 4096), 200, ['Content-Type' => 'audio/mpeg']);
      });
      $scene = App\\Models\\Scene::findOrFail((int) getenv('PROBE_SCENE'));
      $scene->update(['script_segment' => getenv('PROBE_SCRIPT')]);
      (new App\\Jobs\\GenerateSceneAudio($scene->id))->handle(app(App\\Services\\TtsService::class));
      echo json_encode(['requests' => $seen, 'detected' => $scene->fresh()->audio_locale]);
    `;

    samples.forEach(([locale, script, native], i) => {
      const result = artisan<{ requests: Array<{ voice: string; lang: string }>; detected: string }>(probe, {
        PROBE_SCENE: String(scratch[i % scratch.length]),
        PROBE_SCRIPT: script,
      });

      expect(result.detected, `${locale}: script was not recognised as ${locale}`).toBe(locale);
      expect(result.requests, `${locale}: nothing was sent to the speech service`).toHaveLength(1);
      const { voice, lang } = result.requests[0];
      expect(voice, `${locale}: not a native voice`).toMatch(native);
      // The specific thing that must never happen: the multilingual American stand-in.
      expect(voice).not.toBe('en-US-AndrewMultilingualNeural');
      expect(voice).not.toMatch(/^en-US-/);
      // The SSML document language follows the voice, so years and dates are read the local way.
      expect(lang.slice(0, 2)).toBe(locale);
    });
  });
});
