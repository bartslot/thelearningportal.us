# Time-Map data quality: Cliopatria errors, GeaCron alternative, sovereignty relations

**Date:** 2026-07-18 · **Status:** investigation (no implementation decided)
**Trigger:** Grand Duchy of Berg is wrong on our map; GeaCron shows colonies unified with their
motherland (Spain = Spanish Peru = Nueva España) and we don't.

## 1. Grand Duchy of Berg — confirmed wrong, and it's a *geometry* error class

Reality: Rhineland state around Düsseldorf (≈6.8E, 51.2N), 1806–1813.
Our Cliopatria data (`storage/app/cliopatria/cliopatria_polities_only.geojson`, QID Q249428 — correct item):

- Polygons drift east into **Thuringia** (bbox reaches 12.1E; 1811+ segments sit entirely at 10–11.8E).
- Segments run to **1827** (14 years past dissolution).
- **Düsseldorf at 1810 is covered by 'Duchy of Nassau'** instead.
- No pre-1806 'Duchy of Berg' exists at all.

The QID-override system (`cliopatria-qid-overrides.json`) fixes *article* mistags only. Geometry and
render-era are baked into the committed tiles — fixing them means patching the source GeoJSON and
re-running `timemap:build-cliopatria-tiles`, or an upstream PR to Seshat's Cliopatria repo.

## 2. How widespread? Two automated audits (scripts in session scratchpad)

**Misplacement audit** — per-QID median polygon centroid vs Wikidata P625 (or capital P36→P625):
1,198 measurable polities, 216 flagged >400 km. Most flags are *expected*: colonial empires whose
polygons legitimately sit far from the metropole coordinate, plus big-country centroid effects.
Confirmed true geometry errors after manual review:

| Polity | Problem |
|---|---|
| Grand Duchy of Berg | Rhineland state drawn in Thuringia, era to 1827 |
| French Equatorial Africa (1842–47) | polygons drawn over **Algeria**; name + era + place all wrong (should be French Algeria) |
| Freetown Colony (1796 segment) | geometry blob stretches to South Africa |
| New Caledonia (1856–59) | polygon set includes Réunion / Indian-Ocean islands |
| Wikidata-side errors found too | Zimbabwe Rhodesia P625 lat +17.9 (should be −19); Portuguese/Dutch Ceylon P625 at Malacca; Tarumanagara P625 in Gulf of Guinea |

**Era audit** (existing label/era sweep): **104 polities** where the article is right but the tile
span disagrees with Wikidata era by >60% (e.g. Berg 1806–1827 vs 1806–1813, Kamerun 1868–1870 vs
1884–1919). Some are Wikidata metadata quirks (Hittites P571=+1500 sign error), many are Cliopatria.

Two more QID mistags found via the misplacement audit and **fixed immediately** (override entries
59–60, synced): 'Guinea' carried the Equatorial Guinea item (name-split; 'Equatorial Guinea'
polygons keep Q983), 'Zhongshan' (Warring States, Hebei) carried the modern Guangdong city item.

## 3. GeaCron — licensing reality

- geacron.com offers **no downloadable/licensable dataset and no self-serve API**.
- Their "Asset Offer" page is an **exclusive sale of the entire GeaCron database** ("acquire our
  digital assets exclusively", contact sales@geacron.com) — an acquisition pitch aimed at companies,
  not a data subscription. Databases listed: vector country maps/year, routes, border-change
  reasons, demographics, temperatures, timelines, place names.
- Their site is All-Rights-Reserved; tracing/scraping their map is not an option.
- Realistic uses: (a) email sales@geacron.com to ask if non-exclusive licensing exists (Bart's
  call), (b) use their public map as a *visual QA reference* when hand-reviewing flagged polities —
  which is exactly how the Berg error was spotted.

Cliopatria (Seshat, CC-BY 4.0) remains the only open dataset of this scope. Alternative open-ish
source seen linked from GeaCron: phersu-atlas.com (license unverified). Practical path: keep
Cliopatria, fix what the audits flag (overrides for article mistags; GeoJSON patches + upstream PRs
for geometry/era), rather than switch base data.

## 4. Colonies ↔ motherland: the relation data ALREADY EXISTS in our download

The source GeoJSON has fields our tile build drops (`-y` whitelist keeps only
Name/FromYear/ToYear/Wikidata/Type): **`SeshatID`, `Components`, `MemberOf`**, plus 385
`Type=RELATION` rows and parenthesized composite polities we filter from rendering:

- `'(Spanish Empire)' Components='Kingdom of Spain;Spanish Empire'` (per era segment)
- `'(Vichy France)' Components='Vichy France;French Africa;French Indochina'`
- RELATION rows encode alliances/personal unions: `'(Personal union of Spanish Empire with
  Habsburg Monarchy)'`, `'(Allegiance of Duchy of Aquitaine to Kingdom of France)'`

Cliopatria's modeling is **inconsistent** (why Spain looks disconnected): at 1650 the colonies are
one worldwide 'Spanish Empire' polity [Q80702] while the metropole is separate 'Kingdom of Spain'
[Q29] — different QIDs → different fill colors, no visible link. Meanwhile the Dutch/1873-Spanish
cases merge colonies *into* the metropole polity. GeaCron instead colors by sovereignty everywhere.

### Proposed feature (not yet built): sovereignty groups
1. Build step: extract composites + Components into committed
   `database/data/cliopatria-groups.json` — `{group, from, to, members[]}` per segment.
2. Panel: "Part of: **Spanish Empire**" line (member → group at current year), listing sibling
   members (Kingdom of Spain, Spanish Empire) with click-through.
3. Map: optional "empires" color mode — fill hashed by group instead of QID so Spain + Spanish
   America render one color at 1650 (needs group id in tiles → tile rebuild with a `Group` field,
   or a JS name→group lookup from the committed JSON; the latter needs no tile rebuild).
4. RELATION rows later: could power "allied with / vassal of" panel lines.

## 5. Recommended order

1. **Sovereignty groups from Components** — biggest teacher-visible win, zero new data needed,
   no tile rebuild required for the JS-lookup variant.
2. **Geometry/era fix pipeline** — small curated GeoJSON patch file applied before tippecanoe in
   `timemap:build-cliopatria-tiles` (start: Berg → Rhineland 1806–1813 + hand Düsseldorf back from
   Nassau; delete the bogus 1842–47 'French Equatorial Africa' Algeria polygons). File upstream
   issues at Seshat-Global-History-Databank/cliopatria in parallel.
3. **Email GeaCron** about non-exclusive licensing if Bart wants a second reference source.
4. Era-wrong list (104) + misplacement list live in session scratchpad
   (`qid_audit.json`, `misplace_audit.json`) — worth committing under `storage/app/cliopatria/`
   or docs if we start the patch pipeline.
