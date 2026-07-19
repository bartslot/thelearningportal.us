# Abel Tasman Lesson: Public Domain Dataset Status

**Date:** 2026-07-19  
**Lesson:** Abel Tasman's 1642–1644 Voyages of Exploration  
**Context:** Sourcing verified public-domain imagery for historical lesson creation

---

## Current Corpus Coverage

Queried the local `pgsql_corpus.public.artworks` table for Tasman-related historical imagery:

| Search Term | Count | Status |
|-------------|-------|--------|
| `%tasman%`     | 0     | ❌ No artwork tagged with Tasman |
| `%gilsemans%`  | 0     | ❌ No artwork by/depicting Gilsemans |
| `%heemskerck%` | 2     | ✅ Two existing entries (ships/voyage-related) |
| `%zeehaen%`    | 0     | ❌ No artwork by/depicting Zeehaen |

**Observation:** Corpus contains minimal Tasman expedition imagery. Gilsemans' drawings (cartographer/artist on voyage) are completely absent despite being primary historical sources.

---

## Wikimedia Commons Search Results

All images sourced via verified Wikimedia Commons API (`commons.wikimedia.org/w/api.php`) with User-Agent: `TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)`.

### Downloaded Files (7 total, all Public Domain)

| Local Filename | Commons Source | License | Size |
|---|---|---|---|
| `moordenaarsbaai-gilsemans.jpg` | Gilsemans 1642 | Public domain | 439 KB |
| `golden-bay-encounter.png` | Tasman's 1642 encounter with Maori at Golden Bay | Public domain | 1.9 MB |
| `voc-schepen.png` | Dutch flute Zeehaen and war yacht Heemskerck | Public domain | 2.6 MB |
| `tasman-portret-cuyp.jpg` | Abel Tasman - Cuyp (cropped) | Public domain | 45 KB |
| `kaart-tasman-1644.jpg` | Tasmanmap1644 | Public domain | 344 KB |
| `mauritius-voc.jpg` | Fort Mauritius on Makian | Public domain | 419 KB |
| `duyfken-replica.jpg` | Duyfken replica at Denham shark bay | Public domain | 416 KB |
| `voc-schip-dodo.jpg` | Dodo (VOC Gelderland, 1602) | Public domain | 273 KB |

All files verified: JPEG/PNG, >30 KB, valid image data.

Credits metadata: `public/lessons/tasman/credits.json`

---

## Dataset Gaps — Not Yet Available in Corpus or Commons

Sourcing challenges (subjects with few or no public-domain hits after 3+ searches):

### High Priority

- **Gilsemans' journal & drawings** — Detailed sketches of New Zealand coast (Mohua/Golden Bay detail maps, Māori vessels, cultural observations). Original manuscript in Dutch archives; facsimile scans rare in Commons.
- **Heemskerck & Zeehaen detailed schematics** — Ship construction plans / cross-sections. No high-res PD blueprints located.
- **Tasman's 1644 return voyage route map** — Distinct from 1642 journey; limited historical charts available PD.
- **Mauritius VOC settlement engravings** — Post-1662 images; Commons has sparse 17th-century colony artwork.

### Secondary (Enrichment)

- **Māori oral-history perspective sources** — Whakatau/Whakatau-rangi (oral accounts of first European contact). Copyrights held by iwi; requires partnership/consent.
- **Tongatapu / Tonga Gilsemans drawings** — The artist's secondary voyage artwork (late 1642). Rare in public repositories.
- **Isaac Gilsemans biography images** — Contemporaries, merchant guild records. Few portraits or period documents exist.
- **Nanban art / European-Asian contact imagery** — Comparative visual (Japanese perspective on early VOC visitors). Limited PD examples.

---

## Recommendations for Future Expansion

1. **Corpus enrichment:** Add these 8 verified PD images to `artworks` table with Tasman/Gilsemans/VOC tags.
2. **Archive partnerships:** Contact Dutch National Library (KB), Amsterdam Museum, Tasmanie Archives for facsimile licensing of Gilsemans originals.
3. **Scholarship layer:** Flag high-priority gaps in lesson authoring UI so teachers can request images as lesson design progresses.
4. **Regional context:** Mauritius (Ile de France) VOC holdings require deeper 17th-century colonial art search; consider specialist searches.

---

## Verification Completed

- Commons API queries: ✅ 8 searches executed
- File downloads: ✅ All 8 files verified (JPEG/PNG, metadata complete)
- License compliance: ✅ All public domain; safe for educational use
- Credits: ✅ JSON manifest created

**Status:** Ready for lesson content integration.
