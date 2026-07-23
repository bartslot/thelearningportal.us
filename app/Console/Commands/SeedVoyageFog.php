<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copies each voyage's catalog `unknown` fog default into the lesson's EDITABLE game_config.voyage_fog.
 *
 * The catalog polygon is no longer baked into the map at render time (see voyage-tour.js) — fog is now
 * driven solely by the per-lesson voyage_fog so teachers can reshape/erase it. This backfills existing
 * lessons that predate that change. Idempotent: only lessons whose voyage_fog is empty are touched,
 * unless --force is passed (which overwrites, e.g. after editing a catalog default).
 */
class SeedVoyageFog extends Command
{
    protected $signature = 'voyages:seed-fog {--force : Overwrite voyage_fog even if the lesson already has painted regions}';

    protected $description = 'Seed each voyage lesson\'s editable fog (voyage_fog) from the catalog default';

    public function handle(): int
    {
        $catalog = $this->catalog();
        $force = (bool) $this->option('force');
        $seeded = 0;
        $skipped = 0;

        foreach (Lesson::where('game_type', 'voyage')->get() as $lesson) {
            $gc = $lesson->game_config ?? [];
            $voyageId = $gc['voyage'] ?? null;
            $default = $voyageId ? ($catalog[$voyageId]['unknown'] ?? null) : null;

            if (empty($default) || ! is_array($default)) {
                $this->line("  <fg=gray>skip</> #{$lesson->id} {$lesson->title} — no catalog fog for '{$voyageId}'");
                $skipped++;

                continue;
            }

            if (! empty($gc['voyage_fog']) && ! $force) {
                $this->line("  <fg=gray>skip</> #{$lesson->id} {$lesson->title} — already has ".count($gc['voyage_fog']).' region(s)');
                $skipped++;

                continue;
            }

            $gc['voyage_fog'] = array_values($default);
            $lesson->update(['game_config' => $gc]);
            $this->line("  <fg=green>seed</> #{$lesson->id} {$lesson->title} — ".count($default).' region(s) from '.$voyageId);
            $seeded++;
        }

        $this->info("Done. Seeded {$seeded}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /** @return array<string,array<string,mixed>> keyed by voyage id */
    private function catalog(): array
    {
        $json = json_decode(File::get(resource_path('js/timemap/voyages.json')), true);
        $out = [];
        foreach (($json['voyages'] ?? []) as $v) {
            if (! empty($v['id'])) {
                $out[$v['id']] = $v;
            }
        }

        return $out;
    }
}
