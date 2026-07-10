<?php

declare(strict_types=1);

namespace App\Services;

use App\Lessons\LessonPreset;
use App\Lessons\LessonPresets;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * K-12 creation chat: turns a teacher's one-sentence learning goal into lesson-setup
 * suggestions via a single LLM call. Every field is validated/whitelisted server-side;
 * any LLM failure or nonsense degrades to nulls so the chat falls through to manual
 * chips — this class must never throw.
 */
class LessonGoalParser
{
    public const AGE_MIN = 4;

    public const AGE_MAX = 18;

    private const MAX_TEXT_LENGTH = 120;

    public function __construct(private readonly OpenAiLlmService $llm) {}

    /**
     * @return array{topic: ?string, age: ?int, preset_key: ?string, story_query: ?string, language: ?string}
     */
    public function parse(string $goal, string $locale): array
    {
        try {
            $raw = $this->llm->json($this->systemPrompt(), $this->userPrompt($goal, $locale));
        } catch (Throwable $e) {
            Log::warning('[LessonGoalParser] LLM call failed — falling back to manual chips', [
                'error' => $e->getMessage(),
            ]);

            return self::empty();
        }

        return [
            'topic' => $this->cleanText($raw['topic'] ?? null),
            'age' => $this->cleanAge($raw['age'] ?? null),
            'preset_key' => $this->cleanPresetKey($raw['preset_key'] ?? null),
            'story_query' => $this->cleanText($raw['story_query'] ?? null),
            'language' => in_array($raw['language'] ?? null, ['nl', 'en'], true) ? $raw['language'] : null,
        ];
    }

    /** @return array{topic: null, age: null, preset_key: null, story_query: null, language: null} */
    public static function empty(): array
    {
        return ['topic' => null, 'age' => null, 'preset_key' => null, 'story_query' => null, 'language' => null];
    }

    private function systemPrompt(): string
    {
        $types = collect(LessonPresets::all())
            ->map(fn (LessonPreset $p): string => "- {$p->key}: {$p->description}")
            ->implode("\n");

        return <<<PROMPT
        You convert a teacher's one-sentence learning goal into lesson setup suggestions.
        Reply with ONLY a JSON object, no prose:
        {"topic": string|null, "age": number|null, "preset_key": string|null, "story_query": string|null, "language": "nl"|"en"|null}

        Rules:
        - topic: the historical subject in 2-6 words, in the teacher's own language.
        - age: the student age (4-18) only if the goal implies one (grade, group, age), else null.
        - preset_key: the best-fitting lesson type key from this list, else null:
        {$types}
        - story_query: 1-3 keywords to search a curated story catalog, else null.
        - language: the language the goal is written in.
        Never invent facts. When uncertain about any field, use null.
        PROMPT;
    }

    private function userPrompt(string $goal, string $locale): string
    {
        return "Interface locale: {$locale}\nLearning goal: ".trim($goal);
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            return null;
        }

        return $value;
    }

    private function cleanAge(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $age = (int) $value;

        return ($age >= self::AGE_MIN && $age <= self::AGE_MAX) ? $age : null;
    }

    private function cleanPresetKey(mixed $value): ?string
    {
        return is_string($value) && LessonPresets::find($value) !== null ? $value : null;
    }
}
