<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TerritoryReport;
use Illuminate\Console\Command;

/**
 * What teachers have reported as wrong on the Time-Map.
 *
 * The report button is only half the loop. A table nobody reads is a suggestion box in a locked
 * room, so this is the way in — and it prints the CONTEXT alongside the complaint, because "the
 * Cherusci border is wrong" is not actionable and "the Cherusci at 9 CE, drawn from the Barrington
 * Atlas, confidence medium" is.
 */
final class ShowTerritoryReports extends Command
{
    protected $signature = 'timemap:territory-reports
        {--limit=30 : How many to show, newest first}
        {--hand : Only reports about territories we drew ourselves}
        {--territory= : Only reports about this territory id or Wikidata QID}';

    protected $description = 'Show what teachers have reported as wrong on the Time-Map';

    public function handle(): int
    {
        $query = TerritoryReport::query()->latest();

        if ($this->option('hand')) {
            $query->where('source', 'hand');
        }

        if ($territory = $this->option('territory')) {
            $query->where(fn ($q) => $q->where('territory_id', $territory)->orWhere('wikidata_qid', $territory));
        }

        $reports = $query->limit((int) $this->option('limit'))->get();

        if ($reports->isEmpty()) {
            $this->info('No reports.');

            return self::SUCCESS;
        }

        $this->info($reports->count().' report'.($reports->count() === 1 ? '' : 's').', newest first');
        $this->newLine();

        foreach ($reports as $report) {
            $this->line(sprintf(
                '<options=bold>%s</> at %s  <fg=gray>[%s]</>',
                $report->label,
                self::era($report->year),
                $report->source,
            ));

            // Only meaningful for a shape we drew: a Cliopatria territory has no chosen source of
            // ours to name, and printing an empty one would suggest we simply failed to record it.
            if ($report->isHandAuthored()) {
                $this->line(sprintf(
                    '  <fg=gray>drawn from %s, confidence %s</>',
                    $report->chosen_source ?? 'unrecorded',
                    $report->confidence ?? 'unrecorded',
                ));
            }

            $this->line('  "'.trim($report->comment).'"');
            $this->line(sprintf(
                '  <fg=gray>#%d · %s · %s</>',
                $report->id,
                $report->created_at?->diffForHumans() ?? 'unknown date',
                $report->territory_id ?? $report->wikidata_qid ?? 'unidentified',
            ));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** The year as a teacher saw it on the slider, not as a signed integer. */
    private static function era(int $year): string
    {
        return $year < 0 ? abs($year).' BCE' : $year.' CE';
    }
}
