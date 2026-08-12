---
name: history-portal-ui
description: The History Portal visual language — information cards, panels and overlays. Read before building or changing any UI surface, and before adding a panel to the map. Figma is upstream; resources/css/brand-kit.css is what code reads.
---

# History Portal UI

The design lives in Figma and lands in this repo as tokens. **Read this before you design anything
new.** A surface that does not follow it will look like a different product, and on a map that is
especially obvious because the panels sit on top of the same globe.

**Upstream:** https://www.figma.com/design/PrVD4qMyoTtBvF7UV7Yhej/History-Portal?node-id=1462-1886
**In code:** `resources/css/brand-kit.css`

Figma is where decisions are MADE. The CSS file is where they LAND, and it is what code reads.
Never fetch Figma at build or run time: it cannot work in CI, on a machine that is not signed in, or
the day a node id changes. Re-sync by hand when the design moves (see the bottom of this file).

---

## The rules, in order of how often they are broken

**1. Subtle breaks, never boxes.** Every division is a hairline at about 7% white
(`--color-card-hairline`). No borders around groups, no drop shadows, no filled panels inside
panels. If a separator reads as a LINE rather than as a change of section, it is too strong.

**2. Text fades, it does not clip.** Scrolling copy uses `.lp-card-fade`. A hard cut tells the
reader the text ended; a fade tells them there is more. It is a `mask-image`, not a gradient
overlay, because the card sits on a map whose colour changes with the year, the style and the time
of day — an overlay would have to know what is behind it, and it cannot.

**3. Two hands of type, and they do different jobs.**
   - **Body** says something: 15–16px, generous line height, `--color-card-body`.
   - **Label** names something: Inter SemiBold, uppercase, 0.5 letter-spacing, 8px or 11px,
     `--color-card-muted`. Use `.lp-card-label`. "ROMAN REPUBLIC", "READ MORE ON", "SOMETHING NOT
     RIGHT?" are all labels. If you are tempted to make body text small and grey to de-emphasise it,
     you want a label instead.

**4. Contrast is not decoration.** Title is pure white on the dark surface. Body is the light
blue-grey. Muted is only for things you are allowed to miss. Never introduce a fourth text colour
to solve a hierarchy problem — you have three, and one accent.

**5. One accent, and it means the same thing everywhere.** Amber (`--color-card-accent`) is dates
and links. It is the same amber the map selects a territory with, and that is deliberate: the accent
means "this is the thing you asked about". Do not use it for warnings, and never for decoration.

**6. One primary action per card**, as a full-width white pill with an icon. There is exactly one on
the card and it is the thing the teacher came to do. Everything else is a text link.

**7. Say when something might be wrong.** The card carries a "SOMETHING NOT RIGHT? REPORT" footer.
Historical data is uncertain and teachers are the cheapest reviewers we have; a surface that presents
a fact must give them somewhere to push back. See `.claude/skills/timemap-territory-data/SKILL.md`
for what a report has to carry.

---

## Working with the rest of the system

**DaisyUI is still the single source of truth for component chrome.** `learningportal` is the active
theme. Buttons, tabs, modals and inputs use DaisyUI semantic classes (`btn`, `tabs`, `modal`) —
brand-kit.css supplies only the values DaisyUI has no opinion about: hairline opacity, fade height,
label tracking, card radius.

**Never use raw Tailwind colour utilities** for chrome (`bg-slate-900`, `text-amber-500`). Use the
tokens or DaisyUI's semantic classes, or the theme stops being the source of truth. This rule
predates the brand kit and still holds.

**Existing conventions that already apply and are not restated here:**
- Modals close three ways: X, Esc, backdrop.
- No emoji in teacher-facing UI. Plain text labels.
- No instructional prose in the editor — controls and short labels; how-to goes behind the help icon.
- Icons are Heroicons outline, 24×24, `fill=none`, `stroke-width=1.5`.
- Ease by intent via `easing.js`, never a raw curve and never linear.
- Anything visual ships with controls in the dev settings panel in the same change.

---

## Re-syncing from Figma

When the design moves, pull the tokens rather than eyeballing hex values off a screenshot:

1. `get_variable_defs` on the node id → the named variables and type styles.
2. `get_screenshot` on the same node → look at it, because variables do not carry layout,
   hierarchy or the fade.
3. Update `resources/css/brand-kit.css`, and say in the commit WHICH node and WHAT changed.
4. If a value in the file no longer appears in Figma, delete it. A token nobody designed is a
   value nobody chose.

**Do not add a token for a one-off.** The kit is for values used in more than one place. A single
card that needs an odd inset uses a utility class; the moment a second one needs it, it becomes a
token with a name that says what it is FOR, not what it looks like.

---

## The check before you commit a UI change

- [ ] Read this file and looked at the Figma node
- [ ] Divisions are hairlines, not boxes or shadows
- [ ] Scrolling copy fades, does not clip
- [ ] Labels use `.lp-card-label`, body uses body colour
- [ ] Three text colours and one accent, no more
- [ ] One primary action, as a pill
- [ ] Chrome uses DaisyUI classes, colour uses tokens, no raw Tailwind colours
- [ ] Looked at it on the real page, at the size a teacher will use
