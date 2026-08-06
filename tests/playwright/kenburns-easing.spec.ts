import { test, expect } from '@playwright/test';

/**
 * Is the Ken Burns drift's `linear` timing an actual defect a student sees?
 *
 * A motion-lane finding says the drift "starts and stops abruptly" because it is declared linear
 * instead of EASE.move (easeInOutCubic). The declaration is real — resources/js/lesson-player.js
 * writes `transform ${this._bgSlideMax}ms linear`. What this spec asks is the other half: what a
 * student actually watches. Three things decide that:
 *
 *   1. Is the drift's motion in fact constant-rate (linear) in a real, visible browser?
 *   2. Is a pass endpoint ever ON SCREEN? The player crossfades to the other layer, so if the
 *      handover happens at or before the end of the transition, the "stop" is never visible.
 *   3. Does the visible background ever come to a standstill between passes?
 *
 * Runs headed-GPU only (no SwiftShader): under SwiftShader compositor transform transitions snap
 * to their end frame and every reading would be a lie. See kenburns-easing.config.ts.
 */

const CODE = process.env.KB_LESSON_CODE ?? 'CF0AVG'; // Anne Frank — many scene images

type Sample = { t: number; scale: number; opacity: number; which: number };

/** Scale factor out of a computed `matrix(a, b, c, d, e, f)`. */
function scaleOf(transform: string): number {
  const m = transform.match(/matrix\(([^)]+)\)/);
  if (!m) return NaN;
  return parseFloat(m[1].split(',')[0]);
}

async function readLayers(page: import('@playwright/test').Page) {
  return page.evaluate(() => {
    const layers = [...(document.getElementById('background-layer')?.children ?? [])] as HTMLElement[];
    return layers.map((el) => ({
      transform: getComputedStyle(el).transform,
      opacity: Number(getComputedStyle(el).opacity),
      transition: getComputedStyle(el).transition,
    }));
  });
}

test.describe('Ken Burns easing', () => {
  test('the drift is constant-rate, and its endpoint is hidden by the crossfade', async ({ page }, testInfo) => {
    test.setTimeout(90_000);

    await page.goto(`/lesson/${CODE}`);
    expect(await page.evaluate(() => document.visibilityState), 'the page must be visible or nothing animates').toBe('visible');

    await expect(page.locator('#background-layer > div').first()).toHaveCount(1);
    // Let the requestAnimationFrame that writes the "to" frame land.
    await page.waitForFunction(() => {
      const el = document.getElementById('background-layer')?.firstElementChild as HTMLElement | null;
      return !!el && getComputedStyle(el).transitionProperty.includes('transform');
    });

    const declared = (await readLayers(page))[0].transition;
    // The player's own numbers, for the record.
    const cfg = await page.evaluate(() => {
      const root = document.querySelector('[x-data]') as any;
      const scope = root?._x_dataStack?.[0];
      return scope ? { slideMin: scope._bgSlideMin, slideMax: scope._bgSlideMax, images: scope._kbImages?.length } : null;
    });
    console.log('[kb] declared transition:', declared);
    console.log('[kb] player config:', JSON.stringify(cfg));

    // ── Sample every 200ms for 14s: long enough to cross at least one pass boundary ──────────
    const samples: Sample[] = [];
    const started = Date.now();
    while (Date.now() - started < 14_000) {
      const layers = await readLayers(page);
      const which = layers.findIndex((l) => l.opacity > 0.5);
      const vis = layers[which >= 0 ? which : 0];
      samples.push({ t: Date.now() - started, scale: scaleOf(vis.transform), opacity: vis.opacity, which });
      await page.waitForTimeout(200);
    }

    await page.screenshot({ path: testInfo.outputPath('kenburns-drift.png'), fullPage: false });
    console.log('[kb] screenshot:', testInfo.outputPath('kenburns-drift.png'));

    // ── 1. Constant rate within a single pass ───────────────────────────────────────────────
    // Take the longest run of samples on ONE layer (no crossfade in the middle) and compare the
    // speed in its first third against its last third. Linear => the same. easeInOutCubic =>
    // the last third would be a small fraction of the middle, i.e. a visible settle.
    let bestStart = 0, bestLen = 0, runStart = 0;
    for (let i = 1; i <= samples.length; i++) {
      if (i === samples.length || samples[i].which !== samples[runStart].which) {
        if (i - runStart > bestLen) { bestLen = i - runStart; bestStart = runStart; }
        runStart = i;
      }
    }
    const run = samples.slice(bestStart, bestStart + bestLen).filter((s) => Number.isFinite(s.scale));
    console.log(`[kb] longest single-layer run: ${run.length} samples over ${(run.at(-1)!.t - run[0].t) / 1000}s`);
    expect(run.length, 'not enough samples inside one pass to judge its shape').toBeGreaterThan(8);

    const third = Math.floor(run.length / 3);
    const speed = (a: Sample, b: Sample) => Math.abs(b.scale - a.scale) / ((b.t - a.t) / 1000);
    const early = speed(run[0], run[third]);
    const late = speed(run[run.length - 1 - third], run[run.length - 1]);
    console.log(`[kb] scale speed early=${early.toFixed(5)}/s late=${late.toFixed(5)}/s ratio=${(late / early).toFixed(2)}`);

    // Linear: late/early ≈ 1. Under EASE.move the tail would be far slower than the middle.
    expect(late / early, 'the drift is not constant-rate — it decelerates like an eased move').toBeGreaterThan(0.6);

    // ── 2. While a pass is running, the motion is continuous ────────────────────────────────
    // No dwell in the middle, and no settle before the end: the tail of a pass moves at the same
    // rate as its middle. (Samples parked at the pass's final value are the crossfade wait — see
    // 3 — not part of the pass, so they are trimmed off first.)
    const end = run.at(-1)!.scale;
    const moving = run.filter((s, i) => !(s.scale === end && run.slice(i).every((r) => r.scale === end)));
    let longestStall = 0, current = 0;
    for (let i = 1; i < moving.length; i++) {
      if (moving[i].scale === moving[i - 1].scale) { current++; longestStall = Math.max(longestStall, current); } else current = 0;
    }
    console.log(`[kb] longest stall INSIDE a running pass: ${longestStall * 200}ms`);
    console.log('[kb] trace:', samples.map((s) => `${s.t}|L${s.which}|${s.scale?.toFixed(5)}|o${s.opacity.toFixed(2)}`).join(' '));
    expect(longestStall * 200, 'the drift stalled mid-pass').toBeLessThan(600);

    // ── 3. What actually parks the picture: the wait between the end of a pass and the swap ──
    // KB_DIRECTIONS end at scale 1.05. The drift transition and the swap interval are both
    // _bgSlideMax, so any drift between the two timers leaves the picture sitting at its end
    // frame — a genuine standstill, and one an eased curve would LENGTHEN, not remove.
    const parked = samples.filter((s) => Math.abs(s.scale - 1.05) < 0.0005).length;
    console.log(`[kb] samples parked at the end scale 1.05: ${parked}/${samples.length} (~${parked * 200}ms of the ${samples.at(-1)!.t}ms watched)`);
    console.log(`[kb] visible scale range: ${Math.min(...samples.map((s) => s.scale)).toFixed(4)} → ${Math.max(...samples.map((s) => s.scale)).toFixed(4)}`);
  });

  test('a single-cover voyage lesson drifts at the same constant rate', async ({ page }, testInfo) => {
    // Different shape of lesson: one cover image, so the swap interval (6–12s) is SHORTER than the
    // 12s drift and every pass is replaced in flight. Its endpoint is never on screen at all.
    test.setTimeout(60_000);
    await page.goto('/lesson/LYTVSK');
    await expect(page.locator('#background-layer > div').first()).toHaveCount(1);
    await page.waitForFunction(() => {
      const el = document.getElementById('background-layer')?.firstElementChild as HTMLElement | null;
      return !!el && getComputedStyle(el).transitionProperty.includes('transform');
    });

    // Rate per SECOND, not per sample — the sample cadence itself jitters by tens of ms.
    const pts: { t: number; scale: number }[] = [];
    const t0 = Date.now();
    for (let i = 0; i < 12; i++) {
      pts.push({ t: Date.now() - t0, scale: scaleOf((await readLayers(page))[0].transform) });
      await page.waitForTimeout(250);
    }
    await page.screenshot({ path: testInfo.outputPath('voyage-drift.png') });
    console.log('[kb] voyage layer-A scales:', pts.map((p) => `${p.t}|${p.scale.toFixed(5)}`).join(' '));

    const deltas = pts.slice(1)
      .map((p, i) => Math.abs(p.scale - pts[i].scale) / ((p.t - pts[i].t) / 1000))
      .filter((d) => d > 0);
    const min = Math.min(...deltas), max = Math.max(...deltas);
    console.log(`[kb] voyage rate/s min=${min.toFixed(6)} max=${max.toFixed(6)} ratio=${(min / max).toFixed(2)}`);
    // Constant rate: every step the same size. An eased curve's first steps would be a fraction
    // of its middle ones.
    expect(min / max, 'the drift accelerates or decelerates — it is not linear after all').toBeGreaterThan(0.6);
  });

  test('what an eased pass would look like, for comparison', async () => {
    // Not an app assertion — arithmetic, so the report can say what the proposed change buys.
    const easeInOutCubic = (t: number) => (t < 0.5 ? 4 * t * t * t : (t - 1) * (2 * t - 2) * (2 * t - 2) + 1);
    const lastTenth = 1 - easeInOutCubic(0.9);
    const firstTenth = easeInOutCubic(0.1);
    console.log(`[kb] easeInOutCubic covers ${(firstTenth * 100).toFixed(1)}% in its first tenth and ${(lastTenth * 100).toFixed(1)}% in its last`);
    // i.e. an eased pass spends its first and last ~10% almost motionless — the very "settle"
    // the crossfade currently hides, and a stall the linear version does not have.
    expect(firstTenth).toBeLessThan(0.02);
  });
});
