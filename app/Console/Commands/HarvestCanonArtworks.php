<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Top the artwork corpus up with the paintings the world actually knows.
 *
 * The bulk Wikidata harvest that built this corpus selected catalogue entries, not famous works:
 * 95% of its rows are QIDs above Q10,000,000 (museum bulk imports), and it held 94 Rembrandts
 * without The Night Watch, 407 Louvre works without the Mona Lisa. A teacher searching "night
 * watch" got "nothing in the curated collection" in front of the most reproduced painting in
 * Dutch history.
 *
 * Fame is measurable: an item's Wikipedia sitelink count. Roughly 1,500 paintings have 10+, and
 * that set is essentially every work a K-12 history lesson would ever name.
 *
 *   php artisan artwork:harvest-canon --min-sitelinks=10
 *   php artisan artwork:harvest-canon --dry-run
 */
class HarvestCanonArtworks extends Command
{
    protected $signature = 'artwork:harvest-canon
        {--min-sitelinks=10 : how well known a painting must be (Wikipedia language links)}
        {--limit=2500 : cap on rows pulled from Wikidata}
        {--dry-run : show what would be written, write nothing}';

    protected $description = 'Add the best-known paintings (by Wikipedia sitelinks) to the artwork corpus';

    private const ENDPOINT = 'https://query.wikidata.org/sparql';

    /** Wikimedia asks for a descriptive agent with a contact; anonymous bulk querying gets throttled. */
    private const AGENT = 'LearningPortal/1.0 (thelearningportal.us; corpus canon harvest)';

    /** Copyright expires 70 years after the author's death in the EU; leave a year of margin. */
    private const PD_YEARS = 71;

    public function handle(): int
    {
        $min = max(1, (int) $this->option('min-sitelinks'));
        $limit = max(1, (int) $this->option('limit'));

        $this->info("Fetching paintings with {$min}+ Wikipedia sitelinks…");
        try {
            $bindings = $this->query($this->sparql($min, $limit));
        } catch (Throwable $e) {
            $this->error('Wikidata query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // One row per painting: a work in two collections comes back twice.
        $rows = collect($bindings)->map(fn (array $b) => $this->toRow($b))
            ->filter()
            ->keyBy('qid')
            ->values();

        $this->line(sprintf('  %d results → %d distinct paintings', count($bindings), $rows->count()));

        $pd = $rows->where('pd_likely', true);
        $this->line(sprintf('  %d public domain, %d still in copyright (kept, but hidden from search)',
            $pd->count(), $rows->count() - $pd->count()));

        if ($rows->isEmpty()) {
            $this->warn('Nothing to write.');

            return self::SUCCESS;
        }

        // Dutch titles, in a SEPARATE pass. Asking the main query for a second language (two extra
        // OPTIONAL rdfs:label patterns over ~1,500 items, under an ORDER BY) reliably timed the
        // public endpoint out with a 504. Bounded by VALUES, in chunks, it answers in well under a
        // second — and the same method can later backfill the 8.5k rows the bulk harvest left with
        // only one language.
        $rows = $this->withDutchTitles($rows);

        $existing = DB::connection('pgsql_corpus')->table('artworks')
            ->whereIn('qid', $rows->pluck('qid'))->pluck('qid')->flip();
        $new = $rows->reject(fn ($r) => $existing->has($r['qid']));
        $this->line(sprintf('  %d new, %d already in the corpus (will be refreshed)', $new->count(), $rows->count() - $new->count()));

        if ($this->option('dry-run')) {
            $this->table(['qid', 'title', 'creator', 'year', 'pd'],
                $new->take(15)->map(fn ($r) => [$r['qid'], mb_strimwidth($r['title'], 0, 42, '…'), mb_strimwidth((string) $r['creator_name'], 0, 22, '…'), $r['inception_year'], $r['pd_likely'] ? 'yes' : 'no'])->all());
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        // Chunked so one oversized statement cannot time the pooler out mid-write.
        foreach ($rows->chunk(200) as $chunk) {
            DB::connection('pgsql_corpus')->table('artworks')->upsert(
                $chunk->all(),
                ['qid'],
                ['title', 'title_lang', 'creator_qid', 'creator_name', 'creator_death_year',
                    'inception_year', 'image_url', 'source', 'license_note', 'pd_likely', 'kind',
                    'collection', 'creator_sitelinks', 'quality', 'source_uri', 'rights_uri', 'extra'],
            );
            $this->output->write('.');
        }
        $this->newLine();
        $this->info("Done: {$rows->count()} paintings upserted ({$new->count()} new).");

        return self::SUCCESS;
    }

    /**
     * Rank by sitelinks: how many Wikipedia language editions wrote an article about the painting.
     * It is the closest thing to a fame score the data offers, and it needs no curation from us.
     */
    private function sparql(int $minSitelinks, int $limit): string
    {
        return <<<SPARQL
        SELECT ?item ?itemLabel ?creator ?creatorLabel ?death ?inception ?image ?collLabel ?sitelinks ?creatorLinks ?copyright WHERE {
          ?item wdt:P31 wd:Q3305213 ;
                wdt:P18 ?image ;
                wikibase:sitelinks ?sitelinks .
          FILTER(?sitelinks >= {$minSitelinks})
          OPTIONAL {
            ?item wdt:P170 ?creator .
            OPTIONAL { ?creator wdt:P570 ?death }
            OPTIONAL { ?creator wikibase:sitelinks ?creatorLinks }
          }
          OPTIONAL { ?item wdt:P571 ?inception }
          OPTIONAL { ?item wdt:P195 ?coll }
          OPTIONAL { ?item wdt:P6216 ?copyright }
          SERVICE wikibase:label { bd:serviceParam wikibase:language "en,nl" }
        }
        ORDER BY DESC(?sitelinks)
        LIMIT {$limit}
        SPARQL;
    }

    /**
     * Merge each painting's Dutch label into its `extra`, so a Dutch search finds an
     * English-titled work and the other way round.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function withDutchTitles($rows)
    {
        $found = 0;
        $dutch = [];
        foreach ($rows->pluck('qid')->chunk(400) as $chunk) {
            $values = $chunk->map(fn ($q) => "wd:{$q}")->implode(' ');
            $sparql = <<<SPARQL
            SELECT ?item ?label WHERE {
              VALUES ?item { {$values} }
              ?item rdfs:label ?label .
              FILTER(LANG(?label) = "nl")
            }
            SPARQL;
            try {
                foreach ($this->query($sparql) as $b) {
                    $qid = basename((string) ($b['item']['value'] ?? ''));
                    $label = trim((string) ($b['label']['value'] ?? ''));
                    if ($qid !== '' && $label !== '') {
                        $dutch[$qid] = $label;
                        $found++;
                    }
                }
            } catch (Throwable $e) {
                // A missing Dutch title costs a Dutch teacher one search term, not the harvest.
                $this->warn('  Dutch labels unavailable for one chunk: '.$e->getMessage());
            }
            $this->output->write('.');
        }
        $this->newLine();
        $this->line("  {$found} Dutch titles alongside the English ones");

        return $rows->map(function (array $r) use ($dutch) {
            $nl = $dutch[$r['qid']] ?? null;
            if ($nl === null || $nl === $r['title']) {
                return $r;
            }
            $extra = json_decode((string) $r['extra'], true) ?: [];
            $extra['title_nl'] = $nl;
            $r['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE);

            return $r;
        });
    }

    /**
     * Ask Wikidata, with patience.
     *
     * The public query service throttles heavy queries and returns 429/502/504 under load. An
     * empty result set is treated as a failure too: this query matches ~1,500 paintings, so zero
     * rows means the service gave up, not that the canon vanished. Reporting "nothing to write"
     * for that would be the same lie the Commons cache used to tell.
     *
     * @return array<int,array<string,mixed>>
     */
    private function query(string $sparql): array
    {
        $attempts = 3;
        for ($i = 1; $i <= $attempts; $i++) {
            $response = Http::withHeaders(['User-Agent' => self::AGENT, 'Accept' => 'application/sparql-results+json'])
                ->timeout(180)
                ->asForm()
                ->post(self::ENDPOINT, ['query' => $sparql]);

            if ($response->successful()) {
                $bindings = $response->json('results.bindings', []);
                if ($bindings !== []) {
                    return $bindings;
                }
                $reason = 'an empty result set';
            } else {
                $reason = 'HTTP '.$response->status();
            }

            if ($i === $attempts) {
                throw new \RuntimeException("Wikidata returned {$reason} after {$attempts} attempts");
            }
            $wait = 15 * $i;   // the service asks for room; give it some
            $this->warn("  Wikidata returned {$reason} — retrying in {$wait}s ({$i}/{$attempts})");
            sleep($wait);
        }

        return [];   // unreachable; the loop either returns or throws
    }

    /**
     * One SPARQL binding → an artworks row, matching the columns the existing Wikidata rows use.
     *
     * @param  array<string,mixed>  $b
     * @return array<string,mixed>|null
     */
    private function toRow(array $b): ?array
    {
        $qid = basename((string) ($b['item']['value'] ?? ''));
        $image = (string) ($b['image']['value'] ?? '');
        if (! preg_match('/^Q\d+$/', $qid) || $image === '') {
            return null;
        }

        $year = fn (?string $iso) => $iso && preg_match('/(-?\d{1,4})-\d{2}-\d{2}/', $iso, $m) ? (int) $m[1] : null;
        $death = $year($b['death']['value'] ?? null);
        // Wikidata's own copyright statement wins; a long-dead painter is the fallback test.
        $publicDomain = ($b['copyright']['value'] ?? null) === 'http://www.wikidata.org/entity/Q19652'
            || ($death !== null && $death <= ((int) date('Y') - self::PD_YEARS));

        $sitelinks = (int) ($b['sitelinks']['value'] ?? 0);

        $title = trim((string) ($b['itemLabel']['value'] ?? '')) ?: $qid;

        return [
            'qid' => $qid,
            'title' => $title,
            'title_lang' => 'en',
            // Filled in by the Dutch label pass below; the picker promises "Dutch or English".
            'extra' => json_encode(['title_en' => $title], JSON_UNESCAPED_UNICODE),
            'creator_qid' => isset($b['creator']['value']) ? basename((string) $b['creator']['value']) : null,
            'creator_name' => $b['creatorLabel']['value'] ?? null,
            'creator_death_year' => $death,
            'inception_year' => $year($b['inception']['value'] ?? null),
            'image_url' => $image,
            'source' => 'wikidata',
            'license_note' => 'see Commons file page',
            'pd_likely' => $publicDomain,
            'kind' => 'painting',
            'collection' => $b['collLabel']['value'] ?? null,
            'creator_sitelinks' => (int) ($b['creatorLinks']['value'] ?? 0),
            // These are the best-known works in the corpus, so they rank at or above the bulk
            // harvest's flat 90 — the extra points order the canon within itself.
            'quality' => min(100, 90 + intdiv($sitelinks, 15)),
            'source_uri' => 'http://www.wikidata.org/entity/'.$qid,
            'rights_uri' => $publicDomain ? 'http://creativecommons.org/publicdomain/mark/1.0/' : null,
        ];
    }
}
