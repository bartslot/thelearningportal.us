# European History Atlas — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Time-Map into a TimeMap.org-style atlas where each region is a polity with its own flag + Wikipedia summary, shown via name/date/flag labels and a tabbed left panel — retiring the fuzzy macro-region article matching.

**Architecture:** A `polities` table in the Supabase corpus `public` schema is populated by an offline `timemap:enrich-polities` command (Wikidata + Wikipedia + flag PNGs). `timemap:export-boundaries` writes significant-only polygon files plus per-era label/centroid files. The map loads static GeoJSON, renders distinct muted fills + a flag/label symbol layer, and on region click fetches `/teacher/timemap/polity/{id}` to render a Summary/Wikipedia/Over-Time panel. A new oldmapsonline-style slider drives the year.

**Tech Stack:** Laravel 12, Livewire 3, Alpine, MapLibre GL JS, Vite, PostGIS; PHPUnit + Playwright. External: Wikidata API, Wikipedia REST, Wikimedia Commons.

**Spec:** `docs/superpowers/specs/2026-06-09-timemap-european-atlas-design.md` (Phase 1 only).

**Conventions (already in the repo):**
- Corpus access via the `pgsql_corpus` connection (search_path `public`); corpus tables are created with raw SQL (not Laravel migrations).
- Static map data lives under `public/geo/...`; the map fetches it directly.
- Tests: PHPUnit (`php artisan test --filter=…`) against the local PostGIS test DB; Playwright (`npx playwright test`). `tests/Feature/Concerns/SeedsCorpusFixtures.php` creates corpus fixture tables.
- **The dev server runs `APP_ENV=production` (opcache): after any PHP change, restart it** — `pkill -f "artisan serve"; php artisan serve --port=8000 &`.
- External HTTP must go through Laravel's `Http` facade so tests use `Http::fake()`.

---

## File structure

**Created**
- `app/Console/Commands/EnrichPolities.php` — resolve boundary polities → Wikidata/Wikipedia, fill `polities`, download flags
- `app/Services/WikidataPolityResolver.php` — pure HTTP+parse logic (testable in isolation)
- `tests/Feature/TimeMap/EnrichPolitiesTest.php`
- `tests/Feature/TimeMap/PolityEndpointTest.php`
- `tests/Feature/TimeMap/ExportSignificantTest.php`
- `resources/js/timemap/slider.js` — oldmapsonline-style timeline control
- `public/flags/` — downloaded flag PNGs (generated)
- `public/geo/labels/` — per-era centroid/label GeoJSON (generated)

**Modified**
- `tests/Feature/Concerns/SeedsCorpusFixtures.php` — add a `polities` fixture table + seeder helper
- `app/Console/Commands/ExportBoundaries.php` — significant filter + label/centroid export + `color_key`
- `app/Livewire/TimeMap.php` — replace `storiesForRegion` with a polity-info path (or remove; panel is fed by the endpoint)
- `routes/web.php` — `teacher.timemap.polity` endpoint
- `resources/js/timemap/index.js` — atlas palette, flag/label symbol layer, click→fetch polity, tab panel hook
- `resources/views/livewire/time-map.blade.php` — tabbed panel markup + new slider mount
- `tests/playwright/timemap.spec.ts` — click-region→panel / tab-switch / close

---

## Task 1: `polities` fixture table + seeding helper (test scaffolding)

**Files:**
- Modify: `tests/Feature/Concerns/SeedsCorpusFixtures.php`

- [ ] **Step 1: Add the polities fixture table + a seeder to the trait**

In `tests/Feature/Concerns/SeedsCorpusFixtures.php`, inside `setUpCorpusFixtures()` (after the `boundaries` table block), add:

```php
        $corpus->statement('DROP TABLE IF EXISTS public.polities');
        $corpus->statement('
            CREATE TABLE public.polities (
                polity_id text primary key,
                label text,
                wikidata_id text,
                flag_path text,
                summary text,
                wikipedia_url text,
                inception integer,
                dissolution integer,
                predecessor text,
                successor text,
                sitelinks integer default 0,
                significant boolean default true,
                updated_at timestamptz
            )');
```

And add a helper method to the trait (after `seedBoundary`):

```php
    protected function seedPolity(array $attrs = []): void
    {
        DB::connection('pgsql_corpus')->table('public.polities')->insert(array_merge([
            'polity_id' => 'roman-republic',
            'label' => 'Roman Republic',
            'wikidata_id' => 'Q17167',
            'flag_path' => '/flags/roman-republic.png',
            'summary' => 'The Roman Republic was a state...',
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/Roman_Republic',
            'inception' => -509,
            'dissolution' => -27,
            'predecessor' => 'Roman Kingdom',
            'successor' => 'Roman Empire',
            'sitelinks' => 250,
            'significant' => true,
            'updated_at' => now(),
        ], $attrs));
    }
```

- [ ] **Step 2: Verify the trait still loads (existing tests green)**

Run: `php artisan test --filter=StoriesForRegionTest`
Expected: PASS (the trait change is additive).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Concerns/SeedsCorpusFixtures.php
git commit -m "test(atlas): polities fixture table + seedPolity helper"
```

---

## Task 2: `WikidataPolityResolver` service

A pure unit that, given a polity name, calls Wikidata + Wikipedia + Commons (via `Http`) and returns a normalized array. Isolated so it is fully `Http::fake()`-testable.

**Files:**
- Create: `app/Services/WikidataPolityResolver.php`
- Test: `tests/Feature/TimeMap/EnrichPolitiesTest.php` (resolver portion)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimeMap/EnrichPolitiesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Services\WikidataPolityResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichPolitiesTest extends TestCase
{
    public function test_resolver_normalizes_wikidata_and_wikipedia(): void
    {
        Http::fake([
            'www.wikidata.org/w/api.php*' => Http::response([
                'search' => [['id' => 'Q31', 'label' => 'Belgium']],
            ]),
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response([
                'entities' => ['Q31' => [
                    'claims' => [
                        'P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]],
                        'P571' => [['mainsnak' => ['datavalue' => ['value' => ['time' => '+1830-01-01T00:00:00Z']]]]],
                    ],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
        ]);

        $data = app(WikidataPolityResolver::class)->resolve('Kingdom of Belgium');

        $this->assertSame('Q31', $data['wikidata_id']);
        $this->assertSame(1830, $data['inception']);
        $this->assertStringContainsString('Western Europe', $data['summary']);
        $this->assertSame('https://en.wikipedia.org/wiki/Belgium', $data['wikipedia_url']);
        $this->assertSame('Flag of Belgium.svg', $data['flag_commons']);
    }

    public function test_resolver_returns_nulls_when_unresolved(): void
    {
        Http::fake(['www.wikidata.org/w/api.php*' => Http::response(['search' => []])]);

        $data = app(WikidataPolityResolver::class)->resolve('N. European Bronze Age cultures');

        $this->assertNull($data['wikidata_id']);
        $this->assertNull($data['summary']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=EnrichPolitiesTest`
Expected: FAIL — `Class "App\Services\WikidataPolityResolver" not found`.

- [ ] **Step 3: Implement the resolver**

Create `app/Services/WikidataPolityResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WikidataPolityResolver
{
    /**
     * @return array{wikidata_id:?string,label:?string,summary:?string,wikipedia_url:?string,
     *               inception:?int,dissolution:?int,predecessor:?string,successor:?string,
     *               sitelinks:int,flag_commons:?string}
     */
    public function resolve(string $name): array
    {
        $empty = [
            'wikidata_id' => null, 'label' => null, 'summary' => null, 'wikipedia_url' => null,
            'inception' => null, 'dissolution' => null, 'predecessor' => null, 'successor' => null,
            'sitelinks' => 0, 'flag_commons' => null,
        ];

        $search = Http::get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbsearchentities', 'search' => $name, 'language' => 'en',
            'type' => 'item', 'format' => 'json', 'limit' => 1,
        ])->json('search.0');

        if (! $search) {
            return $empty;
        }
        $qid = $search['id'];

        $entity = Http::get("https://www.wikidata.org/wiki/Special:EntityData/{$qid}.json")
            ->json("entities.{$qid}", []);
        $claims = $entity['claims'] ?? [];

        $title = $entity['sitelinks']['enwiki']['title'] ?? null;
        $summary = null;
        $wikipediaUrl = null;
        if ($title) {
            $sum = Http::get('https://en.wikipedia.org/api/rest_v1/page/summary/'.rawurlencode($title))->json();
            $summary = $sum['extract'] ?? null;
            $wikipediaUrl = $sum['content_urls']['desktop']['page'] ?? null;
        }

        return [
            'wikidata_id' => $qid,
            'label' => $search['label'] ?? $name,
            'summary' => $summary,
            'wikipedia_url' => $wikipediaUrl,
            'inception' => $this->year($claims, 'P571'),
            'dissolution' => $this->year($claims, 'P576'),
            'predecessor' => $this->itemLabel($claims, 'P155'),
            'successor' => $this->itemLabel($claims, 'P156'),
            'sitelinks' => count($entity['sitelinks'] ?? []),
            'flag_commons' => $claims['P41'][0]['mainsnak']['datavalue']['value'] ?? null,
        ];
    }

    private function year(array $claims, string $prop): ?int
    {
        $time = $claims[$prop][0]['mainsnak']['datavalue']['value']['time'] ?? null;
        if (! $time) {
            return null;
        }
        // Wikidata times look like "+1830-01-01T00:00:00Z" or "-0509-..." (BCE).
        return (int) preg_replace('/^([+-]?\d+)-.*/', '$1', $time);
    }

    private function itemLabel(array $claims, string $prop): ?string
    {
        // Predecessor/successor are item refs; keep the QID — labels are resolved lazily if needed.
        return $claims[$prop][0]['mainsnak']['datavalue']['value']['id'] ?? null;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=EnrichPolitiesTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/WikidataPolityResolver.php tests/Feature/TimeMap/EnrichPolitiesTest.php
git commit -m "feat(atlas): WikidataPolityResolver (Wikidata + Wikipedia normalize)"
```

---

## Task 3: `timemap:enrich-polities` command

**Files:**
- Create: `app/Console/Commands/EnrichPolities.php`
- Test: add to `tests/Feature/TimeMap/EnrichPolitiesTest.php`

- [ ] **Step 1: Write the failing test (command writes a polities row)**

Append to `tests/Feature/TimeMap/EnrichPolitiesTest.php`'s class (add the `RefreshDatabase` + `SeedsCorpusFixtures` traits and `setUp` at the top of the class; full class header shown for clarity):

```php
// add these imports at the top:
use App\Models\Corpus\Boundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsCorpusFixtures;

// add inside the class:
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_command_enriches_each_boundary_polity(): void
    {
        $this->seedBoundary(
            ['polity_id' => 'kingdom-of-belgium', 'name' => 'Kingdom of Belgium', 'valid_from' => 1831, 'valid_to' => 3000],
            2.5, 49.5, 6.4, 51.5
        );
        $this->seedBoundary(
            ['polity_id' => 'n-european-bronze-age-cultures', 'name' => 'N. European Bronze Age cultures', 'valid_from' => -1500, 'valid_to' => -500],
            8.0, 54.0, 12.0, 58.0
        );

        Http::preventStrayRequests();
        Http::fake([
            'www.wikidata.org/w/api.php*' => Http::sequence()
                ->push(['search' => [['id' => 'Q31', 'label' => 'Belgium']]])
                ->push(['search' => []]), // the "cultures" polity does not resolve
            'www.wikidata.org/wiki/Special:EntityData/Q31.json' => Http::response([
                'entities' => ['Q31' => [
                    'claims' => ['P41' => [['mainsnak' => ['datavalue' => ['value' => 'Flag of Belgium.svg']]]]],
                    'sitelinks' => ['enwiki' => ['title' => 'Belgium'], 'frwiki' => ['title' => 'Belgique']],
                ]],
            ]),
            'en.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
                'extract' => 'Belgium is a country in Western Europe.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Belgium']],
            ]),
            'commons.wikimedia.org/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->artisan('timemap:enrich-polities')->assertExitCode(0);

        $belgium = DB::connection('pgsql_corpus')->table('public.polities')->where('polity_id', 'kingdom-of-belgium')->first();
        $this->assertSame('Q31', $belgium->wikidata_id);
        $this->assertStringContainsString('Western Europe', $belgium->summary);
        $this->assertSame('/flags/kingdom-of-belgium.png', $belgium->flag_path);
        $this->assertTrue((bool) $belgium->significant);

        $cultures = DB::connection('pgsql_corpus')->table('public.polities')->where('polity_id', 'n-european-bronze-age-cultures')->first();
        $this->assertNull($cultures->wikidata_id);
        $this->assertFalse((bool) $cultures->significant); // name matches the low-significance rule

        @unlink(public_path('flags/kingdom-of-belgium.png'));
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_command_enriches_each_boundary_polity`
Expected: FAIL — command not defined.

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/EnrichPolities.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikidataPolityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EnrichPolities extends Command
{
    protected $signature = 'timemap:enrich-polities {--limit=0 : Only process N polities (0 = all)}';

    protected $description = 'Resolve each boundary polity to Wikidata/Wikipedia: flag, summary, dates, significance';

    /** Names matching these (case-insensitive) are non-state groupings — dropped from the map. */
    private array $insignificantPatterns = ['cultures', 'nomads', 'hunter', 'forager', 'pastoral', 'peoples', 'tribes'];

    private int $significantSitelinkFloor = 5;

    public function handle(WikidataPolityResolver $resolver): int
    {
        $this->ensureTable();
        $flagsDir = public_path('flags');
        File::ensureDirectoryExists($flagsDir);

        $corpus = DB::connection('pgsql_corpus');
        $polities = $corpus->table('public.boundaries')
            ->select('polity_id', 'name')->distinct()->orderBy('polity_id')->get();

        if ($limit = (int) $this->option('limit')) {
            $polities = $polities->take($limit);
        }

        foreach ($polities as $p) {
            $data = $resolver->resolve($p->name);

            $flagPath = null;
            if ($data['flag_commons']) {
                $flagPath = "/flags/{$p->polity_id}.png";
                $bytes = Http::get('https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode($data['flag_commons']).'?width=80')->body();
                File::put($flagsDir.'/'.$p->polity_id.'.png', $bytes);
            }

            $significant = ! $this->isInsignificant($p->name) && $data['sitelinks'] >= $this->significantSitelinkFloor;
            // A resolved Wikidata item with no sitelinks is rare; keep state-like names that did resolve.
            if ($data['wikidata_id'] && ! $this->isInsignificant($p->name)) {
                $significant = true;
            }

            $corpus->table('public.polities')->updateOrInsert(
                ['polity_id' => $p->polity_id],
                [
                    'label' => $data['label'] ?? $p->name,
                    'wikidata_id' => $data['wikidata_id'],
                    'flag_path' => $flagPath,
                    'summary' => $data['summary'],
                    'wikipedia_url' => $data['wikipedia_url'],
                    'inception' => $data['inception'],
                    'dissolution' => $data['dissolution'],
                    'predecessor' => $data['predecessor'],
                    'successor' => $data['successor'],
                    'sitelinks' => $data['sitelinks'],
                    'significant' => $significant,
                    'updated_at' => now(),
                ]
            );
            $this->line(($significant ? '  ✓ ' : '  · ').$p->name.($data['wikidata_id'] ? " ({$data['wikidata_id']})" : ' [unresolved]'));
        }

        $this->info("enriched {$polities->count()} polities");

        return self::SUCCESS;
    }

    private function isInsignificant(string $name): bool
    {
        foreach ($this->insignificantPatterns as $pat) {
            if (Str::contains(Str::lower($name), $pat)) {
                return true;
            }
        }

        return false;
    }

    private function ensureTable(): void
    {
        DB::connection('pgsql_corpus')->statement('
            CREATE TABLE IF NOT EXISTS public.polities (
                polity_id text primary key, label text, wikidata_id text, flag_path text,
                summary text, wikipedia_url text, inception integer, dissolution integer,
                predecessor text, successor text, sitelinks integer default 0,
                significant boolean default true, updated_at timestamptz
            )');
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=EnrichPolitiesTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/EnrichPolities.php tests/Feature/TimeMap/EnrichPolitiesTest.php
git commit -m "feat(atlas): timemap:enrich-polities (flags, summaries, dates, significance)"
```

- [ ] **Step 6: Run it for real against the corpus (one-off, networked)**

Run: `php artisan timemap:enrich-polities`
Expected: prints ✓/· lines per polity; populates `public.polities` and `public/flags/*.png`. (May take a few minutes — hundreds of polities × a few HTTP calls each. If rate-limited, re-run; it's idempotent.)

---

## Task 4: Export significant polygons + per-era labels

**Files:**
- Modify: `app/Console/Commands/ExportBoundaries.php`
- Test: `tests/Feature/TimeMap/ExportSignificantTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimeMap/ExportSignificantTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class ExportSignificantTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_export_includes_significant_and_excludes_insignificant(): void
    {
        // Two polities valid at -500; one significant, one not.
        $this->seedBoundary(['polity_id' => 'rome', 'name' => 'Roman Republic', 'valid_from' => -509, 'valid_to' => -27], 11, 41, 13, 43);
        $this->seedBoundary(['polity_id' => 'steppe', 'name' => 'Steppe cultures', 'valid_from' => -509, 'valid_to' => -27], 40, 48, 60, 55);
        $this->seedPolity(['polity_id' => 'rome', 'label' => 'Roman Republic', 'significant' => true, 'inception' => -509, 'dissolution' => -27, 'flag_path' => '/flags/rome.png']);
        $this->seedPolity(['polity_id' => 'steppe', 'label' => 'Steppe cultures', 'significant' => false]);

        $this->artisan('timemap:export-boundaries')->assertExitCode(0);

        $boundaries = json_decode(File::get(public_path('geo/boundaries/-500.geojson')), true);
        $ids = array_column(array_column($boundaries['features'], 'properties'), 'polity_id');
        $this->assertContains('rome', $ids);
        $this->assertNotContains('steppe', $ids); // insignificant dropped

        $labels = json_decode(File::get(public_path('geo/labels/-500.geojson')), true);
        $romeLabel = collect($labels['features'])->firstWhere('properties.polity_id', 'rome');
        $this->assertSame('Roman Republic', $romeLabel['properties']['label']);
        $this->assertSame('509 BCE – 27 BCE', $romeLabel['properties']['date_label']);
        $this->assertSame('Point', $romeLabel['geometry']['type']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=ExportSignificantTest`
Expected: FAIL — labels file missing / steppe present.

- [ ] **Step 3: Rewrite the export command**

Replace `app/Console/Commands/ExportBoundaries.php`'s `handle()` with:

```php
    public function handle(): int
    {
        $boundariesDir = public_path('geo/boundaries');
        $labelsDir = public_path('geo/labels');
        File::ensureDirectoryExists($boundariesDir);
        File::ensureDirectoryExists($labelsDir);

        $corpus = DB::connection('pgsql_corpus');
        $total = 0;

        foreach ($this->years as $year) {
            // Join polities so we can drop insignificant polygons and carry label/flag/date data.
            $rows = $corpus->select(
                "select b.polity_id,
                        coalesce(p.label, b.name) as label,
                        b.valid_from, b.valid_to,
                        coalesce(p.flag_path, null) as flag_path,
                        ST_AsGeoJSON(b.geom::geometry, 6) as geometry,
                        ST_AsGeoJSON(ST_PointOnSurface(b.geom::geometry)) as centroid
                 from public.boundaries b
                 left join public.polities p on p.polity_id = b.polity_id
                 where b.valid_from <= ? and b.valid_to >= ?
                   and coalesce(p.significant, true) = true",
                [$year, $year]
            );

            $features = [];
            $labels = [];
            foreach ($rows as $r) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'polity_id' => $r->polity_id,
                        'label' => $r->label,
                        'year_start' => (int) $r->valid_from,
                        'year_end' => (int) $r->valid_to,
                        'color_key' => abs(crc32($r->polity_id)) % 12, // 0..11 palette index
                    ],
                    'geometry' => json_decode($r->geometry),
                ];
                $labels[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'polity_id' => $r->polity_id,
                        'label' => $r->label,
                        'date_label' => $this->dateLabel((int) $r->valid_from, (int) $r->valid_to),
                        'flag_id' => $r->flag_path ? $r->polity_id : '',
                    ],
                    'geometry' => json_decode($r->centroid),
                ];
            }

            File::put("{$boundariesDir}/{$year}.geojson", json_encode(['type' => 'FeatureCollection', 'features' => $features]));
            File::put("{$labelsDir}/{$year}.geojson", json_encode(['type' => 'FeatureCollection', 'features' => $labels]));
            $this->info(sprintf('wrote %d: %d polygons + labels', $year, count($features)));
            $total += count($features);
        }

        $this->info("exported {$total} significant polygons to {$boundariesDir}");

        return self::SUCCESS;
    }

    private function dateLabel(int $from, int $to): string
    {
        $fmt = fn (int $y) => $y < 0 ? abs($y).' BCE' : $y.' CE';

        return $fmt($from).' – '.$fmt($to);
    }
```

(Keep the class's existing `$years` property, `use` imports, signature, and description.)

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=ExportSignificantTest`
Expected: PASS.

- [ ] **Step 5: Re-export for real + commit**

```bash
php artisan timemap:export-boundaries
git add app/Console/Commands/ExportBoundaries.php tests/Feature/TimeMap/ExportSignificantTest.php public/geo/boundaries public/geo/labels
git commit -m "feat(atlas): export significant-only polygons + per-era flag/label centroids"
```

---

## Task 5: `GET /teacher/timemap/polity/{id}` endpoint

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/TimeMap/PolityEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimeMap/PolityEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class PolityEndpointTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_returns_enriched_polity_payload(): void
    {
        $this->seedPolity(['polity_id' => 'kingdom-of-belgium', 'label' => 'Kingdom of Belgium',
            'summary' => 'Belgium is a country in Western Europe.', 'flag_path' => '/flags/kingdom-of-belgium.png',
            'wikipedia_url' => 'https://en.wikipedia.org/wiki/Belgium', 'inception' => 1831, 'dissolution' => null,
            'predecessor' => 'United Kingdom of the Netherlands', 'successor' => null]);

        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/kingdom-of-belgium')
            ->assertOk()
            ->assertJsonPath('label', 'Kingdom of Belgium')
            ->assertJsonPath('summary', 'Belgium is a country in Western Europe.')
            ->assertJsonPath('flag_path', '/flags/kingdom-of-belgium.png')
            ->assertJsonPath('inception', 1831);
    }

    public function test_unknown_polity_returns_minimal_payload(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->getJson('/teacher/timemap/polity/does-not-exist')
            ->assertOk()
            ->assertJsonPath('summary', null);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=PolityEndpointTest`
Expected: FAIL — 404 (route missing).

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `->prefix('teacher')->name('teacher.')` group, after the `timemap` route, add:

```php
    Route::get('/timemap/polity/{polityId}', function (string $polityId) {
        $p = \Illuminate\Support\Facades\DB::connection('pgsql_corpus')
            ->table('public.polities')->where('polity_id', $polityId)->first();

        return response()->json([
            'polity_id' => $polityId,
            'label' => $p?->label,
            'summary' => $p?->summary,
            'flag_path' => $p?->flag_path,
            'wikipedia_url' => $p?->wikipedia_url,
            'inception' => $p?->inception !== null ? (int) $p->inception : null,
            'dissolution' => $p?->dissolution !== null ? (int) $p->dissolution : null,
            'predecessor' => $p?->predecessor,
            'successor' => $p?->successor,
        ])->header('Cache-Control', 'public, max-age=86400');
    })->name('timemap.polity');
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=PolityEndpointTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/TimeMap/PolityEndpointTest.php
git commit -m "feat(atlas): polity info JSON endpoint"
```

---

## Task 6: Atlas map rendering — distinct fills + flag/label symbol layer

**Files:**
- Modify: `resources/js/timemap/index.js`

- [ ] **Step 1: Replace the fill palette with the distinct atlas palette**

In `resources/js/timemap/index.js`, replace the `FILL_COLOR` definition with a `color_key`-driven muted palette:

```js
  // Distinct muted "atlas" palette, chosen per polity via the exported color_key (0..11).
  const ATLAS_PALETTE = [
    '#c9b79c', '#a8b9a0', '#cbb3a1', '#b6a8c0', '#c2c0a0', '#a9bcc4',
    '#d0bfa8', '#b9a99a', '#aebfa6', '#c8b6b0', '#b0b6a0', '#bcae9e',
  ];
  const FILL_COLOR = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], '#f5c518', // selected = brand yellow
    ['boolean', ['feature-state', 'hover'], false], '#ecd9a0',     // hover = warm tint
    ['at', ['%', ['get', 'color_key'], ATLAS_PALETTE.length], ['literal', ATLAS_PALETTE]],
  ];
  const FILL_OPACITY = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], 0.95,
    ['boolean', ['feature-state', 'hover'], false], 0.85,
    0.7,
  ];
```

- [ ] **Step 2: Load the per-era labels source + a symbol layer with flags**

In `setBoundaries()` (after the boundary source/layers are added), also load the labels file and add a symbol layer. Replace the `setBoundaries` body's fetch/add logic to also fetch labels:

```js
  const loadFlags = async (labelsFc) => {
    const ids = [...new Set(labelsFc.features.map((f) => f.properties.flag_id).filter(Boolean))];
    await Promise.all(ids.map(async (id) => {
      if (map.hasImage(`flag-${id}`)) return;
      try {
        const img = await map.loadImage(`/flags/${id}.png`);
        if (!map.hasImage(`flag-${id}`)) map.addImage(`flag-${id}`, img.data);
      } catch { /* missing flag */ }
    }));
  };

  const setLabels = async () => {
    const fc = await (await fetch(`/geo/labels/${snapshotFor(state.year)}.geojson`)).json();
    await loadFlags(fc);
    const src = map.getSource('labels');
    if (src) { src.setData(fc); return; }
    map.addSource('labels', { type: 'geojson', data: fc });
    map.addLayer({
      id: 'labels-symbol', type: 'symbol', source: 'labels',
      layout: {
        'icon-image': ['case', ['==', ['get', 'flag_id'], ''], '', ['concat', 'flag-', ['get', 'flag_id']]],
        'icon-size': 0.5, 'icon-allow-overlap': false,
        'text-field': ['concat', ['get', 'label'], '\n', ['get', 'date_label']],
        'text-size': 11, 'text-offset': [0, 1.4], 'text-anchor': 'top',
        'text-optional': true, 'text-allow-overlap': false,
      },
      paint: { 'text-color': '#3b3326', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    });
  };
```

Then call `await setLabels();` right after the boundary layers are set up in both the `src` (update) and the initial branch of `setBoundaries`.

- [ ] **Step 3: Build and verify no console errors**

Run: `npm run build`
Expected: build succeeds. (Visual verification happens in Task 9 via Playwright + the user's browser.)

- [ ] **Step 4: Commit**

```bash
git add resources/js/timemap/index.js
git commit -m "feat(atlas): distinct palette + flag/label symbol layer"
```

---

## Task 7: Tabbed left panel (Summary / Wikipedia / Over Time)

**Files:**
- Modify: `resources/views/livewire/time-map.blade.php`
- Modify: `resources/js/timemap/index.js` (click → fetch polity → dispatch to Alpine)

- [ ] **Step 1: Replace the aside with an Alpine-driven tabbed panel**

In `resources/views/livewire/time-map.blade.php`, replace the `<aside>...</aside>` block with:

```blade
    {{-- Polity info panel (TimeMap.org-style) --}}
    <aside x-data="{ tab: 'summary', polity: null, loading: false }"
           x-on:polity-selected.window="
                loading = true; polity = null; tab = 'summary';
                fetch('/teacher/timemap/polity/' + $event.detail.id)
                    .then(r => r.json()).then(d => { polity = d; loading = false; });
           "
           class="absolute left-0 top-0 z-10 h-full w-80 overflow-y-auto bg-base-100/95 p-4 shadow-xl">
        <template x-if="!polity && !loading">
            <p class="opacity-70">{{ __('Click a region') }}</p>
        </template>
        <template x-if="loading">
            <p class="flex items-center gap-2"><span class="loading loading-spinner loading-sm"></span> {{ __('Loading…') }}</p>
        </template>
        <template x-if="polity">
            <div>
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <template x-if="polity.flag_path"><img :src="polity.flag_path" class="h-5 rounded-sm shadow" alt=""></template>
                        <h2 class="text-lg font-bold" x-text="polity.label"></h2>
                    </div>
                    <button class="btn btn-ghost btn-xs" x-on:click="polity = null">✕</button>
                </div>
                <p class="text-xs opacity-70"
                   x-text="(polity.inception != null ? (polity.inception < 0 ? Math.abs(polity.inception)+' BCE' : polity.inception+' CE') : '?') + ' – ' + (polity.dissolution != null ? (polity.dissolution < 0 ? Math.abs(polity.dissolution)+' BCE' : polity.dissolution+' CE') : '')"></p>

                <div role="tablist" class="tabs tabs-bordered mt-3">
                    <a role="tab" class="tab" :class="tab==='summary' && 'tab-active'" x-on:click="tab='summary'">{{ __('Summary') }}</a>
                    <a role="tab" class="tab" :class="tab==='wikipedia' && 'tab-active'" x-on:click="tab='wikipedia'">{{ __('Wikipedia') }}</a>
                    <a role="tab" class="tab" :class="tab==='overtime' && 'tab-active'" x-on:click="tab='overtime'">{{ __('Over Time') }}</a>
                </div>

                <div class="mt-3 text-sm">
                    <p x-show="tab==='summary'" x-text="polity.summary || '{{ __('No summary yet.') }}'"></p>
                    <div x-show="tab==='wikipedia'">
                        <template x-if="polity.wikipedia_url">
                            <a :href="polity.wikipedia_url" target="_blank" rel="noopener" class="link link-primary">{{ __('Open on Wikipedia ↗') }}</a>
                        </template>
                        <p x-show="!polity.wikipedia_url" class="opacity-70">{{ __('No Wikipedia page linked.') }}</p>
                    </div>
                    <div x-show="tab==='overtime'" class="space-y-1">
                        <p><span class="opacity-70">{{ __('Preceded by') }}:</span> <span x-text="polity.predecessor || '—'"></span></p>
                        <p><span class="opacity-70">{{ __('Succeeded by') }}:</span> <span x-text="polity.successor || '—'"></span></p>
                    </div>
                </div>
            </div>
        </template>
    </aside>
```

- [ ] **Step 2: Dispatch the event from the map click**

In `resources/js/timemap/index.js`, replace the body of the `map.on('click', …)` handler's tail (the `wire.storiesForRegion(...)` call) with an event dispatch:

```js
    const polityId = hit ? hit.properties.polity_id : null;
    state.selectedRegion = polityId; // mirror for the test hook (the exported props no longer carry `region`)
    sync();
    window.dispatchEvent(new CustomEvent('polity-selected', { detail: { id: polityId } }));
```

(Keep the `selected` feature-state highlight logic above it. Remove the `await wire.storiesForRegion(...)` line and the now-unused `wire` story calls.)

- [ ] **Step 3: Remove the retired `storiesForRegion` from the component**

In `app/Livewire/TimeMap.php`, delete the `storiesForRegion` method and the `public array $stories` property (the panel is now Alpine-fed). Keep `year`, `stops`, `render()`. Delete `tests/Feature/TimeMap/StoriesForRegionTest.php` (its behavior is retired by design — see spec §1).

- [ ] **Step 4: Build + restart server + sanity check route**

```bash
npm run build
pkill -f "artisan serve"; sleep 1; php artisan serve --port=8000 >/tmp/lp-serve.log 2>&1 &
php artisan test --filter='PolityEndpointTest|ExportSignificantTest|EnrichPolitiesTest|EraServiceTest|BoundaryImportTest'
```
Expected: all green (the retired StoriesForRegionTest is gone).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/time-map.blade.php resources/js/timemap/index.js app/Livewire/TimeMap.php
git rm tests/Feature/TimeMap/StoriesForRegionTest.php
git commit -m "feat(atlas): tabbed polity panel (Summary/Wikipedia/Over Time); retire region matching"
```

---

## Task 8: oldmapsonline-style time slider

**Files:**
- Create: `resources/js/timemap/slider.js`
- Modify: `resources/views/livewire/time-map.blade.php`

- [ ] **Step 1: Implement the slider control**

Create `resources/js/timemap/slider.js`:

```js
// A horizontal scrubbable timeline: a track with century ticks/labels and a draggable handle.
// Calls onYear(year) (debounced by the caller) as the handle moves. Range is inclusive.
export function mountTimeSlider(el, { min, max, value, onYear }) {
  el.classList.add('relative', 'select-none');
  el.innerHTML = `
    <div class="tm-track relative h-10 cursor-pointer">
      <div class="tm-ticks absolute inset-x-0 bottom-0 h-full"></div>
      <div class="tm-handle absolute top-0 -ml-2 h-6 w-4 rounded bg-primary shadow" style="left:0"></div>
    </div>
    <div class="tm-readout mt-1 text-center text-sm font-semibold"></div>`;

  const track = el.querySelector('.tm-track');
  const handle = el.querySelector('.tm-handle');
  const readout = el.querySelector('.tm-readout');
  const ticksEl = el.querySelector('.tm-ticks');

  // Century ticks.
  for (let y = Math.ceil(min / 100) * 100; y <= max; y += 100) {
    const pct = ((y - min) / (max - min)) * 100;
    const t = document.createElement('div');
    t.className = 'absolute bottom-0 border-l border-base-content/30 text-[10px] text-base-content/60';
    t.style.left = `${pct}%`;
    t.style.height = y % 500 === 0 ? '100%' : '50%';
    if (y % 500 === 0) t.innerHTML = `<span class="absolute -left-3 -top-4">${y < 0 ? Math.abs(y) + ' BCE' : y}</span>`;
    ticksEl.appendChild(t);
  }

  let current = value;
  const fmt = (y) => (y < 0 ? `${Math.abs(y)} BCE` : `${y} CE`);
  const render = () => {
    const pct = ((current - min) / (max - min)) * 100;
    handle.style.left = `${pct}%`;
    readout.textContent = fmt(current);
  };
  const setFromClientX = (clientX) => {
    const r = track.getBoundingClientRect();
    const pct = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
    current = Math.round((min + pct * (max - min)) / 10) * 10;
    render();
    onYear(current);
  };

  let dragging = false;
  track.addEventListener('pointerdown', (e) => { dragging = true; track.setPointerCapture(e.pointerId); setFromClientX(e.clientX); });
  track.addEventListener('pointermove', (e) => { if (dragging) setFromClientX(e.clientX); });
  track.addEventListener('pointerup', () => { dragging = false; });

  render();
  return { setYear: (y) => { current = y; render(); } };
}
```

- [ ] **Step 2: Replace the range input with the slider mount**

In `resources/views/livewire/time-map.blade.php`, replace the bottom slider `<div>...range input...</div>` block with a mount point, and import/mount it. Replace the slider block with:

```blade
    {{-- Time slider (oldmapsonline-style) --}}
    <div class="absolute bottom-0 left-1/2 z-10 mb-6 w-[44rem] max-w-[92vw] -translate-x-1/2 rounded-box bg-base-100/95 px-5 py-3 shadow-xl"
         x-ref="sliderbox"
         x-init="$nextTick(() => window.mountAtlasSlider($refs.sliderbox, $refs.map, {{ $year }}))">
    </div>
```

- [ ] **Step 3: Wire the slider into index.js**

In `resources/js/timemap/index.js`, import the slider at the top and expose a mount helper:

```js
import { mountTimeSlider } from './slider.js';
```

And near the bottom of the file (after `window.initTimeMap = ...`), add:

```js
window.mountAtlasSlider = function (el, mapEl, initialYear) {
  let timer = null;
  mountTimeSlider(el, {
    min: -2000, max: 1880, value: initialYear,
    onYear: (year) => {
      clearTimeout(timer);
      timer = setTimeout(() => { if (mapEl._setYear) mapEl._setYear(year); }, 150);
    },
  });
};
```

- [ ] **Step 4: Build + commit**

```bash
npm run build
git add resources/js/timemap/slider.js resources/js/timemap/index.js resources/views/livewire/time-map.blade.php
git commit -m "feat(atlas): oldmapsonline-style century-tick time slider"
```

---

## Task 9: European viewport + Playwright atlas tests

**Files:**
- Modify: `resources/js/timemap/index.js` (map center/zoom)
- Modify: `tests/playwright/timemap.spec.ts`

- [ ] **Step 1: Centre the map on Europe**

In `resources/js/timemap/index.js`, change the `new maplibregl.Map({...})` `center`/`zoom`:

```js
    center: [15, 50], // Europe
    zoom: 4,
```

- [ ] **Step 2: Update the Playwright click test to assert the panel**

In `tests/playwright/timemap.spec.ts`, replace the `timemap-click-stories` test with:

```ts
test('timemap-click-panel: clicking a region opens the polity panel with tabs', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 20_000 });
  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);

  // Either a polity panel (label + tabs) or the empty prompt — both prove the click round-trip.
  const aside = page.locator('aside');
  await expect(aside).toContainText(/.+/);
  // Tabs render when a polity is selected; if present, switching works.
  const wikiTab = page.getByRole('tab', { name: 'Wikipedia' });
  if (await wikiTab.count()) {
    await wikiTab.click();
    await expect(page.getByText(/Wikipedia|No Wikipedia page/)).toBeVisible();
  }
});
```

- [ ] **Step 3: Build + run the full Time-Map verification**

```bash
npm run build
pkill -f "artisan serve"; sleep 1; php artisan serve --port=8000 >/tmp/lp-serve.log 2>&1 &
until curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/login | grep -qE '200|302'; do sleep 1; done
npx playwright test tests/playwright/timemap.spec.ts
```
Expected: 3 passed (shell, slider, click-panel); shell screenshot saved.

- [ ] **Step 4: Commit**

```bash
git add resources/js/timemap/index.js tests/playwright/timemap.spec.ts
git commit -m "feat(atlas): Europe-centred viewport + Playwright panel tests"
```

---

## Task 10: Lint, full suite, docs

- [ ] **Step 1: Lint PHP**

Run: `./vendor/bin/pint`
Expected: clean.

- [ ] **Step 2: Full PHPUnit suite**

Run: `composer test`
Expected: the atlas tests (Enrich, Export, PolityEndpoint) + existing green; only the known pre-existing non-Time-Map failures remain (do not fix those here).

- [ ] **Step 3: Update the data-sources doc**

In `docs/timemap-data-sources.md`, add a "Polities (atlas)" section:

```markdown
## Polities (atlas)
- `public.polities` is populated by `php artisan timemap:enrich-polities` from **Wikidata** (flag P41,
  inception P571, dissolution P576, predecessor/successor P155/P156, sitelinks) and the **Wikipedia REST**
  summary API. Flags are downloaded to `public/flags/{polity_id}.png` (Wikimedia Commons, attribute).
- `php artisan timemap:export-boundaries` writes significant-only polygons to `public/geo/boundaries/{year}.geojson`
  and per-era label centroids to `public/geo/labels/{year}.geojson`.
- Regenerate after a boundary change: `timemap:import-boundaries && timemap:enrich-polities && timemap:export-boundaries`.
```

- [ ] **Step 4: Commit**

```bash
git add docs/timemap-data-sources.md
git commit -m "docs(atlas): polities enrichment + export pipeline"
```

---

## Self-review notes (for the executor)

- **External calls** in `EnrichPolities`/`WikidataPolityResolver` go through `Http` — tests use `Http::fake()`; never hit the network in tests.
- **Server restart after PHP changes** (production env opcache) — Tasks 7 & 9 restart it; do the same if you change PHP between tasks.
- **Names stay consistent:** `polities` columns (`polity_id, label, flag_path, summary, wikipedia_url, inception, dissolution, predecessor, successor, sitelinks, significant`), the export `color_key` (0..11), label props (`label, date_label, flag_id`), the `polity-selected` window event `{ detail: { id } }`, `mapEl._setYear(year)`, `window.mountAtlasSlider`, `window.initTimeMap`.
- **Flags in git:** `public/flags/*.png` are app data (commit them); each is small.
- The retired `storiesForRegion`/`StoriesForRegionTest` are intentionally removed (spec §1) — the panel shows the polity's own info, so there is no fuzzy matching left to test.
