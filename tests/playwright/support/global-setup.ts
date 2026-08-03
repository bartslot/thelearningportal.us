/**
 * Resolve test fixtures from the live catalogue, once, before any spec runs.
 *
 * The suite used to hardcode lesson ids and codes — `/teacher/lessons/3/wizard`, `/lesson/EWQ7RV`.
 * Those are properties of one particular database, not of the app: ids come from a sequence and
 * codes are generated at random, so rebuilding the catalogue renamed every fixture and 31 specs
 * failed at once. Nothing was broken; the tests were pinned to a snapshot that no longer existed.
 *
 * So we ask the app what it actually has. The public catalogue manifest already reports each
 * lesson's id, code and formats, which is exactly the question a spec is really asking — "give me
 * a voyage lesson" — expressed as data rather than as a magic number.
 *
 * Every value is exported through the env vars the specs already read, so this needs no change in
 * the specs that were written with an override in mind.
 */
import type { FullConfig } from '@playwright/test';
import { request } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * A fresh, throwaway CHILD of a real lesson for the specs to edit.
 *
 * The voyage specs write — they drag waypoints, reorder itineraries, repaint fog. Run against a
 * teacher's own lesson, a test run rewrites their work (it did, once: a drag test swapped two
 * stops). So the real lesson is the PARENT and stays untouched; every run copies it and the specs
 * edit the copy. Set VOYAGE_PARENT_LESSON_ID to the lesson to inherit from.
 */
function freshChildOf(parent: string): { id: number; code: string } | null {
  try {
    const out = execFileSync('php', ['artisan', 'lessons:test-copy', parent, '--json'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    const line = out.trim().split('\n').filter(Boolean).pop() ?? '';
    const child = JSON.parse(line) as { id: number; code: string };

    return child?.id ? child : null;
  } catch (e) {
    // A missing parent or an unreachable database is worth saying out loud — but not worth
    // failing the whole suite over, since the catalogue fixtures below may still resolve.
    console.warn(`[fixtures] could not refresh the test copy of lesson ${parent}: ${(e as Error).message}`);

    return null;
  }
}

type Lesson = {
  id: number;
  code: string;
  title: string;
  status: string;
  taxonomy?: { formats?: string[] };
  counts?: { scenes?: number };
};

const scenes = (l: Lesson) => l.counts?.scenes ?? 0;
const hasFormat = (l: Lesson, f: string) => (l.taxonomy?.formats ?? []).includes(f);

/**
 * Pick by lowest id, NOT by scene count.
 *
 * The wizard specs edit the lesson they open — adding text boxes, adding and deleting map blocks —
 * so scene counts drift as the suite runs. Ranking on them made the choice depend on the previous
 * run, and two runs of the same suite picked different lessons. Id is stable.
 */
const pick = (ls: Lesson[]) => [...ls].sort((a, b) => a.id - b.id)[0];

/**
 * The id of a lesson's first voyage scene, read from the player page's inlined payload.
 *
 * A voyage lesson does not necessarily OPEN on its voyage: the Punic Wars starts with a
 * "what do you already know?" quiz, so a spec that lands on the wizard's first scene and waits
 * for a map waits forever. The specs need the scene, not just the lesson, so we deep-link with
 * ?scene= — the same parameter the wizard's own deep-link spec covers.
 */
async function firstVoyageSceneId(ctx: Awaited<ReturnType<typeof request.newContext>>, code: string) {
  const html = await (await ctx.get(`/lesson/${code}`)).text();
  const start = html.indexOf('"scenes":[');
  if (start === -1) return null;

  // Scan to the matching bracket rather than regexing: scene configs contain nested arrays,
  // and a lazy match would stop at the first inner "]".
  let depth = 0;
  let end = -1;
  for (let i = html.indexOf('[', start); i < html.length; i++) {
    if (html[i] === '[') depth++;
    else if (html[i] === ']' && --depth === 0) { end = i + 1; break; }
  }
  if (end === -1) return null;

  try {
    const parsed = JSON.parse(html.slice(html.indexOf('[', start), end)) as Array<{ id: number; kind: string }>;
    return parsed.find((s) => s.kind === 'voyage')?.id ?? null;
  } catch {
    return null;   // payload shape changed — fall back to the plain wizard URL
  }
}

export default async function globalSetup(config: FullConfig) {
  const baseURL = process.env.PW_BASE_URL ?? config.projects[0]?.use?.baseURL ?? 'http://localhost:8000';

  const ctx = await request.newContext({ baseURL });
  let lessons: Lesson[] = [];
  try {
    const res = await ctx.get('/api/v1/catalog/manifest');
    if (!res.ok()) throw new Error(`manifest returned ${res.status()}`);
    lessons = (await res.json()).lessons ?? [];
  } catch (e) {
    // Fail here with the real reason. Left to run, every fixture-dependent spec would instead
    // time out waiting for UI on a page that never loaded — 30 failures describing a symptom.
    throw new Error(
      `Playwright global setup: could not read the catalogue manifest from ${baseURL}.\n` +
      `Is the dev server up and the database reachable?\n  ${(e as Error).message}`
    );
  }

  const published = lessons.filter((l) => l.status === 'published' && scenes(l) > 0);
  if (!published.length) {
    throw new Error(
      'Playwright global setup: the catalogue has no published lesson with scenes to test against.\n' +
      'Run `php artisan lessons:compose` to build one.'
    );
  }

  // A voyage lesson pinned by hand wins outright — checked BEFORE the throw, and without needing
  // the catalogue, which lists published lessons only. A teacher's own voyage is usually mid-edit
  // rather than published, and refusing to run the suite over that (or, worse, publishing their
  // lesson to make the tests happy) is not the harness's call to make.
  // A parent lesson wins over everything: the specs get a fresh copy of it and edit that.
  const parentId = process.env.VOYAGE_PARENT_LESSON_ID;
  const child = parentId ? freshChildOf(parentId) : null;
  if (child) {
    console.log(`[fixtures] voyage → a fresh copy of #${parentId}: #${child.id} ${child.code}`);
  }

  const pinnedId = child ? String(child.id) : process.env.VOYAGE_LESSON_ID;
  const pinnedVoyage: Lesson | undefined = pinnedId
    ? lessons.find((l) => String(l.id) === pinnedId)
      ?? ({ id: Number(pinnedId), code: child?.code ?? process.env.VOYAGE_LESSON_CODE ?? '', title: 'pinned' } as Lesson)
    : undefined;
  const voyage = pinnedVoyage ?? pick(published.filter((l) => hasFormat(l, 'voyage')));
  if (!voyage) {
    throw new Error(
      'Playwright global setup: no published VOYAGE lesson found — the voyage specs have nothing to open.\n' +
      'Run `php artisan lessons:make-voyage` to build one, or pin one with VOYAGE_LESSON_ID=<id>.'
    );
  }

  // A general-purpose lesson for the player/wizard specs. Prefer a non-voyage one so those specs
  // exercise the ordinary narrated path rather than accidentally testing voyages twice.
  const general = pick(published.filter((l) => !hasFormat(l, 'voyage'))) ?? voyage;

  // Deep-link straight to the scene editor AND to the voyage scene. Both halves matter: without
  // step=4 the wizard resumes wherever the lesson last was — for a finished lesson that is step 1,
  // which has no scene rail at all — and without ?scene= a voyage lesson can open on its quiz.
  const voyageScene = await firstVoyageSceneId(ctx, voyage.code);
  await ctx.dispose();
  const voyageWizard = `/teacher/lessons/${voyage.id}/wizard?step=4`
    + (voyageScene ? `&scene=${voyageScene}` : '');
  if (!voyageScene) {
    console.warn(`[fixtures] no voyage scene found in ${voyage.code}; specs will open its first scene`);
  }

  // An explicit env var always wins, so CI (or a developer chasing one lesson) can still pin a
  // fixture by hand without editing anything.
  const preset = (key: string, value: string) => { process.env[key] ??= value; };

  preset('VOYAGE_WIZARD_URL', voyageWizard);
  preset('VOYAGE_LESSON_CODE', voyage.code);
  preset('VOYAGE_LESSON_ID', String(voyage.id));
  preset('PLAYER_LESSON_CODE', general.code);
  preset('PW_LESSON_ID', String(general.id));
  preset('PW_LESSON_CODE', general.code);
  preset('WIZARD_URL', `/teacher/lessons/${general.id}/wizard`);

  console.log(
    `[fixtures] voyage → #${voyage.id} ${voyage.code} "${voyage.title}" (${scenes(voyage)} scenes)\n` +
    `[fixtures] lesson → #${general.id} ${general.code} "${general.title}" (${scenes(general)} scenes)`
  );
}
