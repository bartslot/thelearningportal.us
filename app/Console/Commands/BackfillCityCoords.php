<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use App\Services\WikidataPolityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fill in lat/lng for curated stub cities (created by HistoricalCityNamesSeeder with 0/0 when the
 * Natural Earth import never ran) from their Wikidata item's coordinate (P625). A stub city breaks
 * the wizard map block: its focus pin lands at (0,0) in the Gulf of Guinea and drags the camera
 * fit there.
 */
class BackfillCityCoords extends Command
{
    protected $signature = 'cities:backfill-coords {--dry-run : Show what would change without writing}';

    protected $description = 'Backfill zero/missing city coordinates from Wikidata P625';

    public function handle(): int
    {
        $stubs = City::query()
            ->where(fn ($w) => $w->where(fn ($z) => $z->where('lat', 0)->where('lng', 0))
                ->orWhereNull('lat')->orWhereNull('lng'))
            ->whereNotNull('wikidata_qid')
            ->get(['id', 'name', 'wikidata_qid']);

        if ($stubs->isEmpty()) {
            $this->info('No stub cities — nothing to backfill.');

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($stubs->chunk(50) as $chunk) {
            $ids = $chunk->pluck('wikidata_qid')->unique()->implode('|');
            $entities = Http::withHeaders(['User-Agent' => WikidataPolityResolver::USER_AGENT])
                ->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbgetentities', 'ids' => $ids,
                    'props' => 'claims', 'format' => 'json',
                ])->json('entities', []);

            foreach ($chunk as $city) {
                $coord = $entities[$city->wikidata_qid]['claims']['P625'][0]['mainsnak']['datavalue']['value'] ?? null;
                if (! is_array($coord) || ! isset($coord['latitude'], $coord['longitude'])) {
                    $this->warn("{$city->name} ({$city->wikidata_qid}): no P625 coordinate on Wikidata — left as stub.");

                    continue;
                }
                $this->line(sprintf('%-18s %s -> %.4f, %.4f', $city->name, $city->wikidata_qid, $coord['latitude'], $coord['longitude']));
                if (! $this->option('dry-run')) {
                    $city->update(['lat' => $coord['latitude'], 'lng' => $coord['longitude']]);
                }
                $updated++;
            }
        }

        $this->info(($this->option('dry-run') ? '[dry-run] would backfill ' : 'backfilled ')."{$updated} of {$stubs->count()} stub cities");

        return self::SUCCESS;
    }
}
