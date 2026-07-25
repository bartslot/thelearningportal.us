<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Console\Command;

/**
 * Unpublish lessons that do not meet the current classroom minimum.
 *
 * The bar a lesson must clear to stay in front of teachers: at least ten scenes, a prior-knowledge
 * quiz and an assessment quiz, and narration on its story scenes. Anything below that is demoted to
 * draft rather than deleted, so nothing is lost and a teacher can still finish it in the wizard.
 */
class RetireLegacyLessons extends Command
{
    protected $signature = 'lessons:retire-legacy
        {--min-scenes=10 : Minimum scene count to stay published}
        {--dry : List what would be retired without changing anything}';

    protected $description = 'Demote published lessons that fall below the classroom minimum to draft';

    public function handle(): int
    {
        $minScenes = max(1, (int) $this->option('min-scenes'));
        $dry = (bool) $this->option('dry');

        $published = Lesson::where('status', LessonStatus::Published)->orderBy('id')->get();
        $retired = 0;
        $kept = 0;

        foreach ($published as $lesson) {
            $scenes = $lesson->scenes()->count();
            $quizzes = $lesson->scenes()->where('kind', 'game')->count();
            $narrated = $lesson->scenes()->whereNotNull('audio_path')->count();

            $reasons = [];
            if ($scenes < $minScenes) {
                $reasons[] = "only {$scenes} scenes";
            }
            if ($quizzes < 2) {
                $reasons[] = "{$quizzes} quiz scene(s), needs a before and an after";
            }
            if ($narrated === 0) {
                $reasons[] = 'no narration';
            }

            if ($reasons === []) {
                $kept++;
                $this->line("  keep    {$lesson->lesson_code}  {$lesson->title}");

                continue;
            }

            $retired++;
            $this->warn("  retire  {$lesson->lesson_code}  {$lesson->title} — ".implode('; ', $reasons));
            if (! $dry) {
                $lesson->update(['status' => LessonStatus::Draft]);
            }
        }

        $this->newLine();
        $this->info($dry
            ? "Dry run: {$retired} would be retired, {$kept} kept."
            : "Retired {$retired} lesson(s); {$kept} remain published.");

        return self::SUCCESS;
    }
}
