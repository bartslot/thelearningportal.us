# Voyage tour — Abel Tasman demo + reusable framework (plan)

**Date:** 2026-07-19 · **Status:** approved for build (Bart: "Let's go")
**Goal (today):** press Play on the "Abel Tasman" lesson → space-to-globe zoom → Tasman's fleet
sails from Batavia with date + place chips → at each landfall a stop card + images near the coast →
next slide = slideshow of those images → back to the map for the next leg → repeat to the end.
Controls: ONLY previous/next (← / → arrows, A / B keys). Teacher with zero training. No bugs.

Not a timemap feature: this lives in the **lesson player** (scene sequence), so it publishes like
any lesson. The teacher timemap keeps its ambient ships; tour tech is shared where free.

## Architecture (framework, Tasman = first content)

- **Lesson**: `game_type = 'voyage'` (existing nullable column; peer of `story_game`).
- **Scenes** alternate two kinds:
  - `kind='voyage'` — plays ONE leg of a voyage: config `{voyage: 'tasman-1642', leg: <n>}`.
    Rendered by a new `voyage-tour.js` module inside the player (shares the lesson-map foundation):
    ship(s) sail the dated leg, camera follows, date chip counts through real chronology
    (~1 month/2s), arrival shows a stop card (place, date, one-liner) + small image thumbs pinned
    near the coast. Scene 1 opens with the space→globe→Batavia zoom. Leg ends → player waits for →.
  - ordinary image scenes — the slideshow of that stop's artwork/drawings (existing player kind).
- **Voyage data v2** (`resources/js/timemap/voyages.json`, additive): per voyage `legs:[{from,to,
  coords,stop:{name,date_label,at,story,images[]}}]` + `fleet:[{name,masts,flagship}]`. Voyages
  without legs keep the ambient loop. Other tours = new data + scenes, zero new code.
- **Ships**: Blender MCP is NOT connected in this session → better plan that meets the same goals
  (fraction of size, no textures, flat colours, flag mounts, mast variants): **parametric low-poly
  fleet generated in code** (`voyage-fleet.js`): hull from a few boxes, 2/3/4 masts, plane sails,
  flag quads coloured per ship (Prinsenvlag orange-white-blue stripes as 3 flat boxes — no texture
  needed, matches the reference photo). Tasman fleet = Heemskerck (flagship) + Zeehaen. Replaces
  the 2.2 MB GLB (delete) — KBs of code instead. GLB path stays supported for future hero models.
- **Content dataset check**: what we HAVE (corpus artworks/figures) vs NOT (Gilsemans' 1642-43
  journal drawings — Murderers' Bay, Tongan canoes — public domain, fetched from Wikimedia
  Commons into `public/lessons/tasman/` with credits). Gap report is a deliverable.

## Steps

### Step 1 — Tasman dated legs + Dutch stop content (data)
voyages.json v2: Tasman legs with real dates (Batavia 14 aug 1642 → Mauritius 5 sep–8 okt →
Tasmanië 24 nov → Golden Bay/"Moordenaarsbaai" 13–18 dec → Tonga 20 jan 1643 → Fiji reefs →
via N-Nieuw-Guinea → Batavia 15 jun 1643). Per stop: NL title, date label, 2–3 sentence NL story
(goal of the VOC expedition, what they carried, the Māori confrontation told factually and
age-appropriately, the friendly Tongan trade contrast), image slots.
**Acceptance:** JSON validates (PHP data test extended); dates chronological; every stop has NL
story ≤ 320 chars; legs' coords reuse the audited sea route.

### Step 2 — Image sourcing + dataset gap report (assets)
Query corpus (paintings/figures) for Tasman/Gilsemans/Heemskerck holdings; fetch the public-domain
journal drawings from Wikimedia Commons (Murderers' Bay view, Tongan canoe reception, Tasman
portrait, 1644 Bonaparte-Tasman map) into `public/lessons/tasman/` + `credits.json`;
write `docs/superpowers/specs/2026-07-19-tasman-dataset-gaps.md` (have vs missing).
**Acceptance:** ≥6 images local w/ attribution; gap doc lists corpus hits + misses.

### Step 3 — Parametric low-poly fleet (voyage-fleet.js)
`buildTallship({masts, hullColor, sailColor, flag})` → THREE.Group, ≤ ~600 tris/ship, flat
Lambert colours, named flag anchor. Replace GLB in voyage-ships.js (timemap ambient ships use the
same generator; GLB deleted). Fleet renders flagship + escorts in loose formation.
**Acceptance:** timemap ships still sail (voyages.spec green); bundle sheds the 2.2 MB GLB;
2- and 3-mast variants visibly differ; flags coloured per ship.

### Step 4 — voyage-tour.js + `voyage` scene kind (player)
Tour engine: date-driven ship position along a leg, damped camera follow (extended-lon safe),
date chip (NL month names), stop card + coastal image thumbs, space→globe intro on leg 0,
Globe3D/Flat2D respect from scene config, reduced-motion = jump-cuts. Player routes
`kind==='voyage'` to it; leg end → waits for Next. Depth rule: layer below tm-clouds equivalent
(player map has no clouds — verify).
**Acceptance:** a leg plays start-to-stop hands-off; ← replays/goes back, → advances; no console
errors; works in globe AND flat.

### Step 5 — Demo lesson command (`lessons:make-tasman-voyage`)
Idempotent artisan command building the full lesson: voyage scenes (legs 1–5) interleaved with
image-slideshow scenes wired to Step 2 assets, `game_type='voyage'`, status previewable.
**Acceptance:** run twice → one lesson; scene order alternates map/slideshow; lesson opens in
player from the dashboard.

### Step 6 — Controls hardening
←/→ plus A/B advance/back everywhere in this lesson type; no quiz gate for voyage lessons;
progress dots; nothing else interactive required.
**Acceptance:** whole lesson traversable with ONLY arrow keys; A/B equivalent; focus-safe.

### Step 7 — QA loop (Playwright)
`voyage-lesson.spec.ts`: open lesson → arrow through every scene → assert date chips, stop cards,
slideshow images, zero console errors; screenshots per scene for Bart.
**Acceptance:** spec green twice consecutively; screenshot set delivered.

## Execution model (per Bart's instruction)
Fable 5: plan (this doc), the precision map/camera/three code (Steps 3–4 core), integration,
final QA. Haiku subagents: Step 1 content drafting, Step 2 sourcing/downloads/gap doc, Step 5
scaffold, Step 7 spec drafting. Everything reviewed by Fable 5 before commit.
