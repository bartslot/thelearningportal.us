<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

trait SeedsCorpusFixtures
{
    protected function setUpCorpusFixtures(): void
    {
        // Hard stop: this trait DROPs corpus tables. It must never run anywhere but the
        // local test database, or it could destroy the irreplaceable production corpus.
        if (app()->environment() !== 'testing') {
            throw new \RuntimeException('SeedsCorpusFixtures must only run in the testing environment.');
        }

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
