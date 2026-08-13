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
- **The footage is the colour. The UI is not.** The information card has NO accent — not on the
  era, not anywhere. Bart, on seeing the era in amber: *"we shouldn't put so much colour in our UI
  while our footage is already very colourful."* A photographed planet is already saturated and
  already moving; a coloured control competing with it is one more thing to look at, and the card
  loses either way. Reach for a second colour and you are almost certainly solving a hierarchy
  problem that position, weight or an underline solves better.
- **Affordance is carried by form, not colour.** The two era dates scrub the timeline, and what
  says so is the underline — white text, underlined. This matters beyond taste: a colour-only
  affordance is invisible to a colour-blind teacher, and invisible over a bright map.
- **Contrast at rest, not just in motion.** Content scrolls under controls, so judge legibility in
  the resting state — the top of the scroll, on the brightest background the map can produce
  (Sahara at noon, a cloud deck). Check there, not over open ocean.
- **Two text colours and no third.** White for anything that says something — title, body copy,
  links. Muted for anything that names something, or that you are allowed to miss. That is the
  whole palette of a card. The Figma frame has six near-identical greys in it (`#7e8b9c`,
  `#8ba1b9`, `#9aa2b0`, `#e2e8f0`…); they are a file's untidiness, not a hierarchy, and they
  collapse to these two. A hierarchy problem is not solved by a new colour — it is solved by
  position, weight, or a hairline.

## 3. Type

Two hands, doing different jobs.

- **Body** says something: 13px on a 310px card, generous line height, `--color-card-body`. Size
  follows the measure, not a house number — 13px is what keeps a narrow panel readable.
- **Label** names something: `.lp-card-label` (Inter SemiBold, uppercase, 0.5 tracking, 8 or 11px).

Small grey body text is a label wearing the wrong clothes. `.lp-card-label` carries **type only** —
the 8px era and footer links wear it in white, the 11px section label in muted.

## 4. Fade, do not clip

Scrolling copy uses `.lp-card-fade`. A hard cut says the text ended; a fade says there is more.
`mask-image`, not a gradient overlay — an overlay must know the colour behind it, and on a map
nothing does.

**And it lifts at the end.** The fade *means* "there is more", so drop the class once the last line
is reached, or the closing sentence sits permanently half-dissolved and the fade means nothing.

## 5. Say when something might be wrong

Cards carry `SOMETHING NOT RIGHT? REPORT`. Historical data is uncertain and teachers are the
cheapest reviewers we have. See `.claude/skills/timemap-territory-data/SKILL.md` for what a report
must carry.

## 6. Closing undoes the whole interaction

A panel over the map is not a modal with a backdrop — **the map is the backdrop**, and a click on
empty sea already closes the card. So X, Esc, and the map: three ways, without inventing an overlay.

But hiding the panel is not closing it. Opening one usually changed something else — a highlighted
territory, a playing voice, a camera. Undo all of it. A territory left lit with no card explaining
why reads as a stuck map, and it is the kind of bug nobody reports because it looks deliberate.

If the state you must undo lives in another module's closure, **ask that module for a hook** rather
than reaching in; a panel has no business knowing what a map calls its selection.

---

## Traps this design has already sprung

Every one of these failed silently. That is why they are written down.

**A brand-kit class outranks every Tailwind utility unless it is layered.** `brand-kit.css` is
imported unlayered, so a bare `.lp-card-label { color: … }` beats `text-card-title` and the era
renders muted with nothing to explain it. Any `.lp-*` class that sets a property a utility might
override goes inside `@layer components`. `app.css` documents the same trap for `.form-control`.

**A token outside a Tailwind namespace generates no utility.** `--color-*`, `--radius-*`,
`--spacing-*`, `--text-*` produce classes; `--card-width` produces nothing, so `class="w-card"`
styles nothing and the element silently loses its width. Either name it into a namespace or
reference it as `style="width: var(--card-width)"`. Check the built CSS, not the source.

**Images from generated directories 404 in a clean checkout.** `/public/flags/` is gitignored, so
all 581 polity flags are missing on a fresh worktree — the normal state, not a broken one. Any
`<img>` pointing at generated content needs an error guard; a broken-image glyph is worse than no
image. (The same absent file is why `timemap-shell` is red: `404 /flags/manifest.json`.)

**Alpine transitions never finish in a background tab.** No rAF, so `x-transition` leaves the
element at `opacity: 0` forever. Panels look broken when you inspect them through an unfocused
browser — verify in Playwright, never the in-app pane, which gets no rAF at all.

**Reference the design, not your memory of it.** The Figma node is the answer to "what colour is
this" — but the *rules* live here, because a file carries six greys where the system has two.

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
- [ ] Chrome monochrome; no accent colour anywhere on it
- [ ] Legible at rest over the brightest thing the map can show
- [ ] Labels are labels, body is body
- [ ] Scrolling copy fades, and the fade lifts at the end
- [ ] DaisyUI for chrome, tokens for colour, no raw Tailwind colours
- [ ] Every class you invented actually generated something — grep the BUILT css
- [ ] Closing undoes everything opening did, not just the panel
- [ ] Looked at on the real page, at the size a teacher uses
