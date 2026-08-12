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
- [ ] Looked at on the real page, at the size a teacher uses
