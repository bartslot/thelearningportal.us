<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Corpus\Artwork;
use App\Models\Corpus\Topic;
use App\Services\StoryImages\EventImageHarvester;
use App\Services\StoryImages\StoryImageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make sure events have imagery: harvest open-licensed Commons originals by event
 * name + aliases and link them, so the scene picker is never starved — even for the
 * many events with no structured "depicts" data.
 *
 *   php artisan story:backfill-events --event=Q42005
 *   php artisan story:backfill-events --used          # events referenced by lessons
 *   php artisan story:backfill-events --all --min=3 --limit=10
 */
final class BackfillEventImages extends Command
{
    protected $signature = 'story:backfill-events
        {--event= : a single event QID (Q123…)}
        {--used : only events referenced by a lesson topic (default scope)}
        {--all : every catalog event, most-notable first}
        {--take= : cap the number of events processed (most-notable first)}
        {--min=3 : skip events that already have at least this many story images}
        {--limit=10 : max images to harvest per event}
        {--dry : resolve but do not write}';

    protected $description = 'Harvest Commons imagery by event name/aliases so events used in lessons always have pictures.';

    public function handle(EventImageHarvester $harvester, StoryImageStore $store): int
    {
        $events = $this->targets();
        if ($events === []) {
            $this->warn('No events matched the given scope.');

            return self::SUCCESS;
        }

        $min = (int) $this->option('min');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');
        $this->info(count($events)." event(s) in scope · min={$min} limit={$limit}".($dry ? ' · dry' : ''));

        $totalStored = 0;
        foreach ($events as $ev) {
            $have = Topic::resilient(fn () => Artwork::storyFor($ev->qid, 100)->count());
            if ($have >= $min) {
                $this->line("  · {$ev->name} — has {$have}, skipping");
                continue;
            }

            $images = $harvester->harvest($ev->name, $ev->aliases ?? null, $limit);
            $stored = 0;
            foreach ($images as $img) {
                if (! $dry) {
                    $store->store($img, $ev->qid, $ev->name);
                }
                $stored++;
            }
            $totalStored += $stored;
            $this->line("  + {$ev->name} ({$ev->qid}) — harvested {$stored}");
            usleep(300_000);   // be polite to the Commons API between events
        }

        $this->info($dry ? "{$totalStored} images resolved (dry)." : "{$totalStored} images stored across ".count($events).' event(s).');

        return self::SUCCESS;
    }

    /** @return list<object{qid:string,name:string,aliases:?string}> */
    private function targets(): array
    {
        $events = DB::connection('pgsql_corpus')->table('catalog_events');

        if ($qid = $this->option('event')) {
            return $events->where('qid', $qid)->get(['qid', 'name', 'aliases'])->all();
        }

        if ($this->option('all')) {
            return $events->orderByDesc('sitelinks')
                ->when($this->option('take'), fn ($q) => $q->limit((int) $this->option('take')))
                ->get(['qid', 'name', 'aliases'])->all();
        }

        // Default / --used: events that appear as a lesson topic.
        $qids = \App\Models\Lesson::query()
            ->where('topic_id', 'like', 'event:%')
            ->distinct()
            ->pluck('topic_id')
            ->map(fn ($t) => str_replace('event:', '', (string) $t))
            ->all();

        return $qids === []
            ? []
            : $events->whereIn('qid', $qids)->get(['qid', 'name', 'aliases'])->all();
    }
}
