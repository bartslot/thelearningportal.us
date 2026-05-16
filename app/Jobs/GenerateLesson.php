<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Services\ImageSearchService;
use App\Services\LlmService;
use App\Services\TtsService;
use App\Services\WikipediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateLesson implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        private readonly int $lessonId
    ) {}

    private function buildFallbackFacts(Lesson $lesson): string
    {
        return <<<FACTS
Teacher-provided lesson context:
- Topic: {$lesson->topic}
- Subject: {$lesson->subject}
- Grade level: {$lesson->grade_level}

Wikipedia lookup did not return a usable article for this lesson.
Write a careful overview using only the context above and avoid inventing specific factual claims.
FACTS;
    }

    /**
     * Pad LLM questions to $count using simple fallbacks derived from the script.
     * Only triggered if the LLM returned fewer than $count valid questions.
     */
    private function padQuizQuestions(array $questions, string $script, int $count): array
    {
        if (count($questions) >= $count) {
            return array_slice($questions, 0, $count);
        }

        $cleanScript = trim(preg_replace('/\s+/', ' ', strip_tags($script)) ?? '');
        $sentences   = array_values(array_filter(array_map(
            'trim',
            preg_split('/(?<=[.!?])\s+/', $cleanScript) ?: []
        )));

        $fallbackQuestions = [
            'What is the main topic discussed in this lesson?',
            'Which of the following best describes the subject of this lesson?',
            'What can students learn from this lesson?',
            'Which statement is supported by the lesson content?',
        ];

        $position = count($questions);

        while (count($questions) < $count) {
            $questionText  = $fallbackQuestions[$position % count($fallbackQuestions)];
            $correctOption = $sentences[$position] ?? $sentences[0] ?? 'A key fact covered in the lesson.';
            $correctOption = mb_substr(trim($correctOption), 0, 120);

            $questions[] = [
                'question'      => $questionText,
                'options'       => [
                    $correctOption,
                    'A claim not supported by the lesson.',
                    'An event unrelated to this topic.',
                    'An incorrect interpretation of the lesson.',
                ],
                'correct_index' => 0,
                'explanation'   => 'This is directly supported by the lesson content.',
            ];

            $position++;
        }

        return $questions;
    }

    public function handle(
        WikipediaService   $wikipedia,
        LlmService         $llm,
        TtsService         $tts,
        ImageSearchService $imageSearch,
    ): void {
        $lesson = Lesson::with('avatar')->findOrFail($this->lessonId);

        Log::info("GenerateLesson: starting for lesson #{$lesson->id} — {$lesson->topic}");

        $lesson->update([
            'status'              => LessonStatus::Generating,
            'generation_attempts' => $lesson->generation_attempts + 1,
        ]);

        try {
            // ── Step 1: Fetch Wikipedia facts ───────────────────────────────
            Log::info("GenerateLesson #{$lesson->id}: fetching Wikipedia facts");

            $facts = $wikipedia->fetchFacts($lesson->topic, $lesson->topic);

            if (! $facts) {
                Log::warning("GenerateLesson #{$lesson->id}: Wikipedia returned no content, using fallback");
                $facts = $this->buildFallbackFacts($lesson);
            }

            $lesson->update(['wikipedia_source' => $facts]);

            // ── Step 2: Generate title, script + quiz in one LLM call ────────
            Log::info("GenerateLesson #{$lesson->id}: generating content via LLM");

            $generated = $llm->generate(
                facts:    $facts,
                topic:    $lesson->topic,
                gradLevel: $lesson->grade_level,
                tone:     $lesson->tone,
                details:  $lesson->details,
            );

            if (! $generated || empty($generated['script'])) {
                throw new \RuntimeException('LLM failed to generate lesson content');
            }

            $title     = $generated['title'] ?? $lesson->topic;
            $script    = $generated['script'];
            $questions = $generated['questions'] ?? [];

            $lesson->update([
                'title'  => $title,
                'script' => $script,
            ]);

            // ── Step 3: Persist quiz questions ───────────────────────────────
            Log::info("GenerateLesson #{$lesson->id}: saving quiz questions (" . count($questions) . " from LLM)");

            $questions = $this->padQuizQuestions($questions, $script, 4);

            foreach ($questions as $index => $q) {
                QuizQuestion::create([
                    'lesson_id'     => $lesson->id,
                    'order'         => $index,
                    'question'      => $q['question'],
                    'options'       => $q['options'],
                    'correct_index' => $q['correct_index'],
                    'explanation'   => $q['explanation'] ?? null,
                    'points'        => 10,
                ]);
            }

            // ── Step 4: Generate TTS audio (using avatar voice settings) ────────
            Log::info("GenerateLesson #{$lesson->id}: generating TTS audio");

            // Resolve avatar: lesson's avatar → first active avatar → env defaults
            $avatar     = $lesson->avatar ?? Avatar::active()->first();
            $voiceId    = $avatar?->voice_id    ?? config('services.openai.tts_voice', 'alloy');
            $voiceSpeed = $avatar?->voice_speed  ?? 1.0;

            if ($avatar) {
                Log::info("GenerateLesson #{$lesson->id}: using avatar '{$avatar->name}' voice '{$voiceId}' at {$voiceSpeed}×");
            }

            $audioPath = $tts->generateAudio(
                text:     $script,
                lessonId: (string) $lesson->id,
                voice:    $voiceId,
                speed:    $voiceSpeed,
            );

            if (! $audioPath) {
                throw new \RuntimeException('TTS failed to generate audio');
            }

            $lesson->update(['audio_path' => $audioPath]);

            // ── Step 5: Fetch background slideshow images (optional — non-blocking) ──
            // Europeana first, Wikimedia Commons as fallback.
            // A lesson is marked Ready regardless of whether images were fetched.
            try {
                Log::info("GenerateLesson #{$lesson->id}: fetching slideshow images for '{$lesson->topic}'");
                $images = $imageSearch->fetchImages($lesson->topic, 14);

                if (! empty($images)) {
                    $lesson->update(['slideshow_images' => $images]);
                    Log::info("GenerateLesson #{$lesson->id}: stored " . count($images) . " slideshow images");
                } else {
                    Log::info("GenerateLesson #{$lesson->id}: no slideshow images found (lesson will use portrait fallback)");
                }
            } catch (\Throwable $e) {
                Log::warning("GenerateLesson #{$lesson->id}: slideshow image fetch failed (non-fatal) — " . $e->getMessage());
            }

            // ── Done ─────────────────────────────────────────────────────────
            $lesson->update(['status' => LessonStatus::Ready]);

            Log::info("GenerateLesson #{$lesson->id}: completed successfully — title: \"{$title}\"");

        } catch (\Exception $e) {
            Log::error("GenerateLesson #{$lesson->id} failed: " . $e->getMessage());

            $lesson->update([
                'status'        => LessonStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
