import { test, expect, Page } from '@playwright/test';
import { loginAsTeacher } from './support/auth';

/**
 * The territory information card — Figma node 1462:1886.
 *
 * These tests open the card by dispatching `polity-selected` with a fixture rather than clicking
 * the globe. That is deliberate: clicking a point on a software-rendered MapLibre canvas may land
 * on open ocean, and a test that sometimes opens an empty panel proves nothing about the card. The
 * click path itself is already covered by timemap-click-panel in timemap.spec.ts; what is under
 * test here is what the card DRAWS, given a territory.
 *
 * The fixture's summary is deliberately long — the fade only exists when the body overflows, and a
 * two-line fixture would let the one detail this card is judged on pass without being drawn.
 */

const LONG_SUMMARY = (
  'The Roman Republic was a sister republic of the First French Republic that existed from 1798 '
  + 'to 1799. It was proclaimed on 15 February 1798 after Louis-Alexandre Berthier, a general of '
  + 'the French Revolutionary Army, had occupied the city of Rome on 11 February. It was led by a '
  + 'Directory of five men and comprised territory conquered from the Papal States. '
).repeat(4);

/**
 * The QID lives on the polity-selected EVENT, never in the endpoint's response.
 *
 * This split is load-bearing and it is why FIXTURE below deliberately has no `wikidata_id` key: the
 * json() in routes/web.php returns osm_id, label, summary, flag_path, wikipedia_url, inception,
 * dissolution, predecessor, successor and figures — and nothing else. An earlier version of this
 * fixture invented a `wikidata_id`, the card read it, and the report test passed while every real
 * report would have filed with a null QID. A fixture richer than the endpoint tests the fixture.
 */
const QID = 'Q175881';

const FIXTURE = {
  osm_id: 'r-test-1',
  label: 'Roman Republic',
  summary: LONG_SUMMARY,
  wikipedia_url: 'https://en.wikipedia.org/wiki/Roman_Republic_(18th_century)',
  flag_path: null,
  inception: -500,
  dissolution: 1799,
  predecessor: 'Roman Kingdom',
  successor: 'Roman Empire',
  figures: [
    { qid: 'Q1048', name: 'Julius Caesar', kind: 'ruler', image_url: null, era: '100–44' },
  ],
};

/** Put a territory on screen without touching the globe. */
async function openCard(page: Page, polity: Record<string, unknown> = FIXTURE) {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  await page.evaluate(({ p, qid }) => {
    (window as any).__polityCache = { ...((window as any).__polityCache || {}), [p.osm_id as string]: p };
    // Exactly the detail shape index.js dispatches: id, name, qid. Nothing more.
    window.dispatchEvent(new CustomEvent('polity-selected', {
      detail: { id: p.osm_id, name: p.label, qid },
    }));
  }, { p: polity, qid: QID });
  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeVisible();
}

const card = (page: Page) => page.locator('aside').filter({ hasText: 'Roman Republic' });

test.beforeEach(async ({ page }) => {
  await loginAsTeacher(page);
  await page.goto('/teacher/timemap');
});

test('card-header: title, era as two scrubbable dates, and a close X', async ({ page }) => {
  await openCard(page);

  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeVisible();

  // The Figma sets the era much tighter than the old panel's "500 BCE – 1799 CE".
  await expect(card(page).getByRole('button', { name: '500BCE' })).toBeVisible();
  await expect(card(page).getByRole('button', { name: '1799CE' })).toBeVisible();

  // Both dates scrub the timeline — that is what the underline is promising.
  await card(page).getByRole('button', { name: '500BCE' }).click();
  await page.waitForFunction(() => (window as any).__portal?.year === -500, { timeout: 15_000 });

  await expect(card(page).getByRole('button', { name: 'Close' })).toBeVisible();
});

/**
 * THE ACCENT REGRESSION. The era was built in amber first, because both the brief and the skill
 * file said the accent belonged there. It does not: the map is already the colourful thing, so the
 * card is monochrome and the underline carries the affordance. An absolute value, not a relative
 * one — a test that only compared the era to its neighbours would have passed on the amber build.
 */
test('card-header: the era is white, not an accent colour', async ({ page }) => {
  await openCard(page);

  const colour = await card(page).getByRole('button', { name: '500BCE' })
    .evaluate((el) => getComputedStyle(el).color);
  expect(colour).toBe('rgb(255, 255, 255)');

  const decoration = await card(page).getByRole('button', { name: '500BCE' })
    .evaluate((el) => getComputedStyle(el).textDecorationLine);
  expect(decoration).toContain('underline');
});

/**
 * Two text colours, and which is which. The body reads WHITE — it was #c7d5e3 (Figma's `Menu`
 * variable) until Bart settled it against the frame itself, which is plain white. The section
 * label above it is the muted one, and that contrast is the only hierarchy the card has.
 */
test('card-body: white copy under a muted label, and nothing in between', async ({ page }) => {
  await openCard(page);

  const body = await card(page).locator('[x-ref="body"]')
    .evaluate((el) => getComputedStyle(el).color);
  expect(body).toBe('rgb(255, 255, 255)');

  // The SECTION label — the 11px one. `.lp-card-label` also dresses the 8px era and footer links,
  // which are deliberately white: the class carries type, never colour. That separation is exactly
  // what the @layer components move in brand-kit.css exists to allow.
  const label = await card(page).locator('.lp-card-label:not(.lp-card-label-sm)').first()
    .evaluate((el) => getComputedStyle(el).color);
  expect(label).toBe('rgb(124, 139, 157)');          // --color-card-muted
});

test('card-tabs: the active tab is a raised surface, not an underline', async ({ page }) => {
  await openCard(page);

  const summary = card(page).getByRole('tab', { name: 'Summary' });
  const people = card(page).getByRole('tab', { name: 'People' });

  await expect(summary).toHaveAttribute('aria-selected', 'true');

  // Raised = it has a background of its own. An inactive tab has none.
  const raised = await summary.evaluate((el) => getComputedStyle(el).backgroundColor);
  const flat = await people.evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(raised).toBe('rgb(18, 27, 44)');            // --color-card-surface-raised
  expect(flat).toBe('rgba(0, 0, 0, 0)');

  await people.click();
  await expect(people).toHaveAttribute('aria-selected', 'true');
  await expect(card(page).getByText('Julius Caesar')).toBeVisible();

  await card(page).getByRole('tab', { name: 'Over time' }).click();
  await expect(card(page).getByText('Roman Kingdom')).toBeVisible();
});

/**
 * THE FADE. This is the detail the brief called out as most likely to be dropped, so it is asserted
 * three ways: that it is a mask (not an overlay, which cannot know the colour of the map behind
 * it), that it is ON while there is more to read, and that it goes OFF at the end — a fade that
 * never lifts would leave the last sentence permanently half-dissolved.
 */
test('card-body: the copy fades while there is more, and stops fading at the end', async ({ page }) => {
  await openCard(page);

  const body = card(page).locator('[x-ref="body"]');

  const overflows = await body.evaluate((el) => el.scrollHeight > el.clientHeight + 1);
  expect(overflows, 'fixture must overflow or the fade is untested').toBe(true);

  await expect(body).toHaveClass(/lp-card-fade/);
  const mask = await body.evaluate((el) => getComputedStyle(el).maskImage);
  expect(mask).toContain('gradient');

  // Scroll to the last line: the fade lifts.
  await body.evaluate((el) => { el.scrollTop = el.scrollHeight; el.dispatchEvent(new Event('scroll')); });
  await expect(body).not.toHaveClass(/lp-card-fade/);

  await body.evaluate((el) => { el.scrollTop = 0; el.dispatchEvent(new Event('scroll')); });
  await expect(body).toHaveClass(/lp-card-fade/);
});

test('card-actions: one primary action, and a read-more link', async ({ page }) => {
  await openCard(page);

  await expect(card(page).getByText('Read more on')).toBeVisible();
  const link = card(page).getByRole('link', { name: 'wikipedia.org' });
  await expect(link).toHaveAttribute('href', FIXTURE.wikipedia_url);
  await expect(link).toHaveAttribute('target', '_blank');

  // ONE primary action. A white full-width pill; nothing else on the card is a filled button.
  const create = card(page).getByRole('link', { name: /Create Lesson/ });
  const style = await create.evaluate((el) => {
    const s = getComputedStyle(el);
    return { bg: s.backgroundColor, radius: parseFloat(s.borderRadius), width: el.clientWidth };
  });
  expect(style.bg).toBe('rgb(255, 255, 255)');
  expect(style.radius).toBeGreaterThan(100);          // a pill, not a rounded rectangle
  expect(style.width).toBeGreaterThan(260);           // full width inside the 310px card
});

test('card-footer: report carries the territory, its qid and the year on the slider', async ({ page }) => {
  await openCard(page);

  await page.evaluate(() => {
    (window as any).__reported = null;
    window.addEventListener('timemap-report-requested', (e: any) => { (window as any).__reported = e.detail; });
  });

  await card(page).getByRole('button', { name: 'Report' }).click();

  const detail = await page.evaluate(() => (window as any).__reported);

  // The QID is the field the receiving controller stamps provenance from, and it exists ONLY on
  // the event. If the card ever goes back to reading it off the response, this is null and the
  // report lands unactionable — passing validation the whole way.
  expect(detail.wikidata_qid).toBe(QID);
  expect(detail).toMatchObject({
    label: 'Roman Republic',
    source: 'cliopatria',
  });
  // Null until the hand-authored branch starts putting territoryId on the detail; never undefined,
  // which JSON.stringify would drop from the request body altogether.
  expect(detail).toHaveProperty('territory_id');
  expect(detail.territory_id).toBeNull();
  // The year is the one on the slider at the moment of the click, not a default.
  expect(detail.year).toBe(await page.evaluate(() => Math.round((window as any).__portal.year)));
});

test('card-chrome: 310 wide, and it closes on Escape', async ({ page }) => {
  await openCard(page);

  expect(await card(page).evaluate((el) => el.clientWidth)).toBe(310);

  await page.keyboard.press('Escape');
  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeHidden();
});

/**
 * The flag files are generated rather than committed (/public/flags is gitignored), so a checkout
 * without them is ordinary — every /flags/*.png 404s in a fresh worktree while 581 polities still
 * carry a flag_path. A broken-image glyph sitting in the header is worse than no flag, so a flag
 * that fails to load takes itself out.
 */
test('card-header: a flag that loads is shown, and one that 404s is not', async ({ page }) => {
  const RED_DOT = 'data:image/gif;base64,R0lGODlhAQABAIAAAP8AAAAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==';

  await openCard(page, { ...FIXTURE, flag_path: RED_DOT });
  const flag = card(page).locator('img[alt=""]');
  await expect(flag).toBeVisible();
  expect(await flag.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);

  await page.keyboard.press('Escape');
  await openCard(page, { ...FIXTURE, osm_id: 'r-test-2', flag_path: '/flags/does-not-exist.png' });
  await expect(card(page).locator('img[alt=""]')).toHaveCount(0);
  // The title still sits where it should, rather than beside an empty hole.
  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeVisible();
});

/**
 * CLOSING UNDOES THE WHOLE CLICK, not just the panel.
 *
 * Clicking a territory does three things: it opens the card, it lights the territory (a MapLibre
 * feature-state) and it re-ranks the labels so the chosen name wins its collisions. `polity = null`
 * undid none of them, so closing left the territory lit with nothing on screen to explain it — a
 * highlight with no card reads as a stuck map. Both close routes must call both hooks.
 *
 * Asserted through the hooks rather than by clicking the globe with a real pointer: pointer
 * interaction with `boundaries-fill` does not register under software rendering here, which is the
 * same wall the trunk's own "hover, drag and click behave with a real pointer" hits ("hovering a
 * territory lit nothing"). Reported separately — a test of the card must not be red for that.
 */
test('card-close: the X and Escape both clear the map selection and stop the voice', async ({ page }) => {
  await openCard(page);

  // The hook has to exist, or the card's optional call is a no-op nobody notices.
  expect(await page.evaluate(() => typeof (window as any).__timemapClearSelection)).toBe('function');

  // Wrap ONCE. Wrapping again between the two closes would nest the counters, and one real call
  // would tick twice — which is exactly what this test reported before the spy was hoisted.
  await page.evaluate(() => {
    (window as any).__calls = { cleared: 0, stopped: 0 };
    const clear = (window as any).__timemapClearSelection;
    const stop = (window as any).__timemapStopSpeak;
    (window as any).__timemapClearSelection = () => { (window as any).__calls.cleared++; clear?.(); };
    (window as any).__timemapStopSpeak = () => { (window as any).__calls.stopped++; stop?.(); };
  });
  const reset = () => page.evaluate(() => { (window as any).__calls = { cleared: 0, stopped: 0 }; });
  const calls = () => page.evaluate(() => (window as any).__calls);

  await reset();
  await card(page).getByRole('button', { name: 'Close' }).click();
  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeHidden();
  expect(await calls()).toEqual({ cleared: 1, stopped: 1 });

  await openCard(page);
  await reset();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeHidden();
  expect(await calls()).toEqual({ cleared: 1, stopped: 1 });
});

/** Clicking empty sea closes the card too — the map is this panel's backdrop. */
test('card-close: an empty-map click closes it, and stops the voice', async ({ page }) => {
  await openCard(page);

  await page.evaluate(() => {
    (window as any).__stopped = 0;
    const stop = (window as any).__timemapStopSpeak;
    (window as any).__timemapStopSpeak = () => { (window as any).__stopped++; stop?.(); };
    window.dispatchEvent(new CustomEvent('polity-selected', { detail: { id: null } }));
  });

  await expect(page.getByRole('heading', { name: 'Roman Republic' })).toBeHidden();
  expect(await page.evaluate(() => (window as any).__stopped)).toBeGreaterThan(0);
});

test('card-shot: what a teacher sees', async ({ page }) => {
  await openCard(page);
  await page.waitForTimeout(500);
  await card(page).screenshot({ path: 'tests/playwright/results/information-card.png' });
});
