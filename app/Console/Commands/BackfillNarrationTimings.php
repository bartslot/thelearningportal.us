<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateSceneAudio;
use App\Models\Lesson;
use App\Models\Scene;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-narrate scenes whose audio carries no character timings.
 *
 * For months the ElevenLabs API key was rejected on every request, so `tryElevenLabs()` returned
 * null and the fallback chain quietly served Azure, edge-tts or the macOS `say` voice instead.
 * Those providers return audio and nothing else, so the scenes have audio_alignment = [] and their
 * subtitles are spread evenly over the script rather than following the voice — drifting by well
 * over a second by the end of a scene.
 *
 * The only fix for an existing scene is to synthesise it again, which costs ElevenLabs characters.
 * So: dry run by default, --limit to cap a run, and the character count is always printed BEFORE
 * anything is spent.
 */
class BackfillNarrationTimings extends Command
{
    protected $signature = 'lessons:backfill-narration-timings
        {--apply : Actually re-narrate. Without this the command only reports.}
        {--lesson= : Restrict to one lesson id or lesson_code.}
        {--published : Only lessons students can already see.}
        {--limit=0 : Stop after this many scenes (0 = no limit). Use it to cap spend.}';

    protected $description = 'Re-narrate scenes that have audio but no character timings';

    public function handle(): int
    {
        $scenes = Scene::query()
            ->whereNotNull('audio_path')
            ->whereNotNull('script_segment')
            ->whereRaw("(audio_alignment IS NULL OR audio_alignment::text IN ('[]', 'null'))")
            ->when($this->option('lesson'), fn ($q, $ref) => $q->whereIn(
                'lesson_id',
                Lesson::where('id', is_numeric($ref) ? (int) $ref : 0)->orWhere('lesson_code', $ref)->pluck('id'),
            ))
            ->when($this->option('published'), fn ($q) => $q->whereIn(
                'lesson_id',
                Lesson::where('status', 'published')->pluck('id'),
            ))
            ->with('lesson.avatar')
            ->orderBy('lesson_id')
            ->orderBy('order')
            ->get();

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $scenes = $scenes->take($limit);
        }

        if ($scenes->isEmpty()) {
            $this->info('Every scene with audio already has character timings.');

            return self::SUCCESS;
        }

        $characters = $scenes->sum(fn (Scene $s): int => mb_strlen((string) $s->script_segment));

        $this->table(
            ['Lesson', 'Code', 'Scenes', 'Characters'],
            $scenes->groupBy('lesson_id')->map(fn ($group, $lessonId) => [
                $lessonId,
                $group->first()->lesson?->lesson_code ?? '?',
                $group->count(),
                number_format($group->sum(fn (Scene $s): int => mb_strlen((string) $s->script_segment))),
            ])->values()->all(),
        );

        $this->line(sprintf(
            '%d scenes, %s characters of narration.',
            $scenes->count(),
            number_format($characters),
        ));

        if (! $this->option('apply')) {
            $this->warn('Dry run. Re-run with --apply to spend those characters and re-narrate.');

            return self::SUCCESS;
        }

        $failed = 0;
        $bar = $this->output->createProgressBar($scenes->count());
        $bar->start();

        foreach ($scenes as $scene) {
            try {
                GenerateSceneAudio::dispatchSync($scene->id);
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Scene {$scene->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Report what actually came back, not what was asked for — the whole point of this fix is
        // that "we asked for ElevenLabs" was never evidence that ElevenLabs answered.
        $refreshed = Scene::whereIn('id', $scenes->pluck('id'))->get(['id', 'audio_provider', 'audio_alignment']);
        $this->table(
            ['Provider', 'Scenes', 'With timings'],
            $refreshed->groupBy(fn (Scene $s): string => $s->audio_provider ?? 'unknown')
                ->map(fn ($group, $provider) => [
                    $provider,
                    $group->count(),
                    $group->filter(fn (Scene $s): bool => ! empty($s->audio_alignment))->count(),
                ])->values()->all(),
        );

        if ($failed > 0) {
            $this->error("{$failed} scenes failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
