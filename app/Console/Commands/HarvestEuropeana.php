<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Corpus\EuropeanaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Harvest open-licensed images from Europeana into public.artworks (corpus DB), via the
 * DC/EDM crosswalk. Aimed at widening the country canons — search in the teacher's language
 * (e.g. "zeeslag" for Dutch Golden-Age naval works) finds far more than the English term.
 *
 *   php artisan artwork:harvest-europeana "zeeslag" --country=Netherlands --from=1600 --to=1700
 */
class HarvestEuropeana extends Command
{
    protected $signature = 'artwork:harvest-europeana
        {query : Europeana search term (use the teacher-language word)}
        {--country= : ISO/English country facet, e.g. Netherlands}
        {--from= : earliest object year}
        {--to= : latest object year}
        {--pages=3 : how many pages to pull}
        {--rows=50 : results per page (max 100)}';

    protected $description = 'Harvest open-licensed Europeana images into the artwork corpus';

    public function handle(EuropeanaService $europeana): int
    {
        $query = (string) $this->argument('query');
        $opts = array_filter([
            'country' => $this->option('country'),
            'yearFrom' => $this->option('from') ? (int) $this->option('from') : null,
            'yearTo' => $this->option('to') ? (int) $this->option('to') : null,
            'rows' => (int) $this->option('rows'),
        ], fn ($v) => $v !== null);

        $pages = max(1, (int) $this->option('pages'));
        $cursor = '*';
        $seen = $ingested = 0;

        for ($page = 0; $page < $pages && $cursor !== null; $page++) {
            try {
                $result = $europeana->search($query, [...$opts, 'cursor' => $cursor]);
            } catch (Throwable $e) {
                $this->error('Europeana: '.$e->getMessage());

                return self::FAILURE;
            }

            $rows = array_map(fn (array $r) => $this->toRow($r), $result['items']);
            $seen += count($result['items']);

            if ($rows !== []) {
                DB::connection('pgsql_corpus')->table('artworks')->upsert(
                    $rows,
                    ['qid'],
                    ['title', 'creator_name', 'inception_year', 'image_url', 'source_uri',
                        'rights_uri', 'collection', 'quality', 'era', 'extra', 'language'],
                );
                $ingested += count($rows);
            }

            $this->line(sprintf('page %d: +%d (%d/%d total, cursor %s)',
                $page + 1, count($rows), $ingested, $result['total'], $cursor === '*' ? 'start' : '…'));

            $cursor = $result['cursor'];
        }

        $this->info("Done: {$ingested} works upserted from {$seen} results for \"{$query}\".");

        return self::SUCCESS;
    }

    /** Crosswalked item → artworks row (adds computed quality + paint era + packed extra). */
    private function toRow(array $a): array
    {
        $quality = 45
            + ($a['collection'] ? 15 : 0)
            + ($a['creator_name'] ? 5 : 0)
            + ($a['inception_year'] ? 5 : 0);

        return [
            'qid' => $a['qid'],
            'title' => $a['title'],
            'title_lang' => $a['title_lang'],
            'language' => $a['title_lang'],
            'creator_name' => $a['creator_name'],
            'inception_year' => $a['inception_year'],
            'image_url' => $a['image_url'],
            'source' => 'europeana',
            'source_uri' => $a['source_uri'],
            'license_note' => $a['rights_uri'] ? 'see rights_uri' : 'see Europeana record',
            'rights_uri' => $a['rights_uri'],
            'pd_likely' => $a['pd_likely'],
            'collection' => $a['collection'],
            'kind' => $a['kind'],
            'quality' => $quality,
            'era' => $this->eraOf($a['inception_year']),
            'extra' => json_encode(array_filter([
                'source' => 'europeana',
                'country' => $a['country'],
                'thumb_url' => $a['thumb_url'],
            ])),
            'created_at' => now(),
        ];
    }

    private function eraOf(?int $year): ?string
    {
        if ($year === null) {
            return null;
        }

        return match (true) {
            $year < 500 => 'antiquity',
            $year < 1400 => 'middle-ages',
            $year < 1580 => 'renaissance',
            $year < 1700 => 'baroque',
            $year < 1780 => 'enlightenment',
            $year < 1820 => 'napoleonic',
            $year < 1900 => 'nineteenth-century',
            default => 'modern',
        };
    }
}
