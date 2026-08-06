/**
 * Narrate, when the queue worker is up — and what the teacher sees when it is not.
 *
 * This spec exists to settle a claim: that "no queue worker was running, so narration never
 * generated at all, in any language". That is a statement about a developer's laptop, not about
 * the application — `composer dev` starts `queue:work` as one of four concurrently children, and
 * one of those children dying leaves the app perfectly correct and the machine half-configured.
 *
 * So the spec asks the two questions that actually matter to a teacher:
 *
 *   1. WITH the documented dev environment running, does pressing Narrate in the wizard really
 *      come back with a recording? (Test 1, a real browser, a real TTS call, a real audio file.)
 *
 *   2. WITHOUT anything coming back — the exact "worker is dead" shape, reproduced here by
 *      dropping the Livewire request on the floor so no job is ever queued — does the wizard
 *      hang for ever, or does it hand the button back and say so? (Test 2.)
 *
 * Everything is done to a THROWAWAY copy of a published lesson, never a teacher's own work.
 * Spend: one real text-to-speech call per run.
 *
 *   npx playwright test narration-queue-worker --project=chromium \
 *     --output=tests/playwright/results-narration-worker
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

/** Passing tests get their output directory swept, and the whole point here is to keep the proof. */
const SHOTS = process.env.WORKER_SHOT_DIR ?? 'tests/playwright/results-narration-worker/shots';
const shot = (name: string) => path.join(SHOTS, name);
mkdirSync(SHOTS, { recursive: true });

/** The lesson to inherit from. A code, not an id — both are database-local, this one is overridable. */
const PARENT_CODE = process.env.WORKER_PARENT_CODE ?? 'LYTVSK';

function artisan<T>(code: string): T {
  const out = execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  const line = out.trim().split('\n').reverse().find((l) => l.trim().startsWith('{') || l.trim().startsWith('['));
  if (!line) throw new Error(`artisan returned no JSON:\n${out}`);
  return JSON.parse(line.trim()) as T;
}

type SceneRow = { id: number; kind: string; audio_path: string | null; audio_locale: string | null; status: string };

const scenesOf = (lessonId: number) =>
  artisan<SceneRow[]>(
    `echo json_encode(App\\Models\\Scene::where('lesson_id',${lessonId})->orderBy('order')` +
      `->get(['id','kind','status','audio_path','audio_locale']));`,
  );

const sceneRow = (id: number) =>
  artisan<SceneRow>(
    `echo json_encode(App\\Models\\Scene::where('id',${id})->first(['id','kind','status','audio_path','audio_locale']));`,
  );

/** Is a queue worker alive? A precondition of the environment, checked out loud so a dead worker
 *  reads as "your machine is not set up" rather than as "the feature is broken". */
function workerIsRunning(): boolean {
  try {
    const out = execFileSync('bash', ['-lc', "ps ax -o command | grep 'queue:work' | grep -v grep || true"], {
      encoding: 'utf8',
    });
    return out.trim().length > 0;
  } catch {
    return false;
  }
}

/** The record control, in every shipped language — the wizard chrome follows the teacher's locale,
 *  and a run that leaves the account in Dutch must not make the next run "find no button". */
const RECORD_BUTTON =
  /^(Narrate|Re-narrate|Narrating…|Inspreken|Opnieuw inspreken|Bezig met inspreken…|Enregistrer la voix|Renarrer|Vertonen|Neu vertonen|Registra la voce|Rinarra)$/i;
const scriptPanel = (page: Page) => page.locator('[x-data^="scriptEditor"]');

/** Read the script panel's own state straight out of Alpine — the button label alone cannot tell
 *  "spinning" from "finished and still labelled Re-narrate". */
async function panelState(page: Page) {
  return page.evaluate(() => {
    const el = [...document.querySelectorAll('[x-data]')].find((n) =>
      (n.getAttribute('x-data') || '').startsWith('scriptEditor'),
    ) as (HTMLElement & { _x_dataStack?: Array<Record<string, unknown>> }) | undefined;
    const d = el?._x_dataStack?.[0] as
      | { regenerating: boolean; hasAudio: boolean; dirty: boolean; sceneId: number | null; _narrateTimer: unknown }
      | undefined;
    if (!d) return null;
    return {
      regenerating: !!d.regenerating,
      hasAudio: !!d.hasAudio,
      dirty: !!d.dirty,
      sceneId: d.sceneId,
      guardArmed: d._narrateTimer != null,
    };
  });
}

let copy: { id: number; code: string };

test.beforeAll(() => {
  const out = execFileSync('php', ['artisan', 'lessons:test-copy', PARENT_CODE, '--json'], { encoding: 'utf8' });
  copy = JSON.parse(out.trim().split('\n').filter(Boolean).pop()!);
  expect(copy.id, `could not copy lesson ${PARENT_CODE}`).toBeGreaterThan(0);
});

test.describe('Narration and the queue worker', () => {
  test('a teacher presses Narrate and a recording comes back', async ({ page }, testInfo) => {
    test.skip(!workerIsRunning(), 'No queue:work process — start `composer dev` first. Environment, not app.');
    test.setTimeout(240_000);

    const target = scenesOf(copy.id).find((s) => s.kind === 'narration');
    expect(target, 'the copy has no narration scene to record').toBeTruthy();

    // Wipe the inherited recording so this is a FIRST narration, the state the claim describes:
    // "nothing is ever produced". A scene with no audio shows a labelled Narrate button.
    artisan<SceneRow>(
      `$s = App\\Models\\Scene::find(${target!.id});` +
        `$s->forceFill(['audio_path'=>null,'audio_locale'=>null])->save();` +
        `echo json_encode($s->only(['id','kind','status','audio_path','audio_locale']));`,
    );
    expect(sceneRow(target!.id).audio_path, 'precondition: the scene must start with no audio').toBeNull();

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target!.id}`, {
      waitUntil: 'domcontentloaded',
    });

    const dock = scriptPanel(page);
    await expect(dock).toBeVisible({ timeout: 30_000 });
    const record = dock.getByRole('button', { name: RECORD_BUTTON });
    await expect(record).toBeVisible({ timeout: 20_000 });
    await expect
      .poll(async () => (await panelState(page))?.hasAudio, { timeout: 20_000, intervals: [500] })
      .toBe(false);
    await page.screenshot({ path: shot('01-before-narrate.png'), fullPage: false });

    const started = Date.now();
    await record.click();

    // The spinner state a teacher sees while the job is on the queue.
    await expect
      .poll(async () => (await panelState(page))?.regenerating, { timeout: 15_000, intervals: [250] })
      .toBe(true);
    await page.screenshot({ path: shot('02-narrating.png') });

    // The job really ran: the row now has a file and a language. This is the assertion the claim
    // says can never pass.
    await expect
      .poll(() => sceneRow(target!.id).audio_path, {
        timeout: 180_000,
        intervals: [2000],
        message: 'the queued narration job never wrote an audio file',
      })
      .not.toBeNull();

    const elapsed = Math.round((Date.now() - started) / 1000);
    const after = sceneRow(target!.id);
    expect(after.audio_locale, 'the recording has no language').toBeTruthy();

    // The spinner must stop — the state the claim says never ends.
    await expect
      .poll(async () => (await panelState(page))?.regenerating, {
        timeout: 60_000,
        intervals: [500],
        message: 'the Narrate button never stopped spinning even though the audio was written',
      })
      .toBe(false);

    // Whether the OPEN panel adopts the new recording in place is a separate question from whether
    // narration works, and it is racy (the panel can be re-rendered by the wizard's status poll
    // with the server-rendered — still empty — audio url). Record what happened rather than
    // asserting on the race, then reload and require the teacher to be looking at a recording.
    const adoptedInPlace = (await panelState(page))?.hasAudio === true;
    await page.screenshot({ path: shot('03-after-narrating.png') });

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(scriptPanel(page)).toBeVisible({ timeout: 30_000 });
    await expect
      .poll(async () => (await panelState(page))?.hasAudio, {
        timeout: 30_000,
        intervals: [500],
        message: 'the wizard does not show the recording that was written',
      })
      .toBe(true);

    await page.screenshot({ path: shot('04-recording-in-wizard.png') });
    testInfo.annotations.push({
      type: 'narration round trip',
      description:
        `scene ${target!.id}: ${after.audio_path} (${after.audio_locale}) in ~${elapsed}s; ` +
        `open panel adopted it in place: ${adoptedInPlace}`,
    });
  });

  test('when nothing comes back the button is handed back with an error, not left spinning', async ({ page }) => {
    const target = scenesOf(copy.id).find((s) => s.kind === 'narration');
    expect(target, 'no narration scene to record').toBeTruthy();

    await page.goto(`/teacher/lessons/${copy.id}/wizard?step=4&scene=${target!.id}`, {
      waitUntil: 'domcontentloaded',
    });
    const dock = scriptPanel(page);
    await expect(dock).toBeVisible({ timeout: 30_000 });
    await expect
      .poll(async () => (await panelState(page))?.sceneId, { timeout: 30_000, intervals: [500] })
      .toBeTruthy();

    // A scene that already has a recording shows Play, not Re-narrate, until the teacher edits the
    // words. Edit them the way a teacher would.
    if ((await panelState(page))?.hasAudio) {
      const box = dock.locator('[contenteditable]').first();
      await box.click();
      await page.keyboard.type(' Een extra zin voor de test.');
      await expect
        .poll(async () => (await panelState(page))?.dirty, {
          timeout: 15_000,
          intervals: [250],
          message: 'typing in the script never marked the scene as edited',
        })
        .toBe(true);
    }

    // Reproduce the dead-worker shape at its most extreme: the request that would queue the job
    // never reaches the server at all, so nothing can ever come back to the page. Installed only
    // now, so the wizard's own boot round-trips are untouched.
    await page.route('**/livewire/update', (route) => route.abort());

    const record = dock.getByRole('button', { name: RECORD_BUTTON });
    await expect(record).toBeVisible({ timeout: 15_000 });
    await record.click();

    // The guard is armed the moment Narrate is pressed. Without it the panel would sit here for
    // ever — which is exactly what the claim describes, and exactly what this timer prevents.
    await expect
      .poll(async () => (await panelState(page))?.guardArmed, { timeout: 15_000, intervals: [250] })
      .toBe(true);
    expect((await panelState(page))?.regenerating, 'the button should be spinning').toBe(true);

    // Fire the guard's own terminal step rather than waiting out its three minutes: this is the
    // callback the timer runs, so what the teacher ends up looking at is exactly this.
    await page.evaluate(() => {
      const el = [...document.querySelectorAll('[x-data]')].find((n) =>
        (n.getAttribute('x-data') || '').startsWith('scriptEditor'),
      ) as (HTMLElement & { _x_dataStack?: Array<{ narrationFailed: (r: string | null) => void }> }) | undefined;
      el?._x_dataStack?.[0]?.narrationFailed(null);
    });

    // A visible, dismissible error — and the control usable again.
    await expect(
      page
        .getByText(
          /could not record this scene|kon deze scène niet opnemen|konnte diese Szene nicht aufnehmen|n'a pas pu enregistrer cette scène|non è riuscito a registrare questa scena/i,
        )
        .first(),
    ).toBeVisible({ timeout: 10_000 });
    expect((await panelState(page))?.regenerating, 'the spinner never stopped').toBe(false);
    await expect(record).toBeEnabled();
    await page.screenshot({ path: shot('04-narration-failed-toast.png') });
  });
});
