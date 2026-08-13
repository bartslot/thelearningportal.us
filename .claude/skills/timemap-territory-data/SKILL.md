---
name: timemap-territory-data
description: Research, draw and record a historical territory polygon for the Time-Map — source grading, provenance metadata, and the traps that have already bitten this dataset. Use when adding or correcting a polity, a people, or a border on the history map.
---

# Adding a territory to the Time-Map

The map's borders come from Cliopatria (Seshat). It is good and it is incomplete: it has no Suebi,
Marcomanni, Alemanni, Cherusci or Chatti at all, and its earliest Germanic polity is the Goths at
**30 CE** — so a lesson on Teutoburg Forest in 9 CE has nothing to shade. This is how we add what is
missing, and how we correct what is wrong, without inventing history.

**The standard to hold: a teacher must be able to click a territory and see where its shape came
from.** If you cannot say which source a border came from and how much you trust it, it does not go
on the map.

---

## 1. Research before you draw

Never draw from memory, and never from one source.

1. **Start with the written record, not a map.** Find who says the people/polity existed, when, and
   roughly where. Tacitus' *Germania* is a primary source for the Germanic peoples; it is also
   propaganda written by someone who never went there. Note that in the provenance.
2. **Collect at least three independent map sources.** Wikipedia's article maps, the *Digital Atlas
   of the Roman Empire* (DARE), Pleiades, Euratlas, the Ancient World Mapping Center, a scholarly
   atlas. Independent means *not* three sites all tracing the same underlying map — check the
   caption and the credit line before counting it.
3. **Write down where they DISAGREE.** This is the point of the exercise. Tribal territories in
   particular are drawn very differently by different scholars because the underlying evidence is
   thin. The disagreement IS the finding, and it belongs in the record.

## 2. Grade the sources before you choose

Give every source a truth grade and record it. Then draw from the highest, not from the prettiest.

| Grade | What earns it |
|---|---|
| **A** | Peer-reviewed scholarly atlas or an academic GIS dataset with a stated method and citations |
| **B** | A specialist project with visible sourcing — DARE, Pleiades, Euratlas |
| **C** | Wikipedia map with a cited source in its file description |
| **D** | Wikipedia map with no source, or a map on a hobby site |
| **F** | AI-generated, uncredited, or traced from a work of fiction |

Rules that follow from the grades:

- **Draw from the highest grade available.** If that is a C, the territory is still allowed, but its
  confidence is recorded as low and it is drawn with soft, non-committal edges.
- **Never average two sources into a compromise shape.** That produces a border no scholar
  supports. Pick one, cite it, and record what the others said.
- **Nothing below D goes on the map.** An F is not a weak source, it is not a source.
- **A precise line is a claim.** Tribal territories had no surveyed borders. Prefer a soft,
  generous shape over a crisp one you cannot defend — a hard edge tells a pupil we know something
  we do not.

## 3. Record the provenance IN the data

Every added or corrected polygon carries its own paper trail. A shape without one cannot be checked
by the next person, and will be re-litigated from scratch.

Required on each feature:

```
sources[]      each: { title, url, grade, year, note }
chosen_source  which one the geometry was actually drawn from
disagreements  one sentence per source that differs, and how
confidence     high | medium | low
drawn_by       who drew it, and when
method         traced | approximated from description | adapted from an existing polygon
```

This is not paperwork. It is what makes the "report incorrectness" button answerable: when a teacher
says a border is wrong, the first question is always *"which source, and what did the others say?"*

## 4. What the teacher sees

Two things, both required for a hand-made territory:

- **A provenance panel** on the territory, showing the sources, the grade of each, the chosen one,
  and the confidence. Plain language, no jargon — teachers are not historians and should not have to
  be to judge whether to use it.
- **A "Report incorrectness" button** that captures the polity, the year on the slider, and their
  comment. A teacher who spots a mistake is the cheapest reviewer this dataset will ever get, and
  the report must reach us with enough context to act on.

Say when confidence is low. A teacher choosing between two lessons deserves to know that one border
is firm and the other is a scholarly guess.

---

## Traps this dataset has already sprung

Read these before you start. Every one cost real time.

**A name proves nothing about a Wikidata item.** Cliopatria tagged its 500 BCE Roman Republic
polygons with `Q175881` — the Roman Republic of *1798*, Napoleon's sister republic. Both items are
labelled "Roman Republic" exactly, so reading the name will never catch it. The panel showed a
tricolour and an article about the French Directory over a map of the Mediterranean at 33 BCE.

**Check era overlap mechanically.** `php artisan timemap:audit-polity-qids` compares each polity's
polygon years against its Wikidata item's own dates and flags the ones that cannot overlap. It
rediscovers the mistaggings people already found by hand, which is how you know it works. Run it
after any data change.

**Half a date is a half-open interval.** An item with only a dissolution date (Anglo-Saxon England
has its 1066 and no inception) is not a point in 1066. Treating it as one made correctly tagged
polities look wrong — 7 false positives out of 30 findings.

**Corrections go in `database/data/cliopatria-qid-overrides.json`**, keyed by QID, not by name. Two
different polities can share a QID and one polity can be split by name; both shapes are supported
and documented in the file's own header.

**The source carries raster noise.** Cliopatria polygons come with 5-point specks of a few thousand
square kilometres scattered anywhere on the map — 94,505 of them across the set. They are filtered
at build time in `BuildCliopatriaTiles::withoutSpecks()`. If you author a polygon by hand with a
genuine small exclave, know that the filter keeps anything that is a meaningful share of its
polity — a microstate is its own largest part and can never be filtered away.

**Tiles stop at z4.** Anything past that is over-zoomed, so a small error is magnified rather than
hidden. Check your polygon at the zoom a teacher will actually use, not just on the globe.

---

## Where the work goes

Hand-authored territories do **not** go into the Cliopatria source. They live in a supplementary
layer with their own dates, rendering as ordinary territories so a lesson cannot tell the difference.
Keeping them separate means a Cliopatria update never silently overwrites our work, and our work
never gets mistaken for theirs.

## The checklist

- [ ] At least three independent sources collected, and their disagreements written down
- [ ] Every source graded, and the geometry drawn from the highest
- [ ] No averaging between sources
- [ ] Provenance recorded on the feature, including what the rejected sources said
- [ ] Confidence set honestly, and soft edges where it is low
- [ ] Dates on the polygon, and they overlap the Wikidata item's dates
- [ ] `timemap:audit-polity-qids` run and clean
- [ ] Checked on screen at a teaching zoom, not only on the globe
- [ ] Provenance panel and report button reachable from the territory
