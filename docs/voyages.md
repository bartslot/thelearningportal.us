# Voyages — how they work, what they assume, where they break

A voyage is a **scene kind**, not a lesson type. A lesson is a sequence of scenes and each scene
carries its own `kind`; a lesson with voyage scenes is just a lesson, and it can hold narration,
quizzes, galleries and maps alongside them. There is no such thing as "a voyage lesson" in the data.

This document is the honest version: what the system takes for granted, what it does at the edges,
and what it cannot currently do. Written 2026-08-06.

---

## The pieces

| Piece | Where |
|---|---|
| Route catalogue | `resources/js/timemap/voyages.json` + `<voyage>-tour.json` per voyage |
| Player / editor engine | `resources/js/voyage-tour.js` (~2,400 lines) |
| Ships, wake, flags | `resources/js/timemap/voyage-ships.js` |
| Fog of war | `resources/js/timemap/voyage-fog.js` |
| Route maths (spline, arc) | `resources/js/timemap/voyages.js` |
| Numbered stop pins | `resources/js/map-itinerary.js` — **shared with plain map blocks** |
| Base map | `resources/js/lesson-map.js` |
| Authoring | `app/Livewire/Wizard/Step3SceneConfigurator.php` |

A scene points at a route with `config.voyage` (catalogue id) and `config.leg` (index). The
overview scene additionally sets `config.intro` / `extra_config.overview`.

---

## Assumptions

These are relied on. Breaking one does not throw — it produces a wrong-looking map.

1. **Waypoints are ordered along the sailing direction.** The route is a Catmull-Rom spline through
   them (`smooth()`), and "progress" is a fraction along that spline. Out-of-order waypoints make
   the ship sail backwards through a leg.
2. **A leg's `wp: [from, to]` are indices into `waypoints`, and `from < to`.** Nothing validates
   this.
3. **Waypoint *i* lands exactly on sample `i * SAMPLES_PER_SEGMENT`.** This is how a track fraction
   maps back to a waypoint. `SAMPLES_PER_SEGMENT` is exported for that reason — hard-coding 18
   anywhere else will silently drift.
4. **Longitudes may run past ±180 deliberately.** Tasman's Pacific legs use values like `184.8` so
   the spline does not snap back across the map. Normalising them "to be correct" breaks the route.
5. **Stop 1 is the first place the voyage ARRIVES at.** The departure port carries no number: it is
   where stop 1 sails from, and it is not a scene. `Step3SceneConfigurator::voyageStops` counts the
   same way, so the map and the inspector agree.
6. **A voyage that returns home reuses the departure pin** rather than adding a place to the count.
7. **Every `voyage_map` setting is whole-voyage, not per-waypoint.** Projection, style, ship,
   camera, fog all live in `game_config`. Per-waypoint data is content only: dates, gallery, stop
   title.
8. **A lesson may hold more than one voyage.** Per-voyage edited copies live in
   `game_config.voyage_defs[<voyage-id>]`; the older single `game_config.voyage_def` is still read
   as a fallback. Absence of either is normal — the browser resolves from the catalogue.
9. **ffmpeg-free.** Nothing about a voyage needs an external binary.

---

## Edge cases that are handled

- **Multi-voyage lessons.** `voyage-tour.js` only lets a supplied `def` win when
  `!def.id || def.id === voyage`, so one voyage's edited route cannot draw under another's scenes.
  Covered by `tests/Feature/Wizard/MultiVoyageLessonTest.php`.
- **Sailing counts as playing.** A leg with no narration is still playback — the ship is moving. The
  transport deck shows pause, not a play button.
- **Pausing.** `setPaused()` freezes the ship mid-ocean and stops the landfall auto-advance
  accumulating. The map-block itinerary does the same via `pauseItinerary()`.
- **Leaving a voyage scene.** `_playScene` destroys the voyage instance when the next scene is not a
  voyage, so a torn-down map cannot be replayed by backward navigation.
- **The overview.** Fog is hidden for it (a mask sized to leg 1 would swallow most stops) and the
  route draws from the home port outwards so a class sees the ORDER, not a finished tangle. The
  editor gets it drawn instantly — a teacher needs to drag it, not wait.
- **Live edits never re-mount.** `setVoyageDef` / `ships.setDef` update in place; a re-mount happens
  only on a voyage-id change. Live updates are gated on a real change so the 3-second poll cannot
  rebuild drag handles mid-drag.
- **Anachronistic detail.** Modern city dots and labels default **off** for a voyage — a 1642 map
  was labelling Mogadishu and Kampala. Teachers can turn them back on.
- **Sparse tilesets.** `tileBounds` bounds imagery/elevation to the route's box, so MapLibre never
  requests a tile the lesson will not show.

---

## Known limitations

Real, current, and none of them throw.

1. **Nothing validates a route at authoring time.** Reversed waypoints, a leg whose `wp` indices are
   backwards, a `leg` index past the end of `legs` — all produce a silently wrong map. There is no
   `voyages:lint`.
2. **Fog rings must be closed polygons.** An unclosed ring renders as a filled blob over the
   ocean. Nothing checks this on load.
3. **MapLibre does not load its style while the browser pane is hidden** (`document.hidden`). Every
   voyage screenshots as blank in an automated pane. Verify with a real visible browser
   (Playwright), not the preview pane. This is an environment artefact, not a defect.
4. **GSAP timelines do not advance in a hidden pane either.** Step the timeline by hand to verify.
5. **Dates are prose, not a timeline.** `depart` / `arrive` are strings on a leg. Nothing enforces
   that leg *n*'s arrival precedes leg *n+1*'s departure, and the date chip will happily print an
   impossible voyage.
6. **The itinerary reveal is time-based, not scroll- or narration-locked.** On a map block it is
   paced to the narration length; on a voyage overview it is paced to `overview_anim.duration`. A
   scene whose narration is much longer than the draw leaves the class looking at a finished map.
7. **No per-leg narration language check.** `lessons:renarrate-mismatched` exists for lessons but a
   voyage leg's audio locale is not surfaced in the editor.
8. **Stop titles come from `legLabels` / the scene's `location`.** A leg with neither shows a
   numbered dot and no name. That is deliberate (better than a wrong name), but there is no warning.
9. **The route line is always drawn for a voyage.** A map block deliberately draws none — Marco Polo
   did not sail Venice → Chang'an in a straight line — but a voyage cannot currently suppress it.
10. **`voyage-tour.js` is ~2,400 lines** and mixes camera, fog, ships, HUD, gallery and editing
    handles. The audit army flagged it; the itinerary pins are the only part extracted so far.

---

## Testing

| Spec | Covers |
|---|---|
| `tests/playwright/voyage-lesson.spec.ts` | traversal, chips, galleries |
| `tests/playwright/voyage-itinerary.spec.ts` | stop order, drag-to-reorder |
| `tests/playwright/voyage-overview-handles.spec.ts` | no leg handles on the overview |
| `tests/playwright/voyage-leg-switch-no-remount.spec.ts` | in-place updates |
| `tests/playwright/map-focus-itinerary.spec.ts` | the shared pins, on a plain map block |
| `tests/Feature/Wizard/MultiVoyageLessonTest.php` | per-voyage edited copies |

**Known-failing at the time of writing**, and pre-existing (verified by running them against
reverted source): `voyage-itinerary` ×2 and `voyage-overview-handles` ×2 fail because the fixture
resolver picks the richest voyage lesson (The Punic Wars), which has **no overview scene**. The
specs are right; the fixture selection is wrong. `voyage-lesson` also fails on a wizard visibility
issue unrelated to voyages.

---

## Muting

Never let narration play out loud while driving the player. Playwright is muted via `--mute-audio`;
manual runs must mute **before** `startLesson()`.
