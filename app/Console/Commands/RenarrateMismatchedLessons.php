<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateSceneAudio;
use App\Models\Lesson;
use App\Models\Scene;
use App\Services\Support\ScriptLanguage;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-narrate scenes whose audio was generated in the wrong language.
 *
 * Narration used to take its voice from the teacher's INTERFACE locale, so a Dutch teacher's
 * English lesson was read aloud by a Dutch voice. Now that teaching language is a separate profile
 * setting, this finds the scenes that were narrated under the old assumption and redoes just those.
 *
 * Dry run by default: re-synthesising audio costs money and overwrites files, so it will not touch
 * anything until you pass --apply.
 */
class RenarrateMismatchedLessons extends Command
{
    protected $signature = 'lessons:renarrate-mismatched
        {--apply : Actually re-narrate. Without this the command only reports.}
        {--lesson= : Restrict to one lesson id or lesson_code.}';

    protected $description = 'Find scenes narrated in the wrong language and re-narrate them';

    public function handle(): int
    {
        $lessons = Lesson::with(['teacher', 'scenes'])
            ->when($this->option('lesson'), function ($q, $ref) {
                $q->where('id', is_numeric($ref) ? (int) $ref : 0)->orWhere('lesson_code', $ref);
            })
            ->get();

        $apply = (bool) $this->option('apply');
        $rows = [];
        $toFix = [];

        foreach ($lessons as $lesson) {
            // What the voice WOULD be chosen as now, for this teacher.
            $expected = $lesson->teacher?->teachingLocale() ?? 'en';

            foreach ($lesson->scenes as $scene) {
                if (! $scene->audio_path || ! $scene->script_segment) {
                    continue;
                }
                // The language the script is actually in. Where the detector is confident and
                // disagrees with what the lesson is meant to be taught in, the audio is suspect.
                $detected = ScriptLanguage::detect($scene->script_segment, $expected);
                if ($detected === $expected) {
                    continue;
                }

                $toFix[] = $scene;
                $rows[] = [
                    $lesson->lesson_code,
                    mb_substr((string) $lesson->title, 0, 30),
                    $scene->order,
                    $expected,
                    $detected,
                ];
            }
        }

        if (! $rows) {
            $this->info('No mismatched narration found across '.$lessons->count().' lessons.');

            return self::SUCCESS;
        }

        $this->table(['Lesson', 'Title', 'Scene', 'Teaching', 'Script is'], $rows);
        $this->warn(count($rows).' scene(s) were narrated in a language that does not match the script.');

        if (! $apply) {
            $this->line('Dry run. Re-run with --apply to re-narrate these.');

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        foreach ($toFix as $scene) {
            // Clearing the hash as well as the path is what forces a real re-synthesis rather than
            // a cache hit on the script that produced the wrong-language audio.
            $scene->update(['audio_path' => null, 'audio_script_hash' => null]);
            try {
                GenerateSceneAudio::dispatchSync($scene->id);
                $scene->refresh();
                $scene->audio_path ? $done++ : $failed++;
            } catch (Throwable $e) {
                $failed++;
                $this->error('  scene '.$scene->id.': '.mb_substr($e->getMessage(), 0, 80));
            }
        }

        $this->info("Re-narrated {$done} scene(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
