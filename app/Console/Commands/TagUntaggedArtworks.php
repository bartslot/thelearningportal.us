<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenAiLlmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LLM-tag untagged corpus artworks into the curriculum vocabulary (depicts/theme/form
 * facets into tags[], plus depicted_era / era_flexible / region columns) so they enter
 * the Step-3 picker's match-scoring. Mirrors the July-15 faceted tagging pass, as a
 * proper command instead of an ad-hoc tinker script.
 */
class TagUntaggedArtworks extends Command
{
    protected $signature = 'artwork:tag-untagged
        {--source= : only works from this source (e.g. europeana)}
        {--limit=1000 : max works to tag this run}';

    protected $description = 'LLM-tag untagged corpus artworks into the curriculum vocab (gpt-4o-mini)';

    public function handle(OpenAiLlmService $llm): int
    {
        $c = DB::connection('pgsql_corpus');

        $vocab = [];
        foreach ($c->table('curriculum_themes')->get(['tag', 'facet']) as $row) {
            $vocab[$row->facet][] = $row->tag;
        }
        if (empty($vocab['depicts'])) {
            $this->error('curriculum_themes vocab is empty — aborting.');

            return self::FAILURE;
        }

        $system = "You classify a historical artwork into a controlled vocabulary. Reply ONLY with JSON:\n"
            .'{"depicts":[up to 3 from: '.implode(',', $vocab['depicts'])."],\n"
            .' "themes":[up to 3 from: '.implode(',', $vocab['theme'])."],\n"
            .' "form":[up to 2 from: '.implode(',', $vocab['form'])."],\n"
            .' "era": one of ['.implode(',', $vocab['era'])."] for the period DEPICTED (not painted), or null,\n"
            .' "era_flexible": true when the subject is not tied to one era (landscape, portrait, city view),'."\n"
            .' "region": one of [european,americas,asia,africa,universal] for the SUBJECT'."'".'s region}'."\n"
            .'Use only listed slugs. Dutch titles are common (Tachtigjarige Oorlog = Eighty Years War era ~1568-1648).';

        $works = $c->table('artworks')
            ->whereNull('tags')
            ->when($this->option('source'), fn ($q, $s) => $q->where('source', $s))
            ->limit((int) $this->option('limit'))
            ->get(['qid', 'title', 'creator_name', 'inception_year']);

        $this->info('Tagging '.count($works).' works…');
        $done = 0;
        $fail = 0;

        foreach ($works as $w) {
            try {
                $user = "Title: {$w->title}\nCreator: ".($w->creator_name ?: 'unknown')."\nYear: ".($w->inception_year ?: '?');
                $out = $llm->json($system, $user);
                $tags = array_values(array_unique(array_merge(
                    array_intersect((array) ($out['depicts'] ?? []), $vocab['depicts']),
                    array_intersect((array) ($out['themes'] ?? []), $vocab['theme']),
                    array_intersect((array) ($out['form'] ?? []), $vocab['form']),
                )));
                if ($tags === []) {
                    $fail++;

                    continue;
                }
                $era = in_array($out['era'] ?? null, $vocab['era'], true) ? $out['era'] : null;
                $region = in_array($out['region'] ?? null, ['european', 'americas', 'asia', 'africa', 'universal'], true)
                    ? $out['region'] : 'european';
                $c->table('artworks')->where('qid', $w->qid)->update([
                    'tags' => '{'.implode(',', array_map(fn ($t) => '"'.$t.'"', $tags)).'}',
                    'depicted_era' => $era,
                    'era_flexible' => (bool) ($out['era_flexible'] ?? false),
                    'region' => $region,
                ]);
                $done++;
                if ($done % 25 === 0) {
                    $this->line("tagged {$done}/".count($works));
                }
            } catch (\Throwable $e) {
                $fail++;
                if ($fail <= 3) {
                    $this->warn("ERR {$w->qid}: ".substr($e->getMessage(), 0, 90));
                }
            }
        }

        $this->info("DONE: tagged={$done} failed={$fail} of ".count($works));

        return self::SUCCESS;
    }
}
