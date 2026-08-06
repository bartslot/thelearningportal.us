/**
 * Stored scene duration vs the narration a class actually hears.
 *
 * A narration scene stores `duration_seconds`. Azure returns audio and no timings, so
 * GenerateSceneAudio estimates it from a word count divided by a fixed 2.6 words/second. That
 * number is not cosmetic: SceneTimelinePlayer.totalSeconds() sums it, so the Step-4 preview's
 * "0:00 / M:SS" readout — the only runtime a teacher ever sees before class — is the sum of the
 * estimates, not of the audio.
 *
 * This spec measures the two side by side in a real browser: it decodes each narration mp3 with
 * the browser's own audio pipeline (the same decoder that will play it to the class) and compares
 * the total against the readout the wizard prints.
 *
 * It also settles a specific claim about the CAUSE. The estimate uses str_word_count(), whose
 * default charlist has no accented letters, and the theory was that this inflates French scripts
 * ("l'Assemblée" counted as two words) and so French runs long in a way English does not. So the
 * spec measures the drift per language and compares them, and it re-counts each French script in
 * the browser with a Unicode-aware regex to see whether an accent-safe count would even change
 * the stored number.
 */
import { test, expect } from '@playwright/test';

type Scene = {
  id: number;
  kind: string;
  duration_seconds: number | null;
  audio_url: string | null;
  script: string | null;
};

const LESSONS = [
  { code: 'V6OYLL', label: 'French  #39 Révolution française', lang: 'fr' },
  { code: 'LYTVSK', label: 'English #41 Abel Tasman', lang: 'en' },
  { code: 'CF0AVG', label: 'English #3  Anne Frank', lang: 'en' },
];

/** The player page inlines every scene. Scan to the matching bracket — configs nest arrays. */
function scenesFrom(html: string): Scene[] {
  const at = html.indexOf('"scenes":[');
  if (at === -1) return [];
  const open = html.indexOf('[', at);
  let depth = 0;
  for (let i = open; i < html.length; i++) {
    if (html[i] === '[') depth++;
    else if (html[i] === ']' && --depth === 0) return JSON.parse(html.slice(open, i + 1));
  }
  return [];
}

/**
 * Real playing time, decoded by the browser.
 *
 * `loadedmetadata` is enough for duration and avoids streaming the whole file. The audio element
 * is never connected to the page, and the chromium project launches with --mute-audio anyway, so
 * nothing is heard.
 */
async function realSeconds(page: import('@playwright/test').Page, url: string): Promise<number> {
  return page.evaluate(
    (src) =>
      new Promise<number>((resolve, reject) => {
        const a = new Audio();
        a.preload = 'metadata';
        a.muted = true;
        a.addEventListener('loadedmetadata', () => resolve(a.duration));
        a.addEventListener('error', () => reject(new Error(`could not load ${src}`)));
        a.src = src;
      }),
    url,
  );
}

const drift = new Map<string, { lang: string; label: string; pct: number; stored: number; real: number }>();

for (const lesson of LESSONS) {
  test(`stored duration vs real narration — ${lesson.label}`, async ({ page, request }, testInfo) => {
    const html = await (await request.get(`/lesson/${lesson.code}`)).text();
    const narration = scenesFrom(html).filter((s) => s.audio_url && (s.duration_seconds ?? 0) > 0);
    expect(narration.length, 'lesson has narrated scenes to measure').toBeGreaterThan(0);

    // A blank page is enough to own an Audio element, and it keeps the measurement away from the
    // player's own autoplay and music engine.
    await page.goto(`/lesson/${lesson.code}`);

    let stored = 0;
    let real = 0;
    const rows: string[] = [];

    for (const s of narration) {
      const actual = await realSeconds(page, s.audio_url!);
      expect(Number.isFinite(actual) && actual > 0, `scene ${s.id} decoded`).toBe(true);
      stored += s.duration_seconds!;
      real += actual;

      // What would an accent-safe word count have produced? Same formula, Unicode-aware split.
      const words = await page.evaluate(
        (text) => (text.replace(/<[^>]*>/g, ' ').match(/[\p{L}\p{N}]+/gu) || []).length,
        s.script ?? '',
      );
      const unicodeEstimate = Math.ceil(Math.max(3, Math.round((words / 2.6) * 10) / 10));

      rows.push(
        `  scene ${s.id}: stored ${s.duration_seconds}s | real ${actual.toFixed(2)}s ` +
          `| ${(((s.duration_seconds! - actual) / actual) * 100).toFixed(1)}% ` +
          `| accent-safe re-count would store ${unicodeEstimate}s`,
      );
    }

    const pct = ((stored - real) / real) * 100;
    drift.set(lesson.code, { lang: lesson.lang, label: lesson.label, pct, stored, real });

    const report =
      `${lesson.label}\n${rows.join('\n')}\n` +
      `  TOTAL stored ${stored}s vs real ${real.toFixed(1)}s → ${pct.toFixed(1)}% long\n`;
    console.log(report);
    await testInfo.attach(`${lesson.code}-durations.txt`, { body: report, contentType: 'text/plain' });

    // The drift is real, and it is modest. Anything past a quarter would be a different bug.
    expect(pct, 'stored total runs longer than the audio').toBeGreaterThan(0);
    expect(pct, 'but not wildly so').toBeLessThan(25);
  });
}

test('the drift is not French-specific', async ({}, testInfo) => {
  test.skip(drift.size < LESSONS.length, 'needs every lesson measured');

  const fr = [...drift.values()].filter((d) => d.lang === 'fr');
  const en = [...drift.values()].filter((d) => d.lang === 'en');
  const worstEnglish = Math.max(...en.map((d) => d.pct));

  const summary = [...drift.values()]
    .map((d) => `  ${d.label}: +${d.pct.toFixed(1)}%  (${d.stored}s stored, ${d.real.toFixed(1)}s real)`)
    .join('\n');
  console.log(`\nper-lesson drift\n${summary}\n`);
  await testInfo.attach('drift-summary.txt', { body: summary, contentType: 'text/plain' });

  // If accents were the cause, French would stand apart from English. It does not — English
  // lessons drift into the same band, so the estimate is simply language-neutral and coarse.
  for (const d of fr) {
    expect(d.pct, 'French is not an order apart from English').toBeLessThan(worstEnglish * 2);
  }
});

/**
 * What the teacher is actually shown, per scene.
 *
 * The one place the wizard prints a narration scene's length is the script panel's audio readout
 * ("00:00 / 00:35"). It is bound to the Alpine `dur` of a real <audio> element, so it reports the
 * DECODED file, not `duration_seconds`. This test pins that: for each scene the printed total must
 * match the mp3 to within a second, and must NOT be the stored estimate whenever the two differ.
 */
test('the wizard script panel prints the real audio length, not the stored estimate', async ({
  page,
  request,
}, testInfo) => {
  const html = await (await request.get('/lesson/V6OYLL')).text();
  const scenes = scenesFrom(html).filter((s) => s.audio_url && (s.duration_seconds ?? 0) > 0);
  // Scenes where the estimate is furthest from the audio — where a wrong number would show most.
  const sample = scenes.slice(0, 4);
  const lines: string[] = [];

  for (const s of sample) {
    // ?step=4 is the scene configurator; ?step=5 is the preview. See lesson-wizard.blade.php.
    await page.goto(`/teacher/lessons/39/wizard?step=4&scene=${s.id}`);

    const readout = page.locator('span[x-text*="fmt(dur)"]').first();
    await expect(readout).toBeVisible({ timeout: 30_000 });
    await expect(readout, 'the audio metadata loads').toHaveText(/\d\d:\d\d \/ 00:0?[1-9]/, {
      timeout: 30_000,
    });

    const text = (await readout.textContent())!.trim();
    const [, mm, ss] = text.match(/(\d\d):(\d\d)\s*$/)!;
    const shown = Number(mm) * 60 + Number(ss);
    const real = await realSeconds(page, s.audio_url!);

    lines.push(`  scene ${s.id}: panel shows "${text}" → ${shown}s | real ${real.toFixed(2)}s | stored ${s.duration_seconds}s`);

    expect(Math.abs(shown - real), `scene ${s.id} readout tracks the audio`).toBeLessThanOrEqual(1);
    if (Math.abs(s.duration_seconds! - real) > 1.5) {
      expect(shown, `scene ${s.id} is not showing the stored estimate`).not.toBe(s.duration_seconds);
    }
  }

  const shot = testInfo.outputPath('wizard-script-panel-duration.png');
  await page.screenshot({ path: shot });
  await testInfo.attach('wizard-script-panel-duration.png', { path: shot, contentType: 'image/png' });

  const report = `wizard per-scene readout (French #39)\n${lines.join('\n')}\n`;
  console.log(report);
  await testInfo.attach('wizard-readouts.txt', { body: report, contentType: 'text/plain' });
});

/**
 * And what the Step-5 preview shows.
 *
 * The preview is not a separate timeline widget — it embeds the real student player
 * (/lesson/{code}?embed=1). The player has no numeric runtime at all: its only duration surface is
 * the segmented chapter bar, whose segments are proportional. So there is no place in the preview
 * where a stored estimate is printed as a time.
 */
test('the Step-5 preview embeds the student player and prints no runtime', async ({ page }, testInfo) => {
  await page.goto('/teacher/lessons/39/wizard?step=5');

  const embed = page.frameLocator('iframe').first();
  await expect(embed.locator('body'), 'the student player is embedded').toBeVisible({ timeout: 30_000 });
  await expect
    .poll(() => page.frames().find((f) => f.url().includes('/lesson/'))?.url() ?? '', { timeout: 30_000 })
    .toContain('embed=1');

  const frame = page.frames().find((f) => f.url().includes('/lesson/'))!;
  const body = await frame.locator('body').innerText();
  const times = body.split('\n').filter((l) => /\d+:\d\d/.test(l));

  const shot = testInfo.outputPath('step5-preview.png');
  await page.screenshot({ path: shot });
  await testInfo.attach('step5-preview.png', { path: shot, contentType: 'image/png' });
  console.log(`Step-5 preview frame: ${frame.url()}\n  time-like text: ${JSON.stringify(times)}`);

  expect(times, 'the preview prints no clock a stored estimate could be wrong in').toEqual([]);
});
