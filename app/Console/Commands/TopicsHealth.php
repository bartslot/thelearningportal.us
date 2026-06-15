<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Validates the topics catalog (A3): a topic must be time-placeable (has at least one era year)
 * to appear in the picker. Polities/figures with no era at all are flagged is_publishable=false
 * and drop out of the public.topics view. Reports counts.
 */
class TopicsHealth extends Command
{
    protected $signature = 'timemap:topics-health {--fix : Apply is_publishable flags (default is report-only)}';

    protected $description = 'Validate the topics catalog: flag time-unplaceable entries (A3)';

    public function handle(): int
    {
        $corpus = DB::connection('pgsql_corpus');
        $fix = (bool) $this->option('fix');

        // A topic is time-placeable if it has an inception/dissolution (polity) or era (figure).
        $polTotal = $corpus->table('public.polities')->whereNotNull('wikipedia_url')->whereNotNull('osm_id')->count();
        $polNoEra = $corpus->table('public.polities')
            ->whereNotNull('wikipedia_url')->whereNotNull('osm_id')
            ->whereNull('inception')->whereNull('dissolution')->count();

        $figTotal = $corpus->table('public.figures')->count();
        $figNoEra = $corpus->table('public.figures')->whereNull('era_start')->whereNull('era_end')->count();

        $this->info('Topics catalog health:');
        $this->line(sprintf('  polities: %d total, %d valid, %d flagged (no era)',
            $polTotal, $polTotal - $polNoEra, $polNoEra));
        $this->line(sprintf('  figures:  %d total, %d valid, %d flagged (no era)',
            $figTotal, $figTotal - $figNoEra, $figNoEra));

        if (! $fix) {
            $this->comment('  (report-only — pass --fix to apply is_publishable flags)');

            return self::SUCCESS;
        }

        // Flag time-unplaceable entries.
        $corpus->table('public.polities')
            ->whereNull('inception')->whereNull('dissolution')
            ->update(['is_publishable' => false]);
        $corpus->table('public.polities')
            ->where(fn ($q) => $q->whereNotNull('inception')->orWhereNotNull('dissolution'))
            ->update(['is_publishable' => true]);

        $corpus->table('public.figures')
            ->whereNull('era_start')->whereNull('era_end')
            ->update(['is_publishable' => false]);
        $corpus->table('public.figures')
            ->where(fn ($q) => $q->whereNotNull('era_start')->orWhereNotNull('era_end'))
            ->update(['is_publishable' => true]);

        $publishable = $corpus->table('public.topics')->count();
        $this->info("  applied. {$publishable} publishable topics now in the picker.");

        return self::SUCCESS;
    }
}
