---
name: history-portal-ui
description: The History Portal visual language — information cards, panels and overlays. Read before building or changing any UI surface. Figma is upstream; resources/css/brand-kit.css is what code reads.
---

# History Portal UI

**Upstream:** https://www.figma.com/design/PrVD4qMyoTtBvF7UV7Yhej/History-Portal?node-id=1462-1886
**In code:** `resources/css/brand-kit.css`

Figma is where decisions are made. The CSS is where they land, and it is what code reads. Never
fetch Figma at build or run time — it cannot work in CI or on a machine that is not signed in.

---

## 1. Less

The default failure of generated UI is too much of everything. Assume every screen is too long.

**Words.** Write the shortest thing that is still true. Cut every sentence that explains what the
button already says. No helper text under a labelled control, no "you can now…", no reassurance. A
teacher scanning a card in ten seconds should get it; anything they only need occasionally goes
behind the help icon. If a paragraph is doing a label's job, make it a label.

**Choices.** One primary action per surface. Everything else is a text link or is not there.
Adding an option is easy and is almost always the wrong answer — decide, and let the design carry
the decision. ([HIG: Simplicity](https://developer.apple.com/design/human-interface-guidelines/design-principles#Simplicity))

**Chrome.** Hairlines, not boxes. No shadows. If a separator reads as a line rather than as a
change of section, it is too strong.

## 2. Colour ([HIG](https://developer.apple.com/design/human-interface-guidelines/color))

**Our content layer is a photographed planet.** It is colourful, it moves, and its colour changes
with the year, the map style and the time of day. Everything below follows from that.

- **Chrome over the map is monochromatic.** Toolbars, tab bars, panels: the dark surface, three
  greys, white. Colourful content under colourful controls is what makes labels hard to read.
- **One accent, used once per surface.** Amber marks the single thing the teacher asked about —
  the era on a card. Not links, not icons, not headings, never decoration. If two things on a
  screen are amber, one of them is wrong.
- **Contrast at rest, not just in motion.** Content scrolls under controls, so judge legibility in
  the resting state — the top of the scroll, on the brightest background the map can produce
  (Sahara at noon, a cloud deck). Check there, not over open ocean.
- **Three text colours and no fourth.** Title white, body `--color-card-body`, muted for what you
  are allowed to miss. A hierarchy problem is not solved by a new colour.

## 3. Type

Two hands, doing different jobs.

- **Body** says something: 15–16px, generous line height, `--color-card-body`.
- **Label** names something: `.lp-card-label` (Inter SemiBold, uppercase, 0.5 tracking, 8 or 11px).

Small grey body text is a label wearing the wrong clothes.

## 4. Fade, do not clip

Scrolling copy uses `.lp-card-fade`. A hard cut says the text ended; a fade says there is more.
`mask-image`, not a gradient overlay — an overlay must know the colour behind it, and on a map
nothing does.

## 5. Say when something might be wrong

Cards carry `SOMETHING NOT RIGHT? REPORT`. Historical data is uncertain and teachers are the
cheapest reviewers we have. See `.claude/skills/timemap-territory-data/SKILL.md` for what a report
must carry.

---

## Rulers, and anything that scrolls under a mark

From the time scrubber (node 1471:2238), because the same questions come up on every timeline,
progress bar and filmstrip after it.

**Levels that are always there differ by weight, not by length.** Decades and centuries are the same
height from the same edge; the century is brighter and half a pixel wider. Three lengths on screen
at once read as three kinds of thing and the eye spends its time sorting them. A level that appears
only in a particular state is the exception and *should* be shorter — the single years are a third
the height of a decade, because they subdivide the ruler rather than compete with it.

**The mark that says "here" is a plain light line.** No accent, no arrowhead, no glow. A position
needs none of them to be understood, and the map behind is already carrying every colour the screen
can take.

**A control overlays what it controls; it does not replace a piece of it.** The mock backed the play
button and the year with the bar's own colour at 90% — invisible as a shape, so not a panel, but it
erased every tick behind it and the left end read as a slab the timeline only started after. Bart:
*"the play button and year input is an overlay that should be an overlay of the scrub bar."* Let the
content run underneath. Anything that needs to stay readable carries its own fill, at its own size.

**Detail can arrive when it is wanted and not before.** Thrown across a millennium the scrubber
stays a ruler of decades; eased along a few years it puts the single years in, and snaps to them.
Density that is noise at a glance is exactly right to a hand being careful — so key it to how the
control is being used, not to a preference or a zoom level the teacher has to find.

**Magnification follows the pointer with no easing at all.** The Dock effect — the ticks under the
cursor stand up and settle either side — is only convincing when the position is instantaneous.
Ease the arrival and the departure (enter, exit) and nothing else: easing the position itself is
indistinguishable from lag. Smoothstep the falloff, because a linear one has a corner at the edge
of the radius and the eye finds it.

**A control that moves in whole units should sound like one.** The scrubber clicks once per year,
from five recorded knob clicks with a loudness ladder: quiet ones for a year, the middle for a
decade, the loudest for a century. One flat click is a metronome; the ladder means the ruler you are
looking at is the ruler you are hearing. Rate-limit it, and never let it run during playback.

**The brand face goes on content, never on chrome.** The big year that appears while you scrub is
set in History because it is the one thing on that surface which is not a control. Everything you
operate is Inter.

**A static mock never meets the cases a live one does, so read it for intent and not for debris.**
This one carried a stray full-height tick behind the play button, a label chip in near-black on
near-black, and no century label under the playhead — because its numbers happened to miss. Live,
the playhead lands on a century label constantly and struck it through. Reproduce what the design
decided, fix what it never had to face, and say in the commit which was which.

---

## Traps that cost an afternoon each

Every one of these failed silently, or failed in the measurement rather than the product. That is
the only reason they are written down.

**An opacity transition runs on the compositor, so `getComputedStyle` cannot see it.** It reported
`0` for a third of a second after the fade was already under way, and a Playwright screenshot
freezes compositing — so a capture taken mid-fade renders the value it was passing through, and the
element looks like it never appeared. Assert the *inline* value, which is the decision the code
made; before a screenshot, wait for `getAnimations()` to drain rather than guessing at milliseconds.

**A font nothing renders is never fetched.** The big year starts as an empty element, so no glyph
was ever needed from History, so the face was never loaded — the first year a teacher scrubbed to
came up in the fallback serif and swapped a moment later. Nothing else on that page uses it (the
map's labels are SDF glyphs inside WebGL). `document.fonts.load()` at mount.

**Chrome will not rasterise a fine pattern across a very wide layer.** A 1px line every 4px came
back as a soft wash on a 25,000px strip. Pin the pattern to the viewport at its own size and move
its `background-position` instead — same picture, one small layer.

**Clearing an inline style that held a `var()` collapses the element.** Setting `style.height = ''`
does not restore `var(--tick-height)`; it removes it, and every tick outside the effect went to
zero. Restore the token, not the empty string.

**rAF must never be the only thing that can switch something off.** It stops in a background tab and
pauses whenever the compositor has nothing to draw. Measure with rAF; put the deadline on a timer.
And a deadline for "the gesture ended" must not fire while the pointer is still down — holding still
is the slowest, most deliberate scrub there is, and the years used to fade out from under it.

**Per-event velocity measures the input device, not the hand.** Pointer moves arrive in bursts, so a
4px step landing 3ms after the last sample reads as a fling however slowly the hand is going — the
years came in and went straight out again, once per burst. Measure distance over a fixed window,
from one sampler reading the scroll position, not from two handlers posting deltas to each other.

**A synthetic pointer teleport does not establish hover.** One `mouse.move` to a position the cursor
has never occupied left the magnifier flat, and the test read a perfectly working feature as broken.
Walk the pointer in over several moves — which is also what a hand does.

**A timing-dependent assertion on a loaded machine tests the machine.** A scripted fling is whatever
speed the box can deliver. Measure what the gesture actually achieved and only assert the negative
if it qualified; say so in the output when it did not.

---

## Alongside the rest of the system

DaisyUI `learningportal` remains the source of truth for component chrome — `btn`, `tabs`, `modal`.
The brand kit supplies only what DaisyUI has no opinion about: hairline opacity, fade height, label
tracking, card radius. **Never raw Tailwind colour utilities.**

Already binding, not restated here: modals close three ways (X, Esc, backdrop); no emoji in teacher
UI; no instructional prose in the editor; Heroicons outline 24×24 stroke 1.5; ease by intent via
`easing.js`; anything visual ships with dev-panel controls in the same change.

## Re-syncing

`get_variable_defs` for the tokens, `get_screenshot` because variables carry no layout or
hierarchy. Update `brand-kit.css`, and name the node and the change in the commit. Delete tokens
that no longer exist upstream — a token nobody designed is a value nobody chose. Do not add one for
a single use.

## Before committing a UI change

- [ ] Cut the text once more than felt comfortable
- [ ] One primary action; every added option justified
- [ ] Chrome monochrome; accent used once
- [ ] Legible at rest over the brightest thing the map can show
- [ ] Labels are labels, body is body
- [ ] Scrolling copy fades
- [ ] DaisyUI for chrome, tokens for colour, no raw Tailwind colours
- [ ] Every class you invented actually generated something — grep the BUILT css
- [ ] No two tokens share a utility prefix: `--text-x` and `--color-x` both answer to `text-x`, the
      size wins, and the colour is dropped with nothing anywhere to say so
- [ ] Looked at on the real page, at the size a teacher uses
