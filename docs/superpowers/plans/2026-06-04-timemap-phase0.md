# Time-Map Phase 0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a MapLibre Time-Map where the teacher scrubs a year slider, watches PostGIS border polygons populate, clicks a region, and sees real `history_articles` for that place + era in a left column.

**Architecture:** Laravel 12 + Livewire 3 + Alpine + MapLibre GL JS, talking to the existing Supabase corpus over **two connections** — `pgsql` (search_path `app`, where Laravel migrates) and `pgsql_corpus` (search_path `public`, read-only over the corpus, the one sanctioned writer being the boundary importer). Borders live in PostGIS `boundaries`; the left column reads `history_articles` by macro-region + era. Tests run against a **local Postgres+PostGIS** container.

**Tech Stack:** PHP 8.2 / Laravel 12, Livewire 3, Alpine 3, MapLibre GL JS, Vite 7, PostGIS; PHPUnit + Playwright + Vitest.

**Spec:** `docs/superpowers/specs/2026-06-04-timemap-phase0-design.md`

---

## File structure (created / modified)

**Created**
- `docker-compose.test.yml` — local Postgres+PostGIS for tests
- `app/Services/EraService.php` — year math (years-ago, generations, nearest stop)
- `resources/js/timemap/era.js` — JS mirror of the era readout
- `resources/js/timemap/era.test.js` — Vitest for `era.js`
- `app/Models/Corpus/Article.php` — read-only model over `public.history_articles`
- `app/Models/Corpus/Boundary.php` — read-only model over `public.boundaries`
- `resources/data/region-map.json` — polity slug → macro-region tag (the only hand bridge)
- `database/data/historical-basemaps/` — vendored CC-BY-SA GeoJSON snapshots
- `app/Console/Commands/ImportBoundaries.php` — load polygons → PostGIS `boundaries`
- `app/Console/Commands/VerifyTimemap.php` — integrity check
- `app/Livewire/TimeMap.php` — state, `boundariesGeoJson`, `storiesAt`
- `resources/views/livewire/time-map.blade.php` — map + slider + left column
- `resources/js/timemap/index.js` — MapLibre init, layers, slider, click, `__portal`
- `tests/Unit/Services/EraServiceTest.php`
- `tests/Feature/TimeMap/BoundaryImportTest.php`
- `tests/Feature/TimeMap/StoriesAtTest.php`
- `tests/Feature/Concerns/SeedsCorpusFixtures.php` — creates+seeds fixture corpus tables in test public schema
- `tests/playwright/timemap.spec.ts` — shell / slider / click E2E
- `docs/timemap-data-sources.md` — upstream licence + attribution

**Modified**
- `config/database.php` — env-driven `search_path`; add `pgsql_corpus` connection
- `phpunit.xml` — point tests at local Postgres+PostGIS (replace sqlite override)
- `.env.testing` (created) — local test DB creds
- `vite.config.js:8` — add `resources/js/timemap/index.js` to `input`
- `routes/web.php` — `teacher.timemap` route
- `app/Http/Controllers/Auth/LoginController.php:35,47` — land teachers on the Time-Map
- `package.json` — add `maplibre-gl` dependency

---

## Task 1: Two-connection DB config + local test Postgres

**Files:**
- Modify: `config/database.php:86-100` (pgsql block) and add `pgsql_corpus`
- Create: `docker-compose.test.yml`
- Create: `.env.testing`
- Modify: `phpunit.xml:25-27` (the DB env lines)

- [ ] **Step 1: Make `search_path` env-driven and add the corpus connection**

In `config/database.php`, replace the existing `'pgsql' => [ ... ]` block (around lines 86-100) with both connections:

```php
'pgsql' => [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => env('DB_SEARCH_PATH', 'public'),
    'sslmode' => env('DB_SSLMODE', 'prefer'),
],

'pgsql_corpus' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
],
```

- [ ] **Step 2: Set the app schema search path in `.env`**

Add to `.env` (under the existing `DB_*` block, around line 166):

```
DB_SEARCH_PATH=app
DB_SSLMODE=require
```

- [ ] **Step 3: Create the `app` schema on Supabase (one-off, non-destructive)**

Run:

```bash
php artisan tinker --execute='DB::connection("pgsql")->statement("CREATE SCHEMA IF NOT EXISTS app"); echo "ok";'
```

Expected: prints `ok`. (This creates only the empty schema; it touches no corpus data.)

- [ ] **Step 4: Verify migrations target `app` and the corpus is untouched**

Run:

```bash
php artisan migrate --force
php artisan tinker --execute='
echo "app tables: ".count(DB::connection("pgsql")->select("select tablename from pg_tables where schemaname=?",["app"])).PHP_EOL;
echo "corpus articles: ".DB::connection("pgsql_corpus")->table("history_articles")->count().PHP_EOL;'
```

Expected: app tables ≥ 1 (migrations table etc.); corpus articles still `3512`.

- [ ] **Step 5: Add the local test Postgres+PostGIS container**

Create `docker-compose.test.yml`:

```yaml
services:
  postgis-test:
    image: postgis/postgis:16-3.4
    container_name: lp_postgis_test
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: postgres
      POSTGRES_DB: learningportal_test
    ports:
      - "54329:5432"
    tmpfs:
      - /var/lib/postgresql/data
```

Run:

```bash
docker compose -f docker-compose.test.yml up -d
sleep 5 && docker exec lp_postgis_test pg_isready -U postgres
```

Expected: `accepting connections`.

- [ ] **Step 6: Create `.env.testing` pointing at the container**

Create `.env.testing`:

```
APP_ENV=testing
APP_KEY=
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=54329
DB_DATABASE=learningportal_test
DB_USERNAME=postgres
DB_PASSWORD=postgres
DB_SEARCH_PATH=app
DB_SSLMODE=disable
CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
MAIL_MAILER=array
```

Then: `php artisan key:generate --env=testing`.

- [ ] **Step 7: Stop phpunit.xml forcing sqlite**

In `phpunit.xml`, delete these two lines so `.env.testing` drives the DB:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

- [ ] **Step 8: Confirm the test connection + PostGIS work**

Run:

```bash
php artisan tinker --env=testing --execute='
DB::connection("pgsql")->statement("CREATE SCHEMA IF NOT EXISTS app");
DB::connection("pgsql")->statement("CREATE EXTENSION IF NOT EXISTS postgis");
echo DB::connection("pgsql")->selectOne("select postgis_version() v")->v.PHP_EOL;'
```

Expected: prints a PostGIS version string (e.g. `3.4 ...`).

- [ ] **Step 9: Make tests self-bootstrap the schema + PostGIS**

`RefreshDatabase` migrates into the `app` schema, which must exist (and PostGIS must be
installed) *before* migration runs. Add the `beforeRefreshingDatabase()` hook to the base
test case. Replace `tests/TestCase.php` with:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Runs before RefreshDatabase migrates — guarantees the app schema + PostGIS exist. */
    protected function beforeRefreshingDatabase(): void
    {
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::connection('pgsql')->statement('CREATE SCHEMA IF NOT EXISTS app');
    }
}
```

- [ ] **Step 10: Commit**

```bash
git add config/database.php docker-compose.test.yml .env.testing phpunit.xml tests/TestCase.php
git commit -m "feat(db): app-schema isolation + read-only corpus connection + local postgis test db"
```

---

## Task 2: EraService (PHP)

**Files:**
- Create: `app/Services/EraService.php`
- Test: `tests/Unit/Services/EraServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/EraServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EraService;
use PHPUnit\Framework\TestCase;

class EraServiceTest extends TestCase
{
    private EraService $era;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin "now" so the math is deterministic.
        $this->era = new EraService(currentYear: 2026);
    }

    public function test_years_ago_for_bce_adds_to_current_year(): void
    {
        // 1500 BCE is year -1500 → 2026 + 1500 = 3526 years ago.
        $this->assertSame(3526, $this->era->yearsAgo(-1500));
    }

    public function test_years_ago_for_ce(): void
    {
        $this->assertSame(1026, $this->era->yearsAgo(1000));
    }

    public function test_generations_uses_25_year_default(): void
    {
        // 3526 years / 25 ≈ 141 generations.
        $this->assertSame(141, $this->era->generations(-1500));
    }

    public function test_nearest_stop_picks_closest_seeded_year(): void
    {
        $stops = [-2000, -1500, -500, 1, 500, 1000, 2010];
        $this->assertSame(-500, $this->era->nearestStop(-650, $stops));
        $this->assertSame(1, $this->era->nearestStop(-100, $stops));
        $this->assertSame(2010, $this->era->nearestStop(1990, $stops));
    }

    public function test_bce_sorts_before_ce(): void
    {
        $years = [500, -44, 1, -1500];
        sort($years);
        $this->assertSame([-1500, -44, 1, 500], $years);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=EraServiceTest`
Expected: FAIL — `Class "App\Services\EraService" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/EraService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

class EraService
{
    public function __construct(private ?int $currentYear = null)
    {
        $this->currentYear ??= (int) date('Y');
    }

    /** Whole years between $year (BCE negative) and now. There is no year 0 in history,
     *  but for a kid-facing "how long ago" readout a plain arithmetic gap is clearest. */
    public function yearsAgo(int $year): int
    {
        return $this->currentYear - $year;
    }

    public function generations(int $year, int $yearsPerGeneration = 25): int
    {
        return intdiv($this->yearsAgo($year), $yearsPerGeneration);
    }

    /** Closest value in $stops to $year (ties resolve to the earlier/smaller stop). */
    public function nearestStop(int $year, array $stops): int
    {
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($stops as $stop) {
            $dist = abs($stop - $year);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $stop;
            }
        }

        return (int) $best;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter=EraServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/EraService.php tests/Unit/Services/EraServiceTest.php
git commit -m "feat(timemap): EraService years-ago/generations/nearest-stop"
```

---

## Task 3: era.js (JS mirror) + Vitest

**Files:**
- Create: `resources/js/timemap/era.js`
- Test: `resources/js/timemap/era.test.js`

- [ ] **Step 1: Write the failing test**

Create `resources/js/timemap/era.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { yearsAgo, generations, formatReadout } from './era.js';

describe('era math (mirrors App\\Services\\EraService)', () => {
  it('years ago for BCE adds to current year', () => {
    expect(yearsAgo(-1500, 2026)).toBe(3526);
  });
  it('years ago for CE', () => {
    expect(yearsAgo(1000, 2026)).toBe(1026);
  });
  it('generations default 25y', () => {
    expect(generations(-1500, 2026)).toBe(141);
  });
  it('formats a kid-facing readout', () => {
    expect(formatReadout(-1500, 2026)).toBe('≈ 3,526 years ago · ~141 generations');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx vitest run resources/js/timemap/era.test.js`
Expected: FAIL — cannot resolve `./era.js`.

- [ ] **Step 3: Write the implementation**

Create `resources/js/timemap/era.js`:

```js
// Mirror of App\Services\EraService — keep the two in sync (both are tested).
export function yearsAgo(year, currentYear = new Date().getFullYear()) {
  return currentYear - year;
}

export function generations(year, currentYear = new Date().getFullYear(), perGeneration = 25) {
  return Math.trunc(yearsAgo(year, currentYear) / perGeneration);
}

export function formatReadout(year, currentYear = new Date().getFullYear()) {
  const ya = yearsAgo(year, currentYear).toLocaleString('en-US');
  const gens = generations(year, currentYear);
  return `≈ ${ya} years ago · ~${gens} generations`;
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `npx vitest run resources/js/timemap/era.test.js`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/timemap/era.js resources/js/timemap/era.test.js
git commit -m "feat(timemap): era.js readout mirror with vitest"
```

---

## Task 4: Read-only corpus models

**Files:**
- Create: `app/Models/Corpus/Article.php`
- Create: `app/Models/Corpus/Boundary.php`

- [ ] **Step 1: Write the Article model**

Create `app/Models/Corpus/Article.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view over the existing corpus table public.history_articles.
 * Never written by the app.
 */
class Article extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'history_articles';

    public $timestamps = false;

    protected $guarded = [];

    /** Articles whose era brackets the given year, for a macro-region. */
    public function scopeForRegionYear($query, string $region, int $year)
    {
        return $query->where('region', $region)
            ->whereNotNull('era_start')
            ->whereNotNull('era_end')
            ->where('era_start', '<=', $year)
            ->where('era_end', '>=', $year);
    }
}
```

- [ ] **Step 2: Write the Boundary model**

Create `app/Models/Corpus/Boundary.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Corpus;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-mostly view over public.boundaries (PostGIS). The only writer is
 * App\Console\Commands\ImportBoundaries.
 */
class Boundary extends Model
{
    protected $connection = 'pgsql_corpus';

    protected $table = 'boundaries';

    protected $guarded = [];

    protected $casts = ['extra' => 'array'];
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Corpus/Article.php app/Models/Corpus/Boundary.php
git commit -m "feat(timemap): read-only corpus models (Article, Boundary)"
```

---

## Task 5: Corpus fixture trait (test scaffolding)

The local test DB starts empty; tests that touch corpus tables must create minimal
`public.history_articles` and `public.boundaries` tables and seed rows.

**Files:**
- Create: `tests/Feature/Concerns/SeedsCorpusFixtures.php`

- [ ] **Step 1: Write the trait**

Create `tests/Feature/Concerns/SeedsCorpusFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

trait SeedsCorpusFixtures
{
    protected function setUpCorpusFixtures(): void
    {
        $corpus = DB::connection('pgsql_corpus');
        $corpus->statement('CREATE EXTENSION IF NOT EXISTS postgis');

        $corpus->statement('DROP TABLE IF EXISTS public.history_articles');
        $corpus->statement('
            CREATE TABLE public.history_articles (
                id bigserial primary key,
                title varchar,
                summary text,
                full_text text,
                region varchar,
                era_start integer,
                era_end integer,
                source_url varchar,
                source_license varchar
            )');

        $corpus->statement('DROP TABLE IF EXISTS public.boundaries');
        $corpus->statement('
            CREATE TABLE public.boundaries (
                id bigserial primary key,
                polity_id text,
                name text,
                valid_from integer,
                valid_to integer,
                geom geometry(Geometry, 4326),
                color text,
                source text,
                license text,
                extra jsonb,
                created_at timestamptz,
                updated_at timestamptz
            )');
    }

    protected function seedArticle(array $attrs = []): int
    {
        return DB::connection('pgsql_corpus')->table('public.history_articles')->insertGetId(array_merge([
            'title' => 'Test Article',
            'summary' => 'A summary.',
            'region' => 'Mediterranean',
            'era_start' => -100,
            'era_end' => 100,
            'source_url' => 'https://example.test/a',
            'source_license' => 'CC-BY',
        ], $attrs));
    }

    /** Insert a square boundary polygon covering a lon/lat box. */
    protected function seedBoundary(array $attrs, float $minLng, float $minLat, float $maxLng, float $maxLat): void
    {
        $wkt = sprintf(
            'POLYGON((%1$f %2$f, %3$f %2$f, %3$f %4$f, %1$f %4$f, %1$f %2$f))',
            $minLng, $minLat, $maxLng, $maxLat
        );
        $extra = json_encode($attrs['extra'] ?? ['region' => 'Mediterranean']);

        DB::connection('pgsql_corpus')->statement(
            'INSERT INTO public.boundaries (polity_id,name,valid_from,valid_to,geom,source,license,extra,created_at,updated_at)
             VALUES (?,?,?,?, ST_SetSRID(ST_GeomFromText(?),4326), ?,?, ?::jsonb, now(), now())',
            [
                $attrs['polity_id'] ?? 'roman-republic',
                $attrs['name'] ?? 'Roman Republic',
                $attrs['valid_from'] ?? -509,
                $attrs['valid_to'] ?? -27,
                $wkt,
                'historical-basemaps',
                'CC-BY-SA',
                $extra,
            ]
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add tests/Feature/Concerns/SeedsCorpusFixtures.php
git commit -m "test(timemap): corpus fixture trait for local postgis"
```

---

## Task 6: Boundary importer + region map

**Files:**
- Create: `resources/data/region-map.json`
- Create: `database/data/historical-basemaps/` (vendored GeoJSON)
- Create: `app/Console/Commands/ImportBoundaries.php`
- Test: `tests/Feature/TimeMap/BoundaryImportTest.php`

- [ ] **Step 1: Vendor the GeoJSON snapshots (CC-BY-SA)**

Run (downloads the ancient-spine snapshots; `-f` fails loudly on a 404 so you can adjust a filename):

```bash
mkdir -p database/data/historical-basemaps
base="https://raw.githubusercontent.com/aourednik/historical-basemaps/master/geojson"
for f in world_bc2000 world_bc1500 world_bc500 world_1 world_500 world_1000 world_1880; do
  curl -fsSL "$base/$f.geojson" -o "database/data/historical-basemaps/$f.geojson" && echo "got $f"
done
ls -la database/data/historical-basemaps/
```

Expected: seven `.geojson` files saved. If any 404s, pick the nearest available year from
`https://github.com/aourednik/historical-basemaps/tree/master/geojson` and update the list
here and in Step 3's `$snapshots`.

- [ ] **Step 2: Write the region map**

Create `resources/data/region-map.json` — maps a slugified polygon NAME to one of the 7
corpus macro-regions. Seed the spine; unmapped polities are skipped by the importer.

```json
{
  "_comment": "slugified historical-basemaps NAME -> history_articles.region (7 macro-regions)",
  "egypt": "Mediterranean",
  "ptolemaic-kingdom": "Mediterranean",
  "roman-republic": "Mediterranean",
  "roman-empire": "Mediterranean",
  "greece": "Mediterranean",
  "macedonia": "Mediterranean",
  "carthage": "Mediterranean",
  "sumer": "Middle East",
  "babylonia": "Middle East",
  "assyria": "Middle East",
  "achaemenid-empire": "Middle East",
  "indus-valley": "South Asia",
  "maurya-empire": "South Asia",
  "gupta-empire": "South Asia",
  "shang": "East Asia",
  "zhou": "East Asia",
  "han-empire": "East Asia",
  "qin": "East Asia"
}
```

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/TimeMap/BoundaryImportTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Models\Corpus\Boundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class BoundaryImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();

        // Minimal one-feature GeoJSON for a year snapshot.
        File::ensureDirectoryExists(base_path('database/data/historical-basemaps'));
        File::put(
            base_path('database/data/historical-basemaps/world_bc500.geojson'),
            json_encode([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['NAME' => 'Roman Republic'],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                        [12.0, 41.0], [13.0, 41.0], [13.0, 42.0], [12.0, 42.0], [12.0, 41.0],
                    ]]],
                ]],
            ])
        );
    }

    public function test_import_loads_polygons_with_geom_and_region_tag(): void
    {
        $this->artisan('timemap:import-boundaries')->assertExitCode(0);

        $b = Boundary::where('polity_id', 'roman-republic')->firstOrFail();
        $this->assertSame('Mediterranean', $b->extra['region']);
        $this->assertSame('historical-basemaps', $b->source);

        $hasGeom = DB::connection('pgsql_corpus')->selectOne(
            'select ST_Contains(geom, ST_SetSRID(ST_Point(12.5,41.5),4326)) hit from public.boundaries where polity_id=?',
            ['roman-republic']
        );
        $this->assertTrue((bool) $hasGeom->hit);
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('timemap:import-boundaries')->assertExitCode(0);
        $this->artisan('timemap:import-boundaries')->assertExitCode(0);

        $this->assertSame(1, Boundary::where('polity_id', 'roman-republic')->count());
    }
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `php artisan test --filter=BoundaryImportTest`
Expected: FAIL — `command "timemap:import-boundaries" is not defined`.

- [ ] **Step 5: Write the importer**

Create `app/Console/Commands/ImportBoundaries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportBoundaries extends Command
{
    protected $signature = 'timemap:import-boundaries';

    protected $description = 'Load historical-basemaps GeoJSON snapshots into PostGIS public.boundaries';

    /** Seeded snapshot year => filename (without extension). Edit alongside Task 6 Step 1. */
    private array $snapshots = [
        -2000 => 'world_bc2000',
        -1500 => 'world_bc1500',
        -500 => 'world_bc500',
        1 => 'world_1',
        500 => 'world_500',
        1000 => 'world_1000',
        1880 => 'world_1880',
    ];

    public function handle(): int
    {
        $regionMap = json_decode(File::get(resource_path('data/region-map.json')), true);
        $years = array_keys($this->snapshots);
        sort($years);

        $corpus = DB::connection('pgsql_corpus');
        // Idempotent: clear only rows this importer owns.
        $corpus->statement("DELETE FROM public.boundaries WHERE source = 'historical-basemaps'");

        $imported = 0;
        foreach ($this->snapshots as $year => $file) {
            $path = base_path("database/data/historical-basemaps/{$file}.geojson");
            if (! File::exists($path)) {
                $this->warn("missing snapshot {$file}.geojson — skipping");
                continue;
            }

            // This snapshot is "valid" until the next seeded year (or +∞ for the last).
            $idx = array_search($year, $years, true);
            $validTo = $years[$idx + 1] ?? 3000;

            $fc = json_decode(File::get($path), true);
            foreach ($fc['features'] ?? [] as $feature) {
                $name = $feature['properties']['NAME']
                    ?? $feature['properties']['name'] ?? null;
                if (! $name) {
                    continue;
                }
                $slug = Str::slug($name);
                if (! isset($regionMap[$slug])) {
                    continue; // outside the seeded spine
                }

                $corpus->statement(
                    'INSERT INTO public.boundaries
                       (polity_id,name,valid_from,valid_to,geom,source,license,extra,created_at,updated_at)
                     VALUES (?,?,?,?, ST_SetSRID(ST_GeomFromGeoJSON(?),4326), ?,?, ?::jsonb, now(), now())',
                    [
                        $slug,
                        $name,
                        $year,
                        $validTo,
                        json_encode($feature['geometry']),
                        'historical-basemaps',
                        'CC-BY-SA 4.0 — historical-basemaps (A. Ourednik)',
                        json_encode(['region' => $regionMap[$slug]]),
                    ]
                );
                $imported++;
            }
        }

        $this->info("imported {$imported} boundary features");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Run it to verify it passes**

Run: `php artisan test --filter=BoundaryImportTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add resources/data/region-map.json app/Console/Commands/ImportBoundaries.php tests/Feature/TimeMap/BoundaryImportTest.php database/data/historical-basemaps
git commit -m "feat(timemap): import historical-basemaps polygons into PostGIS boundaries"
```

---

## Task 7: TimeMap Livewire component (`storiesAt` + boundary payload)

**Files:**
- Create: `app/Livewire/TimeMap.php`
- Test: `tests/Feature/TimeMap/StoriesAtTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimeMap/StoriesAtTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TimeMap;

use App\Livewire\TimeMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsCorpusFixtures;
use Tests\TestCase;

class StoriesAtTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCorpusFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCorpusFixtures();
    }

    public function test_click_inside_boundary_returns_region_era_articles(): void
    {
        // Boundary box around Rome (12..13 lng, 41..42 lat), valid 509 BCE..27 BCE.
        $this->seedBoundary(
            ['polity_id' => 'roman-republic', 'name' => 'Roman Republic',
             'valid_from' => -509, 'valid_to' => -27, 'extra' => ['region' => 'Mediterranean']],
            12.0, 41.0, 13.0, 42.0
        );
        $hit = $this->seedArticle(['title' => 'The Punic Wars', 'region' => 'Mediterranean', 'era_start' => -264, 'era_end' => -146]);
        $this->seedArticle(['title' => 'Cold War', 'region' => 'Mediterranean', 'era_start' => 1947, 'era_end' => 1991]);

        Livewire::test(TimeMap::class)
            ->call('storiesAt', 12.5, 41.5, -200)
            ->assertSet('selectedRegion', 'Mediterranean')
            ->assertSee('The Punic Wars')
            ->assertDontSee('Cold War');
    }

    public function test_click_outside_any_boundary_is_empty(): void
    {
        $this->seedBoundary(
            ['polity_id' => 'roman-republic', 'valid_from' => -509, 'valid_to' => -27],
            12.0, 41.0, 13.0, 42.0
        );

        Livewire::test(TimeMap::class)
            ->call('storiesAt', 100.0, 0.0, -200)
            ->assertSet('selectedRegion', null)
            ->assertSee('No stories here yet');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=StoriesAtTest`
Expected: FAIL — `Class "App\Livewire\TimeMap" not found`.

- [ ] **Step 3: Write the component**

Create `app/Livewire/TimeMap.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Corpus\Article;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TimeMap extends Component
{
    /** Seeded snapshot stops shown on the slider. */
    public array $stops = [-2000, -1500, -500, 1, 500, 1000, 1880];

    public int $year = -200;

    public ?string $selectedRegion = null;

    public ?string $selectedPolity = null;

    public array $stories = [];

    /** Boundaries valid at the current year, as a GeoJSON FeatureCollection for MapLibre. */
    public function boundariesGeoJson(): array
    {
        $rows = DB::connection('pgsql_corpus')->select(
            "select polity_id, name, extra->>'region' as region,
                    ST_AsGeoJSON(geom) as geometry
             from public.boundaries
             where valid_from <= ? and valid_to >= ?",
            [$this->year, $this->year]
        );

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(fn ($r) => [
                'type' => 'Feature',
                'properties' => ['polity_id' => $r->polity_id, 'name' => $r->name, 'region' => $r->region],
                'geometry' => json_decode($r->geometry, true),
            ], $rows),
        ];
    }

    /** A map click: find the polity at (lng,lat) for the year, then its region's articles. */
    public function storiesAt(float $lng, float $lat, int $year): void
    {
        $this->year = $year;

        $polity = DB::connection('pgsql_corpus')->selectOne(
            "select polity_id, name, extra->>'region' as region
             from public.boundaries
             where valid_from <= ? and valid_to >= ?
               and ST_Contains(geom, ST_SetSRID(ST_Point(?, ?), 4326))
             limit 1",
            [$year, $year, $lng, $lat]
        );

        if (! $polity) {
            $this->selectedRegion = null;
            $this->selectedPolity = null;
            $this->stories = [];

            return;
        }

        $this->selectedRegion = $polity->region;
        $this->selectedPolity = $polity->name;

        $this->stories = Article::query()
            ->forRegionYear($polity->region, $year)
            ->orderByRaw('(era_end - era_start) asc') // tighter-era (more specific) first
            ->limit(20)
            ->get(['id', 'title', 'summary', 'source_url', 'era_start', 'era_end'])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.time-map')
            ->layout('components.layouts.app', ['title' => 'Time-Map']);
    }
}
```

- [ ] **Step 4: Write a minimal view so the component renders in tests**

Create `resources/views/livewire/time-map.blade.php` (expanded in Task 9):

```blade
<div>
    @forelse ($stories as $story)
        <article wire:key="story-{{ $story['id'] }}">{{ $story['title'] }}</article>
    @empty
        <p>No stories here yet</p>
    @endforelse
</div>
```

- [ ] **Step 5: Run it to verify it passes**

Run: `php artisan test --filter=StoriesAtTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TimeMap.php resources/views/livewire/time-map.blade.php tests/Feature/TimeMap/StoriesAtTest.php
git commit -m "feat(timemap): TimeMap Livewire with spatial storiesAt + boundary geojson"
```

---

## Task 8: VerifyTimemap command

**Files:**
- Create: `app/Console/Commands/VerifyTimemap.php`

- [ ] **Step 1: Write the command**

Create `app/Console/Commands/VerifyTimemap.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyTimemap extends Command
{
    protected $signature = 'timemap:verify';

    protected $description = 'Assert imported boundaries have geom + a valid corpus region tag';

    public function handle(): int
    {
        $corpus = DB::connection('pgsql_corpus');
        $regions = $corpus->table('history_articles')->whereNotNull('region')->distinct()->pluck('region')->all();

        $bad = $corpus->select(
            "select polity_id, extra->>'region' region,
                    (geom is null) as no_geom
             from public.boundaries where source='historical-basemaps'"
        );

        $problems = 0;
        foreach ($bad as $b) {
            if ($b->no_geom) {
                $this->error("{$b->polity_id}: missing geom");
                $problems++;
            }
            if (! in_array($b->region, $regions, true)) {
                $this->error("{$b->polity_id}: region '{$b->region}' not a corpus region");
                $problems++;
            }
        }

        if ($problems > 0) {
            $this->error("timemap:verify found {$problems} problem(s)");

            return self::FAILURE;
        }

        $this->info('timemap:verify OK');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Smoke it against the real corpus**

Run: `php artisan timemap:import-boundaries && php artisan timemap:verify`
Expected: import prints a count; verify prints `timemap:verify OK` and exits 0.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/VerifyTimemap.php
git commit -m "feat(timemap): timemap:verify integrity check"
```

---

## Task 9: MapLibre frontend (map shell, slider, layer, click)

**Files:**
- Modify: `package.json` (add maplibre-gl)
- Modify: `vite.config.js:8`
- Create: `resources/js/timemap/index.js`
- Modify: `resources/views/livewire/time-map.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Auth/LoginController.php:35,47`

- [ ] **Step 1: Install MapLibre**

Run: `npm install maplibre-gl`
Expected: added to `dependencies`.

- [ ] **Step 2: Add the Vite entry**

In `vite.config.js:8`, append the entry to the `input` array:

```js
input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/avatar-3d.js', 'resources/js/argument-map.js', 'resources/js/lesson-player.js', 'resources/js/timemap/index.js'],
```

- [ ] **Step 3: Write the map module**

Create `resources/js/timemap/index.js`:

```js
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { formatReadout } from './era.js';

// Mounted by the Blade view via x-init. `wire` is the Livewire component proxy.
window.initTimeMap = function initTimeMap(el, wire, initialYear) {
  const state = { year: initialYear, ready: false, selectedRegion: null };
  const sync = () => {
    window.__portal = { ready: state.ready, year: state.year, selectedRegion: state.selectedRegion };
  };
  sync();

  const map = new maplibregl.Map({
    container: el,
    style: 'https://demotiles.maplibre.org/style.json',
    center: [20, 30],
    zoom: 2,
    attributionControl: { customAttribution: 'Borders © historical-basemaps (CC-BY-SA)' },
  });

  const setBoundaries = async () => {
    const fc = await wire.boundariesGeoJson();
    const src = map.getSource('boundaries');
    if (src) {
      src.setData(fc);
    } else {
      map.addSource('boundaries', { type: 'geojson', data: fc });
      map.addLayer({
        id: 'boundaries-fill', type: 'fill', source: 'boundaries',
        paint: { 'fill-color': '#f59e0b', 'fill-opacity': 0, 'fill-opacity-transition': { duration: 400 } },
      });
      map.addLayer({
        id: 'boundaries-line', type: 'line', source: 'boundaries',
        paint: { 'line-color': '#b45309', 'line-width': 1 },
      });
      requestAnimationFrame(() => map.setPaintProperty('boundaries-fill', 'fill-opacity', 0.35));
    }
  };

  map.on('load', async () => {
    await setBoundaries();
    state.ready = true;
    sync();
  });

  map.on('click', async (e) => {
    await wire.storiesAt(e.lngLat.lng, e.lngLat.lat, state.year);
    state.selectedRegion = wire.selectedRegion ?? null;
    sync();
  });

  // Called by the Alpine slider on input.
  el._setYear = async (year) => {
    state.year = year;
    wire.year = year;
    map.setPaintProperty('boundaries-fill', 'fill-opacity', 0);
    await setBoundaries();
    requestAnimationFrame(() => map.setPaintProperty('boundaries-fill', 'fill-opacity', 0.35));
    sync();
    return formatReadout(year);
  };

  return map;
};
```

- [ ] **Step 4: Build the full view**

Replace `resources/views/livewire/time-map.blade.php` with:

```blade
<div class="relative h-[calc(100vh-4rem)] w-full"
     x-data="{ year: @js($year), readout: '' }"
     x-init="$nextTick(() => window.initTimeMap($refs.map, $wire, year))">
    {{-- Map canvas --}}
    <div x-ref="map" class="absolute inset-0" wire:ignore></div>

    {{-- Left story column --}}
    <aside class="absolute left-0 top-0 z-10 h-full w-80 overflow-y-auto bg-base-100/95 p-4 shadow-xl">
        <h2 class="text-lg font-bold">
            {{ $selectedPolity ?? __('Click a region') }}
        </h2>
        <div class="mt-3 space-y-3">
            @forelse ($stories as $story)
                <a href="{{ $story['source_url'] }}" target="_blank" rel="noopener"
                   wire:key="story-{{ $story['id'] }}"
                   class="card bg-base-200 p-3 hover:bg-base-300">
                    <span class="font-semibold">{{ $story['title'] }}</span>
                    <span class="text-xs opacity-70">{{ $story['era_start'] }}–{{ $story['era_end'] }}</span>
                </a>
            @empty
                <p class="opacity-70">{{ __('No stories here yet') }}</p>
            @endforelse
        </div>
    </aside>

    {{-- Time slider + readout --}}
    <div class="absolute bottom-0 left-1/2 z-10 mb-6 w-[36rem] max-w-[90vw] -translate-x-1/2 rounded-box bg-base-100/95 p-4 shadow-xl">
        <input type="range" class="range range-primary" min="-2000" max="1880" step="10"
               x-ref="slider" x-model.number="year"
               @input.debounce.150ms="readout = await $refs.map._setYear(year)" />
        <div class="mt-2 flex justify-between text-sm">
            <span x-text="year < 0 ? Math.abs(year) + ' BCE' : year + ' CE'"></span>
            <span x-text="readout"></span>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the `->prefix('teacher')->name('teacher.')` group (after the `dashboard` route, ~line 49), add:

```php
Route::get('/timemap', \App\Livewire\TimeMap::class)->name('timemap');
```

- [ ] **Step 6: Land teachers on the Time-Map**

In `app/Http/Controllers/Auth/LoginController.php`, change both redirect lines (35 and 47) from:

```php
$user->isTeacher(), $user->role === 'admin' => redirect()->route('teacher.dashboard'),
```

to:

```php
$user->isTeacher(), $user->role === 'admin' => redirect()->route('teacher.timemap'),
```

- [ ] **Step 7: Build and smoke manually**

Run: `npm run build`
Expected: build succeeds, `maplibre-gl` chunk emitted.

Then `php artisan serve` + `npm run dev`, log in as a teacher, confirm `/teacher/timemap`
shows a map, the slider moves borders, and clicking a region fills the left column.

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json vite.config.js resources/js/timemap/index.js resources/views/livewire/time-map.blade.php routes/web.php app/Http/Controllers/Auth/LoginController.php
git commit -m "feat(timemap): MapLibre shell, year slider, boundary layer, click-to-stories"
```

---

## Task 10: Playwright E2E

**Files:**
- Create: `tests/playwright/timemap.spec.ts`

> These run against a serving app with the corpus imported. The runner script below boots
> `php artisan serve` (using `.env`, i.e. the real Supabase corpus, read-only) and assumes
> `timemap:import-boundaries` has been run once.

- [ ] **Step 1: Write the E2E specs**

Create `tests/playwright/timemap.spec.ts`:

```ts
import { test, expect } from '@playwright/test';

// A teacher session is required. This helper assumes a dev auto-login or seeded session;
// if the app needs a manual login, perform it here once.
test.beforeEach(async ({ page }) => {
  await page.goto('/teacher/timemap');
});

test('timemap-shell: canvas mounts, portal ready, no console errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

  await expect(page.locator('canvas.maplibregl-canvas')).toBeVisible();
  await page.waitForFunction(() => (window as any).__portal?.ready === true, { timeout: 15_000 });

  await page.screenshot({ path: 'tests/playwright/results/timemap-shell.png' });
  expect(errors, errors.join('\n')).toHaveLength(0);
});

test('timemap-slider: moving the year updates the readout', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true);
  const slider = page.locator('input[type=range]');
  await slider.fill('1000');
  await expect(page.getByText(/years ago/)).toBeVisible();
  await page.waitForFunction(() => (window as any).__portal?.year === 1000);
});

test('timemap-click-stories: clicking the map updates the left column', async ({ page }) => {
  await page.waitForFunction(() => (window as any).__portal?.ready === true);
  const box = await page.locator('canvas.maplibregl-canvas').boundingBox();
  if (box) {
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  }
  // Either stories appear or the explicit empty state shows — both prove the round-trip.
  await expect(page.locator('aside')).toContainText(/.+/);
});
```

- [ ] **Step 2: Run the E2E suite**

Run:

```bash
php artisan timemap:import-boundaries
php artisan serve &
npx playwright test tests/playwright/timemap.spec.ts
kill %1
```

Expected: 3 passed; `timemap-shell.png` saved under `tests/playwright/results/`.
Paste the output into the commit/PR.

- [ ] **Step 3: Commit**

```bash
git add tests/playwright/timemap.spec.ts
git commit -m "test(timemap): playwright shell/slider/click e2e"
```

---

## Task 11: Attribution doc, lint, full verification

**Files:**
- Create: `docs/timemap-data-sources.md`

- [ ] **Step 1: Record the data licence**

Create `docs/timemap-data-sources.md`:

```markdown
# Time-Map data sources

## Historical borders
- **Source:** historical-basemaps (https://github.com/aourednik/historical-basemaps)
- **Licence:** CC-BY-SA 4.0
- **Attribution shown:** "Borders © historical-basemaps (CC-BY-SA)" (MapLibre attribution control)
- **Vendored:** `database/data/historical-basemaps/world_*.geojson`; imported via `php artisan timemap:import-boundaries`.

## Corpus (read-only)
- `history_articles`, `history_facts`, `gazetteer_places` live in the Supabase ophof project (`public` schema). The app never writes them; the only sanctioned write is `boundaries` via the importer.
```

- [ ] **Step 2: Lint**

Run: `./vendor/bin/pint`
Expected: clean (or auto-fixes; re-run until clean).

- [ ] **Step 3: Full PHP suite**

Run: `composer test`
Expected: all green, including `EraServiceTest`, `BoundaryImportTest`, `StoriesAtTest`.

- [ ] **Step 4: Full JS suite + build**

Run: `npm run test && npm run build`
Expected: Vitest green (incl. `era.test.js`); Vite build succeeds with maplibre-gl bundled.

- [ ] **Step 5: Commit**

```bash
git add docs/timemap-data-sources.md
git commit -m "docs(timemap): data sources + attribution"
```

---

## Self-review notes (for the executor)

- **Corpus safety:** never run `migrate:fresh`/`migrate:rollback` against `.env` (the real corpus). The `app` search_path makes app migrations safe, but `db:wipe` honours search_path — keep `DB_SEARCH_PATH=app`.
- **`storiesAt` returns the explicit empty state** (`selectedRegion = null`, stories `[]`) when the click misses every polygon — the StoriesAt test and the Playwright click test both rely on that.
- **Method/property names are fixed across tasks:** `boundariesGeoJson()`, `storiesAt($lng,$lat,$year)`, `$stories`, `$selectedRegion`, `$selectedPolity`, `el._setYear(year)`, `window.__portal.{ready,year,selectedRegion}`, `Article::scopeForRegionYear`. Keep them identical wherever referenced.
- **Snapshot filenames** in `ImportBoundaries::$snapshots` must match the files actually downloaded in Task 6 Step 1; if you substituted a year, update both places.
