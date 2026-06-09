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
