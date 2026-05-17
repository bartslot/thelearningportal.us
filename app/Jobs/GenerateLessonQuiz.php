<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Services\OpenAiLlmService;
use App\Services\QuizPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLessonQuiz implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 90;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('scenes')->findOrFail($this->lessonId);

        $result = $llm->json(
            system: QuizPrompt::system(),
            user:   QuizPrompt::user($lesson),
        );

        foreach ($result['questions'] ?? [] as $q) {
            QuizQuestion::create([
                'lesson_id'     => $lesson->id,
                'order'         => (int) ($q['order'] ?? 0),
                'question'      => (string) ($q['question'] ?? ''),
                'options'       => $q['options'] ?? [],
                'correct_index' => (int) ($q['correct_index'] ?? 0),
            ]);
        }

        $lesson->update(['status' => LessonStatus::ScenesReady]);
    }
}
