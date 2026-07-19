# Voyage time-sync, dated stops, camera-follow & more routes — research

**Date:** 2026-07-19 · **Status:** research only (no implementation yet, per Bart)
**Questions:** (1) sync sailing animation to real journey time, with dated stops; (2) are there
more routes/expeditions in an existing dataset; (3) follow-the-ship camera with dates shown and a
globe-3D / flat-2D switch.

## 1. What our data has today — and lacks

`resources/js/timemap/voyages.json`: 7 voyages, hand-plotted waypoints (coast-audited), one era
window per voyage, **no dates on waypoints**. The ship animation is a decorative constant-speed
loop (`LAP_SECONDS = 45`) with zero relation to real chronology. So: *no*, today we do not know
when a voyage was where — but the historical record does, precisely, and our hero voyages are
small enough to hand-curate.

Example, fully documented itineraries (Wikipedia/journals):
- **Da Gama 1497–99**: dep. Lisbon 8 Jul 1497 · Cape Verde 26 Jul–3 Aug · St Helena Bay 7 Nov ·
  Cape rounded 22 Nov · Mossel Bay 25 Nov–7 Dec · Mozambique 2 Mar 1498 · Mombasa 7 Apr ·
  Malindi 14–24 Apr · Calicut 20 May 1498 · return Lisbon Sep 1499.
- **Tasman 1642–43**: dep. Batavia 14 Aug 1642 · Mauritius 5 Sep–8 Oct · Tasmania 24 Nov ·
  NZ (Golden Bay, the Māori encounter) 13–18 Dec · Tonga 20 Jan 1643 · back Batavia 15 Jun 1643.

### Proposed schema v2 (per voyage)
```json
"legs": [
  { "from": "1497-07-08", "to": "1497-07-26", "coords": [[...], ...] },
  { "stop": "Cape Verde", "from": "1497-07-26", "to": "1497-08-03", "at": [-23.5, 14.9] },
  ...
]
```
Ship position = pure function of a decimal date: find the leg containing `t`, interpolate along
its coords by time fraction; inside a stop window the ship lies at anchor. Backwards compatible:
voyages without legs keep the ambient loop.

## 2. More routes — the honest source landscape

| Source | What it has | Dates? | License / access | Verdict |
|---|---|---|---|---|
| Hand-curated (Wikipedia itineraries) | any hero voyage | day-precision | free | **Best quality**; ~30–60 min per voyage. Candidates: Columbus 2–4, Hudson, Willoughby/Barentsz other legs, Bontekoe (NL classroom favourite!), Schouten/Le Maire (Kaap Hoorn! NL), Cook 2–3, Magellan legs. |
| **DAS — Dutch-Asiatic Shipping** ([Huygens](https://resources.huygens.knaw.nl/das), [RDF on Zenodo](https://zenodo.org/records/5507139)) | **8,100+ VOC voyages 1595–1795**: ship, skipper, chamber, departure date, Cape stopover dates, arrival date, cargo, mutiny/shipwreck notes | port-level dates | open (Huygens ING) | **NL-curriculum gold.** Pair its dates with our Brouwer-route geometry → real, named, dated VOC voyages by the hundred ("De *Batavia*, vertrokken 28 okt 1628…"). |
| **CLIWOC** ([KNMI/DANS](https://phys-techsciences.datastations.nl/dataset.xhtml?persistentId=doi%3A10.17026%2FDANS-2BX-DUTG), [OpenDataSoft mirror](https://public.opendatasoft.com/explore/dataset/ocean-ship-logbooks-1750-1850/)) | ~280k **daily logbook positions**, Dutch/British/Spanish/French ships **1750–1854** | day-by-day real tracks | open | Real wiggly tracks for the late era (Cook's time). Great for an "ambient fleet" of period traffic; too late for da Gama/Tasman. |
| [docuracy/Historical_Sea_Routing](https://github.com/docuracy/Historical_Sea_Routing) | graph-router that *synthesises* plausible historical sea routes (wind/season aware), exports GeoJSON | synthetic durations | open source | Useful generator when we need geometry for a DAS voyage variant; not a dataset itself. |
| GeaCron "vector routes by year" | many expedition routes | unknown | **exclusive-sale only** | Closed. Not usable. |
| AM Digital "Age of Exploration" (50+ voyages) | curated voyage map | yes | commercial/academic subscription | Closed. |

**Conclusion:** there is no free bulk "explorer routes" GeoJSON to import. The winning combo is
hand-curated dated legs for ~15 hero voyages + DAS for mass VOC realism later.

## 3. Time-sync design (answer to "sync seconds to journey time")

Two clocks must reconcile:
- **Timeline clock** (existing): the year slider/play, integer-ish years, whole-map filter.
- **Voyage clock** (new, follow mode): day-resolution within one voyage's window.

Design: selecting "follow" on a voyage puts the map in **tour mode**: the voyage clock drives —
default pace ≈ **1 month of history per 2 seconds** (da Gama ≈ 4½ min; configurable), the year
readout upgrades to a date chip ("22 november 1497 — Kaap de Goede Hoop gerond"), and the global
year filter tracks `floor(date)` so borders stay era-correct. At stops the clock lingers ~1.5 s
and shows a stop card (place, dates, one-liner; optional read-aloud via the existing TTS route).
Outside tour mode ships keep the ambient loop (or optionally creep at real-chronology positions
matching the integer year — cheap to add once legs exist).

## 4. Camera-follow ("de reis meevaren")

- **Rig:** rAF loop; camera centre = point slightly *behind* the ship along the track (lerped for
  smoothness), `map.jumpTo` per frame (no easing fights), zoom ~4.5–5.5 near coasts, gentle
  bearing following the heading (damped, or fixed-north toggle — motion-sickness guard).
  [`easeTo`/`flyTo`](https://maplibre.org/maplibre-gl-js/docs/API/type-aliases/CameraOptions/)
  only for enter/exit transitions; [FreeCameraOptions](https://maplibre.org/maplibre-native/cpp/api/structmbgl_1_1FreeCameraOptions.html)
  exists in MapLibre for a later cinematic pass (low-orbit chase cam) but is not needed for v1.
  Pattern reference: MapLibre's [animate-a-point-along-a-route](https://maplibre.org/maplibre-gl-js/docs/examples/animate-a-point-along-a-route/) example.
- **Antimeridian:** our extended longitudes (Cook/Magellan beyond ±180) mean the camera must
  follow the *extended* coordinate, not the wrapped one — jumpTo accepts it; the ship layer
  already normalises separately. Must test the Pacific crossing explicitly.
- **HUD:** date chip + voyage name + progress bar + exit (Esc/×); slider hidden or repurposed as
  the voyage scrubber during the tour (scrub = set voyage date — teachers will love scrubbing).
- **Interrupt rules:** user drag/zoom pauses following (resume button), Esc exits, era filters
  restore on exit.

## 5. Globe-3D ↔ flat-2D switch

`map.setProjection({type:'mercator'|'globe'})` at runtime works — **already proven empirically in
our app** during the ship debugging session. The ship layer places itself via
`map.transform.getMatrixForModel`, which is projection-aware, so ships should survive the flip
(verify). Wire it as a small toggle on the tour HUD + in Settings (persisted like `tm-style`).
Caveats to verify: label re-collision after flip; the `tm-clouds` sphere in mercator (probably
hide clouds in flat mode); pitch behaviour.

## 6. Phasing & effort

1. **Schema v2 + da Gama dated pilot + time-locked ship + date chip** — ~1 day.
2. **Tour mode** (camera rig, HUD, stop cards, scrubber) — ~1–1.5 days.
3. **Projection toggle** (+ clouds/labels behaviour) — ~½ day.
4. **More hero voyages dated** (Tasman, Columbus, Magellan, Barentsz, Cook, Brouwer/Bontekoe,
   Schouten–Le Maire) — ~30–60 min each, parallelisable.
5. **DAS integration** (named VOC voyages with real dates on the Brouwer geometry) — ~1–2 days,
   separate feature ("VOC-schepen" toggle).

## 7. Open questions for Bart

- Default tour pace (1 month ≈ 2 s?) and whether the tour auto-plays on "Volg de reis" or starts
  paused at departure.
- Should stop cards read aloud (existing TTS endpoint) — cost per click vs. wow factor.
- Ambient mode once legs exist: keep the decorative loop, or pin ships to their real position for
  the map's current year (ship only visible mid-voyage)?
- DAS: one representative VOC ship at a time, or a swarm?
