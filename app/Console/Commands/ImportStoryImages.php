<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CommonsImageService;
use App\Services\StoryImages\HistoriekSource;
use App\Services\StoryImages\StoryImageResolver;
use App\Services\StoryImages\StoryImageStore;
use App\Services\StoryImages\StoryImageSource;
use App\Services\StoryImages\WorldHistorySource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Discover event-matched imagery from history articles and store the open-licensed
 * Commons originals against a catalog event, so the scene picker leads with them.
 *
 *   php artisan story:import-images Q164432 "https://www.worldhistory.org/Eighty_Years'_War/"
 *   php artisan story:import-images "Eighty Years" <url> <url> --dry
 */
final class ImportStoryImages extends Command
{
    protected $signature = 'story:import-images
        {event : event QID (Q123…) or a name to look up in catalog_events}
        {url* : one or more article URLs (worldhistory.org / historiek.net)}
        {--dry : scrape + resolve but do not write to the corpus}';

    protected $description = 'Scrape history articles, resolve images to open-licensed Commons originals, and link them to an event.';

    public function handle(StoryImageStore $store): int
    {
        [$eventQid, $eventName] = $this->resolveEvent((string) $this->argument('event'));
        if ($eventQid === null) {
            $this->error('No catalog event matched "'.$this->argument('event').'".');

            return self::FAILURE;
        }
        $this->info("Event: {$eventName} ({$eventQid})".($this->option('dry') ? '  [dry run]' : ''));

        $sources = [new WorldHistorySource(), new HistoriekSource()];
        $resolver = new StoryImageResolver(new CommonsImageService());
        $stored = 0;
        $rows = [];

        foreach ((array) $this->argument('url') as $url) {
            $source = $this->sourceFor($sources, $url);
            if ($source === null) {
                $this->warn("  no source handles {$url}");
                continue;
            }

            $article = $source->fetch($url);
            if ($article === null) {
                $this->warn("  could not fetch/parse {$url}");
                continue;
            }
            $this->line("  {$article->title} — {$article->totalWords()} words, "
                ."{$article->shrinkRatio()}:1 shrink, ".count($article->images()).' images');

            foreach ($resolver->resolveArticle($article) as $img) {
                if (! $this->option('dry')) {
                    $store->store($img, $eventQid, $eventName);
                    $stored++;
                }
                $rows[] = [$img->sectionTitle, $img->license, $img->commonsFile];
            }
        }

        if ($rows !== []) {
            $this->table(['section', 'license', 'commons file'], $rows);
        }
        $this->info($this->option('dry')
            ? count($rows).' images resolved (nothing written).'
            : "{$stored} images stored + linked to {$eventName}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<StoryImageSource>  $sources
     */
    private function sourceFor(array $sources, string $url): ?StoryImageSource
    {
        foreach ($sources as $source) {
            if ($source->supports($url)) {
                return $source;
            }
        }

        return null;
    }

    /** @return array{0:?string,1:?string} [qid, name] */
    private function resolveEvent(string $event): array
    {
        $events = DB::connection('pgsql_corpus')->table('catalog_events');

        $row = preg_match('/^Q\d+$/', $event)
            ? $events->where('qid', $event)->first(['qid', 'name'])
            : $events->where('name', 'ilike', '%'.$event.'%')->orderByDesc('sitelinks')->first(['qid', 'name']);

        return $row ? [$row->qid, $row->name] : [null, null];
    }
}
