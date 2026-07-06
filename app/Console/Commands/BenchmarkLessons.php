<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Quality benchmark harness for the lesson generation engine.
 *
 * Regenerates a FIXED set of topics and dumps the resulting scripts + quizzes
 * to storage/app/benchmarks/{tag}/ so engine changes can be compared before/after:
 *
 *   php artisan lesson:benchmark --tag=baseline        # create + wait + dump
 *   php artisan lesson:benchmark --tag=phase1          # after engine changes
 *   php artisan lesson:benchmark --tag=baseline --dump-only   # re-dump existing run
 *
 * Requires a running queue worker (composer dev). Compare tags by diffing the
 * generated markdown files and the rubric counters at the top of each file.
 */
class BenchmarkLessons extends Command
{
    protected $signature = 'lesson:benchmark
        {--tag=      : Label for this run (required), e.g. baseline, phase1, final}
        {--wait=900  : Max seconds to wait for generation before dumping anyway}
        {--dump-only : Skip generation; re-dump the lessons recorded in the tag manifest}';

    protected $description = 'Regenerate 3 fixed benchmark topics and dump scripts+quizzes with a boring-lesson rubric.';

    private const BENCHMARK_TEACHER_EMAIL = 'benchmark@thelearningportal.test';

    private const POLL_INTERVAL_SECONDS = 10;

    /** Fixed benchmark set — never change these, or cross-tag diffs become meaningless. */
    private const TOPICS = [
        [
            'slug' => 'julius-caesar',
            'topic' => 'Julius Caesar',
            'figure' => 'Julius Caesar',
            'grade' => '8',
            'tone' => 'dramatic',
        ],
        [
            'slug' => 'dutch-golden-age',
            'topic' => 'Dutch Golden Age',
            'figure' => null,
            'grade' => '6',
            'tone' => 'storytelling',
        ],
        [
            'slug' => 'wwii-netherlands',
            'topic' => 'The Netherlands in World War II',
            'figure' => null,
            'grade' => '8',
            'tone' => 'serious',
        ],
    ];

    /** Question stems about scenery/mood instead of history — the "nothing learned" smell. */
    private const SCENERY_QUESTION_PATTERN =
        '/\b(atmosphere|weather|mood|color|colour|looked like|look like|appearance|setting|surroundings|visual|standing|scene)\b/i';

    public function handle(): int
    {
        $tag = (string) $this->option('tag');
        if ($tag === '') {
            $this->error('A --tag is required (e.g. --tag=baseline).');

            return self::FAILURE;
        }

        $dir = "benchmarks/{$tag}";

        $lessons = $this->option('dump-only')
            ? $this->lessonsFromManifest($dir)
            : $this->generateBenchmarkLessons($dir);

        if ($lessons === null) {
            return self::FAILURE;
        }

        if (! $this->option('dump-only')) {
            $this->waitForGeneration($lessons, (int) $this->option('wait'));
        }

        foreach ($lessons as $slug => $lesson) {
            $report = $this->buildReport($lesson->fresh(['scenes', 'quizQuestions']));
            Storage::disk('local')->put("{$dir}/{$slug}.md", $report);
            $this->info("Dumped {$dir}/{$slug}.md");
        }

        $this->printRubricSummary($lessons);
        $this->line('Compare runs with e.g.: diff storage/app/benchmarks/baseline/julius-caesar.md storage/app/benchmarks/'.$tag.'/julius-caesar.md');

        return self::SUCCESS;
    }

    /** @return array<string, Lesson>|null */
    private function generateBenchmarkLessons(string $dir): ?array
    {
        $teacher = User::firstOrCreate(
            ['email' => self::BENCHMARK_TEACHER_EMAIL],
            [
                'name' => 'Benchmark Teacher',
                'password' => bcrypt(Str::random(32)),
                'role' => 'teacher',
                'tag' => 'test',
            ],
        );

        $this->line("Benchmark teacher: {$teacher->email} (#{$teacher->id})");
        $this->warn('A queue worker must be running (composer dev) or generation will never complete.');

        $lessons = [];
        $manifest = [];

        foreach (self::TOPICS as $spec) {
            $lesson = Lesson::create([
                'teacher_id' => $teacher->id,
                'topic' => $spec['topic'],
                'subject' => 'history',
                'grade_level' => $spec['grade'],
                'tone' => $spec['tone'],
                'historical_figure' => $spec['figure'],
                'include_game' => false,
                'status' => LessonStatus::Draft,
            ]);

            LessonSource::create([
                'lesson_id' => $lesson->id,
                'kind' => 'internet',
                'extracted_text' => '',
            ]);

            $lesson->startGenerationPipeline();

            $lessons[$spec['slug']] = $lesson;
            $manifest[$spec['slug']] = $lesson->id;
            $this->info("Started lesson #{$lesson->id}: {$spec['topic']}");
        }

        Storage::disk('local')->put("{$dir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

        return $lessons;
    }

    /** @return array<string, Lesson>|null */
    private function lessonsFromManifest(string $dir): ?array
    {
        $raw = Storage::disk('local')->get("{$dir}/manifest.json");
        $manifest = json_decode((string) $raw, true);

        if (! is_array($manifest) || $manifest === []) {
            $this->error("No manifest at storage/app/{$dir}/manifest.json — run without --dump-only first.");

            return null;
        }

        $lessons = [];
        foreach ($manifest as $slug => $id) {
            $lesson = Lesson::find($id);
            if (! $lesson) {
                $this->warn("Lesson #{$id} ({$slug}) no longer exists — skipping.");

                continue;
            }
            $lessons[$slug] = $lesson;
        }

        return $lessons;
    }

    /** @param array<string, Lesson> $lessons */
    private function waitForGeneration(array $lessons, int $maxWaitSeconds): void
    {
        $deadline = time() + $maxWaitSeconds;
        $terminal = [LessonStatus::ScenesReady, LessonStatus::Previewable, LessonStatus::Ready, LessonStatus::Failed];

        $this->line("Waiting for generation (max {$maxWaitSeconds}s)...");

        while (time() < $deadline) {
            $pending = collect($lessons)
                ->map(fn (Lesson $lesson) => $lesson->fresh())
                ->reject(fn (Lesson $lesson) => in_array($lesson->status, $terminal, true));

            if ($pending->isEmpty()) {
                $this->info('All benchmark lessons reached a terminal status.');

                return;
            }

            $this->line('  still generating: '.$pending->map(
                fn (Lesson $lesson) => "#{$lesson->id} ({$lesson->status->value})",
            )->implode(', '));

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        $this->warn('Timed out waiting — dumping whatever exists now.');
    }

    private function buildReport(Lesson $lesson): string
    {
        $scenes = $lesson->scenes->where('kind', 'narration')->sortBy('order')->values();
        $questions = $lesson->quizQuestions;

        $rubric = $this->rubric($lesson);

        $lines = [
            "# Benchmark: {$lesson->topic}",
            '',
            "- Lesson: #{$lesson->id} · status `{$lesson->status->value}` · grade {$lesson->grade_level} · tone {$lesson->tone}",
            "- Generated: {$lesson->updated_at}",
            '',
            '## Rubric (lower = more boring)',
            '',
        ];

        foreach ($rubric as $label => $value) {
            $lines[] = "- {$label}: {$value}";
        }

        $lines[] = '';
        $lines[] = '## Learning objectives';
        $lines[] = '';
        $objectives = $lesson->outline['learning_objectives'] ?? [];
        if ($objectives === []) {
            $lines[] = '_(none — outline has no learning_objectives)_';
        } else {
            foreach ($objectives as $objective) {
                $lines[] = '- '.($objective['id'] ?? '?').': '.($objective['text'] ?? '');
            }
        }

        $lines[] = '';
        $lines[] = '## Scenes';

        foreach ($scenes as $scene) {
            $lines[] = '';
            $lines[] = "### Scene {$scene->order}".($scene->year ? " — {$scene->year}" : '').($scene->location ? ", {$scene->location}" : '');
            $lines[] = '';
            $lines[] = trim((string) $scene->script_segment) ?: '_(no script)_';
        }

        $lines[] = '';
        $lines[] = '## Quiz';

        foreach ($questions as $index => $question) {
            $options = is_array($question->options) ? $question->options : [];
            $lines[] = '';
            $lines[] = ($index + 1).'. '.$question->question;
            foreach ($options as $optionIndex => $option) {
                $marker = $optionIndex === $question->correct_index ? '✓' : ' ';
                $lines[] = "   [{$marker}] {$option}";
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @return array<string, int|string> */
    private function rubric(Lesson $lesson): array
    {
        $scenes = $lesson->scenes->where('kind', 'narration');
        $scripts = $scenes->pluck('script_segment')->filter()->map(fn ($script) => trim((string) $script));
        $questions = $lesson->quizQuestions;

        $standingOpeners = $scripts->filter(
            fn (string $script) => preg_match('/^you (are )?(stand|find yourself)/i', $script) === 1,
        )->count();

        $sceneryQuestions = $questions->filter(
            fn ($question) => preg_match(self::SCENERY_QUESTION_PATTERN, (string) $question->question) === 1,
        )->count();

        $stems = $questions->map(fn ($question) => Str::lower(Str::substr((string) $question->question, 0, 40)));
        $duplicateStems = $stems->count() - $stems->unique()->count();

        $wordCounts = $scripts->map(fn (string $script) => str_word_count(strip_tags($script)));

        return [
            'narration scenes' => $scenes->count(),
            'scenes with script' => $scripts->count(),
            'avg words per scene' => $wordCounts->isEmpty() ? 0 : (int) round($wordCounts->avg()),
            '"you are standing" openers' => $standingOpeners,
            'quiz questions' => $questions->count(),
            'scenery/mood quiz questions' => $sceneryQuestions,
            'near-duplicate quiz stems' => $duplicateStems,
            'learning objectives in outline' => count($lesson->outline['learning_objectives'] ?? []),
        ];
    }

    /** @param array<string, Lesson> $lessons */
    private function printRubricSummary(array $lessons): void
    {
        $rows = [];
        foreach ($lessons as $slug => $lesson) {
            $rubric = $this->rubric($lesson->fresh(['scenes', 'quizQuestions']));
            $rows[] = array_merge(['lesson' => $slug], array_map('strval', $rubric));
        }

        if ($rows !== []) {
            $this->table(array_keys($rows[0]), $rows);
        }

        $this->line('Manual rubric (eyeball the dumps):');
        $this->line('  1. Does any scene show a NAMED PERSON doing something (not just scenery)?');
        $this->line('  2. Do scenes connect with but/therefore turns, or is it a list of facts?');
        $this->line('  3. Does every quiz question test something a student should retain?');
    }
}
