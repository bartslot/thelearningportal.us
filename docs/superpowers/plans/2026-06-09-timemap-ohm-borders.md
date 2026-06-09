# Time-Map → OpenHistoricalMap Borders (J-5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Time-Map's static `historical-basemaps` snapshot borders with locally-mirrored OpenHistoricalMap (OHM) vector tiles, giving continuous time-accurate borders, real per-feature lifespans, and curated extents.

**Architecture:** Mirror OHM `ohm_admin` (`boundaries`) + `osm_land` low-zoom tiles into `public/ohm-tiles/` (served statically, gitignored). MapLibre renders them with the existing atlas styling; the `maplibre-gl-dates` plugin drives `filterByDate(year)` from the slider for continuous filtering. Enrichment (`polities`, re-keyed by `osm_id`) comes from OHM Overpass (precise `wikidata` QIDs) + lazy-on-click.

**Tech Stack:** Laravel 12, Livewire/Alpine, MapLibre GL JS + `maplibre-gl-dates`, Vite, PostGIS; PHPUnit + Playwright. External: OHM vtiles + Overpass, Wikidata/Wikipedia/Commons.

**Spec:** `docs/superpowers/specs/2026-06-09-timemap-ohm-borders-design.md`

**Conventions (already in the repo):**
- Corpus via the `pgsql_corpus` connection; corpus tables created with raw SQL.
- Static assets under `public/`; the map fetches them directly.
- External HTTP via the `Http` facade with a **descriptive User-Agent** (OHM/Wikimedia 403 the default UA — see `app/Services/WikidataPolityResolver.php::USER_AGENT`); `Http::fake()` in tests, never the network.
- Dev server runs `APP_ENV=production` (opcache): **restart after PHP changes** (`pkill -f "artisan serve"; php artisan serve --port=8000 &`).
- Tests: `php artisan test --filter=…` (local PostGIS) + `npx playwright test`. The in-tool browser is flaky → rely on Playwright + the user's browser for visuals.

---

## ⚠️ Task 1 is a SPIKE that GATES the rest. Do not start Tasks 2–6 until the controller reviews the spike findings and gives a go.

---

## Task 1: Phase-0 spike — verify OHM before committing

**Goal:** confirm OHM coverage/quality, the time-filter, tile sizes, and Overpass `wikidata` — then STOP for go/no-go. This task writes findings into this file (a "Spike findings" block at the bottom) and **commits nothing else**.

- [ ] **Step 1: Coverage at three eras.** For 235 BCE, 1000 CE, 1850 CE, fetch a low-zoom Europe `ohm_admin` tile and decode feature names. Use Node with `@mapbox/vector-tile` + `pbf` (already transitively available via maplibre, but install if missing: `npm i -D @mapbox/vector-tile pbf`). Save `scripts/spike-ohm.mjs`:

```js
import { VectorTile } from '@mapbox/vector-tile';
import Protobuf from 'pbf';
const UA = 'TheLearningPortal/1.0 (educational; bartslot@gmail.com)';
// z4 tiles covering Europe (x in 7..9, y in 4..6 at z4 roughly cover W/Central Europe)
const tiles = [[4, 8, 5], [4, 9, 5]];
for (const [z, x, y] of tiles) {
  const r = await fetch(`https://vtiles.openhistoricalmap.org/maps/ohm_admin/${z}/${x}/${y}.pbf`, { headers: { 'User-Agent': UA } });
  const buf = new Uint8Array(await r.arrayBuffer());
  const tile = new VectorTile(new Protobuf(buf));
  const layer = tile.layers['boundaries'];
  const names = new Set();
  for (let i = 0; i < (layer?.length ?? 0); i++) {
    const f = layer.feature(i).properties;
    names.add(`${f.name} [${f.start_decdate}..${f.end_decdate}] adm=${f.admin_level}`);
  }
  console.log(`z${z}/${x}/${y}: ${buf.length} bytes, ${layer?.length ?? 0} features`);
  console.log([...names].slice(0, 40).join('\n'));
}
```

Run `node scripts/spike-ohm.mjs`. **Record:** do Gaul/Roman Republic/Carthage (235 BCE), and the HRE/Kingdoms (1000/1850 CE) appear with sane `start_decdate`/`end_decdate`? Are admin_levels usable for filtering?

- [ ] **Step 2: BCE date-filter sanity.** Confirm `start_decdate`/`end_decdate` are negative for BCE (e.g. Roman Republic ≈ -509..-27) so a numeric MapLibre filter `['all', ['<=', ['get','start_decdate'], Y], ['>', ['get','end_decdate'], Y]]` works for BCE and CE. Note whether the `maplibre-gl-dates` plugin (`npm view maplibre-gl-dates`) supports BCE, or whether we use a manual filter expression instead.

- [ ] **Step 3: Tile size/zoom bound.** For z0–5 over the Europe bbox, count tiles and sum bytes (extend the script to walk the ranges). Record total MB **as-is**; then estimate the size if only `['name','name_en','admin_level','start_decdate','end_decdate','osm_id','has_label','type']` are kept (i.e. is re-tiling worth it, or is z0–5 acceptable raw?). Decide the **zoom bound** and **whether to re-tile**.

- [ ] **Step 4: Overpass wikidata.** Query OHM Overpass for a couple of European admin boundaries and confirm the `wikidata` tag is present:

```bash
curl -s -A "TheLearningPortal/1.0 (educational; bartslot@gmail.com)" \
  "https://overpass-api.openhistoricalmap.org/api/interpreter" \
  --data 'data=[out:json][timeout:25];relation["boundary"="administrative"]["name"="Roman Republic"];out tags 1;' | head -c 800
```

Record whether `wikidata` (and `name`, `admin_level`) come back, and the exact endpoint that works.

- [ ] **Step 5: Write findings + go/no-go.** Append a `## Spike findings` section to THIS plan file with: coverage verdict per era, the chosen zoom bound + re-tile decision, the date-filter approach (plugin vs manual expression — with the exact call), and the Overpass endpoint + whether `wikidata` is available. Commit only this doc update:

```bash
git add docs/superpowers/plans/2026-06-09-timemap-ohm-borders.md scripts/spike-ohm.mjs
git commit -m "spike(ohm): verify coverage, date filter, tile sizes, overpass wikidata"
```

**Then STOP and report to the controller.** If coverage is good → proceed. If ancient coverage is thin → the controller decides (e.g. OHM for medieval+modern, keep snapshots for antiquity, or pick another source).

> **Tasks 2–6 below assume the spike's likely outcome (z0–5 raw mirror acceptable; manual `start_decdate`/`end_decdate` filter expression; Overpass provides `wikidata`). The controller adjusts the constants marked `[SPIKE]` to the measured values before dispatching each task.**

---

## Task 2: `timemap:fetch-ohm-tiles` — mirror OHM tiles locally

**Files:**
- Create: `app/Console/Commands/FetchOhmTiles.php`
- Modify: `.gitignore`

- [ ] **Step 1: gitignore the tile cache.** Append to `.gitignore`:

```
/public/ohm-tiles/
```

- [ ] **Step 2: Write the command.** Create `app/Console/Commands/FetchOhmTiles.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Mirror OpenHistoricalMap vector tiles into public/ohm-tiles so the Time-Map serves them
 * statically (no live dependency). Low-zoom only — the map is an overview navigation tool.
 */
class FetchOhmTiles extends Command
{
    protected $signature = 'timemap:fetch-ohm-tiles {--maxzoom=5}'; // [SPIKE] zoom bound

    protected $description = 'Mirror OHM admin + land vector tiles to public/ohm-tiles';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    /** Europe-biased coverage box [west, south, east, north]; world at z0-2 for context. */
    private array $bbox = [-25.0, 30.0, 50.0, 72.0];

    /** tileset name => base URL. */
    private array $tilesets = [
        'ohm_admin' => 'https://vtiles.openhistoricalmap.org/maps/ohm_admin',
        'osm_land' => 'https://vtiles.openhistoricalmap.org/maps/osm_land',
    ];

    public function handle(): int
    {
        $maxZoom = (int) $this->option('maxzoom');
        $base = public_path('ohm-tiles');
        $got = 0;
        $bytes = 0;

        foreach ($this->tilesets as $set => $url) {
            for ($z = 0; $z <= $maxZoom; $z++) {
                [$x0, $y0, $x1, $y1] = $this->tileRange($z);
                for ($x = $x0; $x <= $x1; $x++) {
                    for ($y = $y0; $y <= $y1; $y++) {
                        $res = Http::withHeaders(['User-Agent' => self::UA])->get("{$url}/{$z}/{$x}/{$y}.pbf");
                        if (! $res->ok()) {
                            continue; // 404 = empty tile, skip
                        }
                        $dir = "{$base}/{$set}/{$z}/{$x}";
                        File::ensureDirectoryExists($dir);
                        File::put("{$dir}/{$y}.pbf", $res->body());
                        $got++;
                        $bytes += strlen($res->body());
                    }
                }
            }
            $this->info("{$set}: mirrored to z{$maxZoom}");
        }

        $this->info(sprintf('fetched %d tiles, %.1f MB', $got, $bytes / 1048576));

        return self::SUCCESS;
    }

    /** Web-mercator tile x/y range covering $this->bbox at zoom $z (world at z<=2). */
    private function tileRange(int $z): array
    {
        $n = 2 ** $z;
        if ($z <= 2) {
            return [0, 0, $n - 1, $n - 1]; // whole world for context at the lowest zooms
        }
        [$w, $s, $e, $north] = $this->bbox;
        $lon2x = fn (float $lon) => (int) floor(($lon + 180) / 360 * $n);
        $lat2y = function (float $lat) use ($n) {
            $r = deg2rad($lat);
            return (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n);
        };

        return [max(0, $lon2x($w)), max(0, $lat2y($north)), min($n - 1, $lon2x($e)), min($n - 1, $lat2y($s))];
    }
}
```

- [ ] **Step 3: Smoke it (networked, controller runs).** `php artisan timemap:fetch-ohm-tiles --maxzoom=5` → prints a tile count + MB; `ls public/ohm-tiles/ohm_admin/4/` shows `.pbf` files. (No unit test — it's a network mirror; verified by the smoke + the Playwright map load in Task 5.)

- [ ] **Step 4: Commit.**

```bash
git add app/Console/Commands/FetchOhmTiles.php .gitignore
git commit -m "feat(ohm): timemap:fetch-ohm-tiles mirrors OHM tiles to public/ohm-tiles"
```

---

## Task 3: `resolveByQid` + `timemap:sync-ohm-polities`

**Files:**
- Modify: `app/Services/WikidataPolityResolver.php`
- Create: `app/Console/Commands/SyncOhmPolities.php`
- Modify: `tests/Feature/Concerns/SeedsCorpusFixtures.php` (polities table gains `osm_id`)
- Test: `tests/Feature/TimeMap/SyncOhmPolitiesTest.php`, extend `EnrichPolitiesTest`

- [ ] **Step 1: Add `osm_id` to the polities fixture + a `resolveByQid` failing test.**

In `tests/Feature/Concerns/SeedsCorpusFixtures.php`, in the `public.polities` CREATE TABLE, add `osm_id text` as a column (after `polity_id text`) and add `'osm_id' => null` to the `seedPolity` defaults.

Append to `tests/Feature/TimeMap/EnrichPolitiesTest.php`:

```php
    public function test_resolve_by_qid_returns_enrichment(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => \Illuminate\Support\Facades\Http::response([
                'entities' => ['Q31' => [
                    'claims' => [
                        'P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]],
                        'P571' => [['mainsnak' => ['datavalue' => ['value' => ['time' => '+1830-01-01T00:00:00Z']]]]],
                    ],
                    'labels' => ['en' => ['value' => 'Belgium']],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => \Illuminate\Support\Facades\Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
            'www.wikidata.org/w/api.php*' => \Illuminate\Support\Facades\Http::response(['entities' => []]),
        ]);

        $data = app(\App\Services\WikidataPolityResolver::class)->resolveByQid('Q31');

        $this->assertSame('Q31', $data['wikidata_id']);
        $this->assertSame('Belgium', $data['label']);
        $this->assertSame(1830, $data['inception']);
        $this->assertStringContainsString('Western Europe', $data['summary']);
        $this->assertSame('Flag of Belgium.svg', $data['flag_commons']);
    }
```

Run `php artisan test --filter=test_resolve_by_qid_returns_enrichment` → FAIL (method missing).

- [ ] **Step 2: Implement `resolveByQid`.** In `app/Services/WikidataPolityResolver.php`, refactor so the entity→enrichment mapping is shared. Add:

```php
    /**
     * Enrich directly from a known Wikidata QID (OHM features carry the QID, so no name search).
     *
     * @return array<string,mixed> same shape as resolve()
     */
    public function resolveByQid(string $qid): array
    {
        $entity = $this->client()->get("https://www.wikidata.org/wiki/Special:EntityData/{$qid}.json")
            ->json("entities.{$qid}", []);
        if ($entity === []) {
            return $this->emptyResult();
        }

        return $this->fromEntity($qid, $entity, $entity['labels']['en']['value'] ?? $qid);
    }
```

Extract the existing `resolve()` tail (the array build + summary fetch + label resolution) into a private `fromEntity(string $qid, array $entity, string $label): array`, and add a private `emptyResult(): array` returning the `$empty` array. Have `resolve()` call `fromEntity()` too (DRY). Keep `labelsFor()` and `year()`/`itemQid()`.

Run the test → PASS. Run `php artisan test --filter=EnrichPolitiesTest` → all green.

- [ ] **Step 3: Write the failing sync test.** Create `tests/Feature/TimeMap/SyncOhmPolitiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class SyncOhmPolitiesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_sync_creates_polities_keyed_by_osm_id_from_overpass(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'overpass-api.openhistoricalmap.org/*' => Http::response(['elements' => [
                ['type' => 'relation', 'id' => 12345, 'tags' => ['name' => 'Kingdom of Belgium', 'admin_level' => '2', 'wikidata' => 'Q31']],
                ['type' => 'relation', 'id' => 999, 'tags' => ['name' => 'No Wikidata Land', 'admin_level' => '4']],
            ]]),
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response(['entities' => ['Q31' => [
                'claims' => ['P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]]],
                'labels' => ['en' => ['value' => 'Belgium']], 'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
            ]]]),
            'en.wikipedia.org/*' => Http::response(['extract' => 'Belgium is in Western Europe.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']]]),
            'commons.wikimedia.org/*' => Http::response('PNG', 200, ['Content-Type' => 'image/png']),
            'www.wikidata.org/w/api.php*' => Http::response(['entities' => []]),
        ]);

        $this->artisan('timemap:sync-ohm-polities')->assertExitCode(0);

        $belgium = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', 'r12345')->first();
        $this->assertNotNull($belgium);
        $this->assertSame('Q31', $belgium->wikidata_id);
        $this->assertStringContainsString('Western Europe', $belgium->summary);

        // No-wikidata feature is recorded (clickable) but unenriched.
        $other = DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', 'r999')->first();
        $this->assertNotNull($other);
        $this->assertNull($other->wikidata_id);

        @unlink(public_path('flags/r12345.png'));
    }
}
```

Run → FAIL (command missing).

- [ ] **Step 4: Implement the sync command.** Create `app/Console/Commands/SyncOhmPolities.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikidataPolityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class SyncOhmPolities extends Command
{
    protected $signature = 'timemap:sync-ohm-polities';

    protected $description = 'Pre-enrich major OHM admin boundaries (admin_level<=4 / has_label) into polities, keyed by osm_id';

    private const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';

    public function handle(WikidataPolityResolver $resolver): int
    {
        $this->ensureTable();
        $flagsDir = public_path('flags');
        File::ensureDirectoryExists($flagsDir);
        $corpus = DB::connection('pgsql_corpus');

        // Europe admin boundaries, major levels only.
        $query = '[out:json][timeout:120];relation["boundary"="administrative"]["admin_level"~"^[1-4]$"]'
            .'(30.0,-25.0,72.0,50.0);out tags;';
        $elements = Http::withHeaders(['User-Agent' => self::UA])
            ->asForm()->post('https://overpass-api.openhistoricalmap.org/api/interpreter', ['data' => $query])
            ->json('elements', []);

        $count = 0;
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? null;
            if (! $name) {
                continue;
            }
            $osmId = ($el['type'][0] ?? 'r').$el['id']; // e.g. r12345
            $qid = $tags['wikidata'] ?? null;

            $data = $qid ? $resolver->resolveByQid($qid) : $resolver->resolve($name);

            $flagPath = null;
            if (! empty($data['flag_commons'])) {
                $flagPath = "/flags/{$osmId}.png";
                $bytes = Http::withHeaders(['User-Agent' => self::UA])
                    ->get('https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($data['flag_commons']).'?width=80')->body();
                File::put($flagsDir.'/'.$osmId.'.png', $bytes);
            }

            $corpus->table('public.polities')->updateOrInsert(
                ['osm_id' => $osmId],
                [
                    'polity_id' => $osmId,
                    'label' => $data['label'] ?? $name,
                    'wikidata_id' => $data['wikidata_id'],
                    'flag_path' => $flagPath,
                    'summary' => $data['summary'],
                    'wikipedia_url' => $data['wikipedia_url'],
                    'inception' => $data['inception'],
                    'dissolution' => $data['dissolution'],
                    'predecessor' => $data['predecessor'],
                    'successor' => $data['successor'],
                    'sitelinks' => $data['sitelinks'],
                    'significant' => true,
                    'updated_at' => now(),
                ]
            );
            $count++;
        }

        $this->info("synced {$count} OHM polities");

        return self::SUCCESS;
    }

    private function ensureTable(): void
    {
        DB::connection('pgsql_corpus')->statement('
            CREATE TABLE IF NOT EXISTS public.polities (
                polity_id text primary key, osm_id text unique, label text, wikidata_id text, flag_path text,
                summary text, wikipedia_url text, inception integer, dissolution integer,
                predecessor text, successor text, sitelinks integer default 0,
                significant boolean default true, updated_at timestamptz
            )');
        DB::connection('pgsql_corpus')->statement('ALTER TABLE public.polities ADD COLUMN IF NOT EXISTS osm_id text');
    }
}
```

Run `php artisan test --filter=SyncOhmPolitiesTest` → PASS. Pint. Commit:

```bash
./vendor/bin/pint app/Services/WikidataPolityResolver.php app/Console/Commands/SyncOhmPolities.php
git add app/Services/WikidataPolityResolver.php app/Console/Commands/SyncOhmPolities.php tests/Feature/TimeMap/SyncOhmPolitiesTest.php tests/Feature/TimeMap/EnrichPolitiesTest.php tests/Feature/Concerns/SeedsCorpusFixtures.php
git commit -m "feat(ohm): resolveByQid + timemap:sync-ohm-polities (osm_id-keyed enrichment)"
```

- [ ] **Step 5: Real sync (controller runs, networked, ALTER the live table first).** `php artisan tinker --execute='DB::connection("pgsql_corpus")->statement("ALTER TABLE public.polities ADD COLUMN IF NOT EXISTS osm_id text");'` then `php artisan timemap:sync-ohm-polities`.

---

## Task 4: Point the map at local OHM tiles + continuous date filter

**Files:**
- Modify: `resources/js/timemap/index.js`
- Modify: `package.json` (add `maplibre-gl-dates` if the spike chose the plugin; otherwise skip)

- [ ] **Step 1: Sources + base.** In `resources/js/timemap/index.js`, replace the `style: 'https://demotiles.maplibre.org/style.json'` map init with an inline style using the **local** tiles, and replace `setBoundaries()`/`setLabels()`/`snapshotFor` with OHM-source layers. The map style:

```js
  const map = new maplibregl.Map({
    container: el,
    style: {
      version: 8,
      glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
      sources: {
        land: { type: 'vector', tiles: [`${location.origin}/ohm-tiles/osm_land/{z}/{x}/{y}.pbf`], maxzoom: 5 },
        ohm: { type: 'vector', tiles: [`${location.origin}/ohm-tiles/ohm_admin/{z}/{x}/{y}.pbf`], maxzoom: 5 },
      },
      layers: [
        { id: 'water', type: 'background', paint: { 'background-color': '#d8e9f3' } },
        { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': '#f3ead6' } },
      ],
    },
    center: [15, 50], zoom: 4,
  });
```

(`source-layer` for `osm_land` is `land` — confirm against the spike's tilejson if different.)

- [ ] **Step 2: Boundary fill + labels from OHM, filtered by date.** After `map.on('load')`, add the boundary layers (replacing the old `boundaries`/`labels` GeoJSON layers). Colour by a hash of `osm_id`; filter prominence by `admin_level`; date label from `start_decdate`/`end_decdate`:

```js
  const ATLAS_PALETTE = ['#c9b79c','#a8b9a0','#cbb3a1','#b6a8c0','#c2c0a0','#a9bcc4','#d0bfa8','#b9a99a','#aebfa6','#c8b6b0','#b0b6a0','#bcae9e'];
  const fillColor = ['case',
    ['boolean', ['feature-state', 'selected'], false], '#f5c518',
    ['boolean', ['feature-state', 'hover'], false], '#ecd9a0',
    ['match', ['%', ['abs', ['to-number', ['get', 'osm_id']]], 12], ...ATLAS_PALETTE.flatMap((c, i) => [i, c]), ATLAS_PALETTE[0]],
  ];

  map.on('load', () => {
    map.addLayer({ id: 'boundaries-fill', type: 'fill', source: 'ohm', 'source-layer': 'boundaries',
      paint: { 'fill-color': fillColor, 'fill-opacity': 0.7 } });
    map.addLayer({ id: 'boundaries-line', type: 'line', source: 'ohm', 'source-layer': 'boundaries',
      paint: { 'line-color': '#9a7b4f', 'line-width': 0.6, 'line-opacity': 0.6 } });
    map.addLayer({ id: 'boundaries-label', type: 'symbol', source: 'ohm', 'source-layer': 'boundaries',
      filter: ['<=', ['to-number', ['get', 'admin_level']], 4],
      layout: { 'text-field': ['get', 'name'], 'text-size': 11, 'text-allow-overlap': false, 'symbol-placement': 'point' },
      paint: { 'text-color': '#3b3326', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 } });

    applyYear(state.year);
    state.ready = true; sync();
  });
```

- [ ] **Step 3: Continuous date filter from the slider.** Add `applyYear(year)` and replace `el._setYear`:

```js
  // [SPIKE] If maplibre-gl-dates was chosen: import filterByDate and call map.filterByDate(...).
  // Default (manual expression): filter each OHM layer by start/end decdate.
  const dateFilter = (year) => ['all',
    ['<=', ['coalesce', ['to-number', ['get', 'start_decdate']], -1e6], year],
    ['>', ['coalesce', ['to-number', ['get', 'end_decdate']], 1e6], year],
  ];
  const applyYear = (year) => {
    for (const id of ['boundaries-fill', 'boundaries-line', 'boundaries-label']) {
      const base = id === 'boundaries-label' ? ['<=', ['to-number', ['get', 'admin_level']], 4] : true;
      map.setFilter(id, ['all', base === true ? ['literal', true] : base, dateFilter(year)]);
    }
  };

  el._setYear = (year) => { state.year = year; wire.year = year; applyYear(year); sync(); return formatReadout(year); };
```

- [ ] **Step 4: Build + Playwright (controller, needs the mirrored tiles + real sync).** `npm run build`; then the Playwright run in Task 6. (No isolated JS unit test; Playwright covers it.) Commit:

```bash
git add resources/js/timemap/index.js package.json package-lock.json
git commit -m "feat(ohm): render local OHM tiles with continuous date filter (drop static snapshots)"
```

---

## Task 5: Click → panel by `osm_id` + lazy enrichment

**Files:**
- Modify: `routes/web.php` (the `timemap.polity` endpoint: by `osm_id`, lazy-enrich on miss)
- Modify: `resources/js/timemap/index.js` (click reads `osm_id`)
- Test: extend `tests/Feature/TimeMap/PolityEndpointTest.php`

- [ ] **Step 1: Failing test for lazy enrich.** Add to `PolityEndpointTest`:

```php
    public function test_unknown_osm_id_lazy_enriches_and_caches(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'www.wikidata.org/w/api.php*' => \Illuminate\Support\Facades\Http::response(['search' => [['id' => 'Q142', 'label' => 'France']]]),
            'www.wikidata.org/wiki/Special:EntityData/Q142.json' => \Illuminate\Support\Facades\Http::response(['entities' => ['Q142' => ['claims' => [], 'labels' => ['en' => ['value' => 'France']], 'sitelinks' => ['enwiki' => ['title' => 'France']]]]]),
            'en.wikipedia.org/*' => \Illuminate\Support\Facades\Http::response(['extract' => 'France is in Western Europe.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/France']]]),
        ]);

        $teacher = \App\Models\User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/r71?name='.urlencode('France'))
            ->assertOk()
            ->assertJsonPath('summary', 'France is in Western Europe.');

        // cached
        $this->assertNotNull(\Illuminate\Support\Facades\DB::connection('pgsql_corpus')->table('public.polities')->where('osm_id', 'r71')->first());
    }
```

Run → FAIL.

- [ ] **Step 2: Update the route.** Replace the `timemap.polity` route body in `routes/web.php`:

```php
    Route::get('/timemap/polity/{osmId}', function (\Illuminate\Http\Request $request, string $osmId) {
        $corpus = \Illuminate\Support\Facades\DB::connection('pgsql_corpus');
        $p = $corpus->table('public.polities')->where('osm_id', $osmId)->first();

        // Lazy-enrich on a miss when the click provides a name.
        if (! $p && $request->filled('name')) {
            $data = app(\App\Services\WikidataPolityResolver::class)->resolve($request->string('name'));
            $corpus->table('public.polities')->updateOrInsert(['osm_id' => $osmId], [
                'polity_id' => $osmId, 'label' => $data['label'] ?? $request->string('name'),
                'wikidata_id' => $data['wikidata_id'], 'summary' => $data['summary'],
                'wikipedia_url' => $data['wikipedia_url'], 'inception' => $data['inception'],
                'dissolution' => $data['dissolution'], 'predecessor' => $data['predecessor'],
                'successor' => $data['successor'], 'sitelinks' => $data['sitelinks'],
                'significant' => true, 'updated_at' => now(),
            ]);
            $p = $corpus->table('public.polities')->where('osm_id', $osmId)->first();
        }

        return response()->json([
            'osm_id' => $osmId, 'label' => $p?->label, 'summary' => $p?->summary,
            'flag_path' => $p?->flag_path, 'wikipedia_url' => $p?->wikipedia_url,
            'inception' => $p?->inception !== null ? (int) $p->inception : null,
            'dissolution' => $p?->dissolution !== null ? (int) $p->dissolution : null,
            'predecessor' => $p?->predecessor, 'successor' => $p?->successor,
        ])->header('Cache-Control', 'public, max-age=86400');
    })->name('timemap.polity');
```

Run `php artisan test --filter=PolityEndpointTest` → PASS (update the existing test if it asserted `polity_id` instead of `osm_id`).

- [ ] **Step 3: Click reads osm_id + name.** In `index.js`, the click handler's `queryRenderedFeatures({ layers: ['boundaries-fill'] })[0]` now reads `hit.properties.osm_id` and `hit.properties.name`; dispatch `polity-selected` with `{ id: hit.properties.osm_id, name: hit.properties.name }`, and the blade aside's fetch becomes `'/teacher/timemap/polity/' + id + '?name=' + encodeURIComponent(name)`. Update the blade `x-on:polity-selected` accordingly. Feature-state hover/selected use the OHM feature id (set `promoteId: { boundaries: 'osm_id' }` on the source, or use `e.features[0].id`).

- [ ] **Step 4: Build, restart, test, commit.**

```bash
npm run build
pkill -f "artisan serve"; sleep 1; php artisan serve --port=8000 >/tmp/lp-serve.log 2>&1 &
php artisan test --filter='PolityEndpointTest|SyncOhmPolitiesTest|EnrichPolitiesTest'
git add routes/web.php resources/js/timemap/index.js resources/views/livewire/time-map.blade.php tests/Feature/TimeMap/PolityEndpointTest.php
git commit -m "feat(ohm): polity panel by osm_id with lazy enrich-on-click"
```

---

## Task 6: Retire the historical-basemaps pipeline + Playwright + docs

**Files:**
- Delete: `app/Console/Commands/ImportBoundaries.php`, `app/Console/Commands/ExportBoundaries.php`, `app/Console/Commands/VerifyTimemap.php`, `app/Models/Corpus/Boundary.php`, `database/data/historical-basemaps/`, `public/geo/boundaries/`, `public/geo/labels/`, `tests/Feature/TimeMap/BoundaryImportTest.php`, `tests/Feature/TimeMap/ExportSignificantTest.php`
- Modify: `tests/Feature/Concerns/SeedsCorpusFixtures.php` (drop the `boundaries` table + `seedBoundary` if now unused), `tests/playwright/timemap.spec.ts`, `docs/timemap-data-sources.md`

- [ ] **Step 1: Drop the boundaries table on Supabase + remove the dead code.**

```bash
php artisan tinker --execute='DB::connection("pgsql_corpus")->statement("DROP TABLE IF EXISTS public.boundaries");'
git rm app/Console/Commands/ImportBoundaries.php app/Console/Commands/ExportBoundaries.php app/Console/Commands/VerifyTimemap.php app/Models/Corpus/Boundary.php tests/Feature/TimeMap/BoundaryImportTest.php tests/Feature/TimeMap/ExportSignificantTest.php
git rm -r database/data/historical-basemaps public/geo/boundaries public/geo/labels
```

Remove the `public.boundaries` CREATE TABLE + `seedBoundary` from `SeedsCorpusFixtures.php` if no remaining test uses them (grep first: `grep -rl seedBoundary tests/`). If `EnrichPolitiesTest` still uses `seedBoundary`, keep it.

- [ ] **Step 2: Update the Playwright spec.** In `tests/playwright/timemap.spec.ts`, the slider test still works; update the click test to assert the panel opens for an OHM region (same shape as the current `timemap-click-panel` test — it asserts the aside gets content + the Wikipedia tab). Add an assertion that moving the year changes the rendered feature set: after `filterByDate`, `map.queryRenderedFeatures({layers:['boundaries-fill']}).length` differs between two distant years (expose a tiny `window.__featCount(year)` test hook in index.js if needed).

- [ ] **Step 3: Update docs.** Rewrite `docs/timemap-data-sources.md` borders section: source is now OHM (`ohm_admin`, CC0), mirrored by `timemap:fetch-ohm-tiles` into `public/ohm-tiles/` (gitignored), enriched by `timemap:sync-ohm-polities`. Regeneration: `fetch-ohm-tiles && sync-ohm-polities`.

- [ ] **Step 4: Full verification + commit.**

```bash
./vendor/bin/pint
git status   # ensure pint did not stage repo-wide churn; restore unrelated files if so
composer test   # atlas tests green; pre-existing unrelated failures may remain
npm run build
pkill -f "artisan serve"; sleep 1; php artisan serve --port=8000 >/tmp/lp-serve.log 2>&1 &
until curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/login | grep -qE '200|302'; do sleep 1; done
npx playwright test tests/playwright/timemap.spec.ts
git add -A
git commit -m "refactor(ohm): retire historical-basemaps pipeline; docs + playwright for OHM"
```

---

## Self-review notes (for the executor)

- **Task 1 is a gate.** Do not build until the spike findings are in and the controller approves. The `[SPIKE]` constants (zoom bound, re-tile yes/no, plugin vs manual date filter, `osm_land` source-layer name) get set from the findings.
- **`pint` with no args reformats the whole repo** — after running it, check `git status` and `git restore` any unrelated churn before committing (a known trap in this repo).
- **Restart the dev server after PHP changes** (production-env opcache).
- **CRITICAL — osm_id key format must match the TILE.** `polities.osm_id`, the click's `hit.properties.osm_id`, the endpoint key, and the sync-from-Overpass key must all use the **same** format. The plan tentatively uses `r{id}` from Overpass, but the OHM **tile's** `osm_id` property format (plain numeric `12345`? signed? `r12345`?) is **unconfirmed** — Task 1 Step 1 must record it, and the controller aligns the sync key + the `fill-color` hash (`['get','osm_id']`) + the click + the endpoint to that exact format before dispatching Tasks 3–5. If the tile gives plain numeric ids, drop the `r` prefix everywhere.
- **Names stay consistent:** the `boundaries` source-layer + `ohm`/`land` source ids, `resolveByQid`, `applyYear(year)`, `el._setYear`, the `polity-selected` event `{ id, name }`, the `/teacher/timemap/polity/{osmId}?name=` endpoint.
- **External HTTP is faked in tests**; the real fetch/sync runs are controller-run, networked, and slow.
- **Tiles are gitignored**, never committed.

---

## Spike findings (Task 1 — VERDICT: GO)

- **Coverage** (sampled z4 Europe tiles, decoded): real, time-accurate.
  - 235 BCE: Res Publica Romana (time-sliced 238–218 BCE), Qart-ḥadašt (Carthage), Kingdom of Pontus, Parthia, Massylii/Massaesylii (Numidia), Iberian tribes, Greek regions (Attica/Boeotia/Megaris), Egyptian nomes.
  - 1000 CE: 94 features (Welsh/Catalan/French counties, Castile, Venice).
  - 1850 CE: 236 features (Baden, Braunschweig, Bremen, Danish Amts, Italian/Spanish regions, British Empire).
  - Far richer + dated than historical-basemaps. (Gaul not in the limited sample; OHM models sub-regions — verify French coverage when the tiles are mirrored.)
- **osm_id format = NEGATIVE integer** (e.g. `-2851760`). Key `polities` by the raw tile osm_id (a negative int as text). In `sync-ohm-polities`, derive the same key from Overpass as `-{relation id}` (Overpass ids are positive). The fill-color hash uses `['abs', ['to-number', ['get','osm_id']]]`. **Drop the `r` prefix from the plan; no `r` anywhere.**
- **Date filter:** BCE decdates are negative; the manual expression `['all', ['<=', start_decdate, year], ['>', end_decdate, year]]` (coalesce undefined end_decdate to +1e6) works for BCE + CE. **No maplibre-gl-dates dependency** — skip the package.
- **Labels:** features carry native-language `name`; use `['coalesce', ['get','name_en'], ['get','name']]` for the English-school atlas.
- **Tile size:** z0–5 raw = 80.4 MB / 114 tiles (the ~300 `name_xx` fields dominate). **Decision: cap `FetchOhmTiles` at `--maxzoom=4` (~28 MB) raw, no re-tiling** (simplest; gitignored; navigation-tool low detail is fine at z≤4). Re-tiling to drop `name_xx` is a future optimization if needed.
- **Overpass:** `https://overpass-api.openhistoricalmap.org/api/interpreter` returns `wikidata`, `name`, `admin_level`, `start_date`, `end_date` for major admin relations. QID-precise enrichment confirmed.
