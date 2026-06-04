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
