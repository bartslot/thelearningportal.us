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
 * button options — this class must never throw.
 */
class LessonGoalParser
{
    public const AGE_MIN = 4;

    public const AGE_MAX = 18;

    private const MAX_TEXT_LENGTH = 120;

    /** Dutch primary "groep N": a pupil is (N + 3) years old at the start of the school year. */
    private const GROEP_AGE_OFFSET = 3;

    /** Dutch secondary "klas N": a pupil is (N + 11) years old at the start of the school year. */
    private const KLAS_AGE_OFFSET = 11;

    public function __construct(private readonly OpenAiLlmService $llm) {}

    /**
     * @return array{summary: ?string, topic: ?string, age: ?int, preset_key: ?string, story_query: ?string, language: ?string}
     */
    public function parse(string $goal, string $locale): array
    {
        try {
            $raw = $this->llm->json($this->systemPrompt(), $this->userPrompt($goal, $locale));
        } catch (Throwable $e) {
            Log::warning('[LessonGoalParser] LLM call failed — falling back to button options', [
                'error' => $e->getMessage(),
            ]);

            // Keep whatever the goal states outright. A grade or age is plain text, not a judgement
            // call, so losing it because the LLM is unavailable made the assistant ignore a teacher
            // who had already said "group 7" and pitch the lesson at 12-14 year olds.
            return [...self::empty(), 'age' => self::dutchGradeAge($goal)];
        }

        return [
            'summary' => $this->cleanText($raw['summary'] ?? null),
            'topic' => $this->cleanText($raw['topic'] ?? null),
            'age' => self::dutchGradeAge($goal) ?? $this->cleanAge($raw['age'] ?? null),
            'preset_key' => $this->cleanPresetKey($raw['preset_key'] ?? null),
            'story_query' => $this->cleanText($raw['story_query'] ?? null),
            'language' => in_array($raw['language'] ?? null, ['nl', 'en'], true) ? $raw['language'] : null,
        ];
    }

    /** @return array{summary: null, topic: null, age: null, preset_key: null, story_query: null, language: null} */
    public static function empty(): array
    {
        return [
            'summary' => null,
            'topic' => null,
            'age' => null,
            'preset_key' => null,
            'story_query' => null,
            'language' => null,
        ];
    }

    /**
     * Deterministic age from a Dutch school-grade phrase, overriding the LLM's guess.
     *
     * The LLM systematically reads "groep 7"/"klas 3" as US grades and returns ~age 12-14,
     * so when a goal names a Dutch grade we map it ourselves. Ages are the start-of-year age:
     *   - Basisschool (primary) groep 1-8 → ages 4-11, i.e. (groep + 3): groep 1 ≈ 4, groep 7 ≈ 10.
     *   - Middelbare school (secondary) klas 1-6 → ages 12-17, i.e. (klas + 11): klas 1 ≈ 12.
     * Returns null for anything else (English grades, plain ages) so the LLM's value stands.
     */
    public static function dutchGradeAge(string $goal): ?int
    {
        // "groep 7", and its English spelling "group 7" — teachers using the English UI of a Dutch
        // product write it both ways, and only matching the Dutch spelling silently fell back to
        // the default age (a group-7 lesson was pitched at 12-14 year olds).
        if (preg_match('/\bgro[eu]?p\s*([1-8])\b/iu', $goal, $m) === 1) {
            return (int) $m[1] + self::GROEP_AGE_OFFSET;
        }

        if (preg_match('/\bklas\s*([1-6])\b/iu', $goal, $m) === 1) {
            return (int) $m[1] + self::KLAS_AGE_OFFSET;
        }

        // An age stated outright: "age 11", "ages 10-11", "11 years old", "11-year-olds".
        // (US "grade N" / UK "year N" are deliberately NOT guessed — they disagree by a year, and a
        // wrong reading is worse than letting the teacher pick.)
        if (preg_match('/\bages?\s*(\d{1,2})\b/iu', $goal, $m) === 1) {
            return self::inAgeRange((int) $m[1]);
        }

        if (preg_match('/\b(\d{1,2})[\s-]*year[\s-]*olds?\b/iu', $goal, $m) === 1) {
            return self::inAgeRange((int) $m[1]);
        }

        return null;
    }

    /** Only trust a stated age inside the range the product actually serves. */
    private static function inAgeRange(int $age): ?int
    {
        return ($age >= 4 && $age <= 18) ? $age : null;
    }

    private function systemPrompt(): string
    {
        $types = collect(LessonPresets::all())
            ->map(fn (LessonPreset $p): string => "- {$p->key}: {$p->description}")
            ->implode("\n");

        return <<<PROMPT
        You convert a teacher's one-sentence learning goal into lesson setup suggestions.
        Reply with ONLY a JSON object, no prose:
        {"summary": string|null, "topic": string|null, "age": number|null, "preset_key": string|null, "story_query": string|null, "language": "nl"|"en"|null}

        Rules:
        - summary: restate the full learning goal as one natural phrase of at most 18 words, in the teacher's language. Preserve every requested angle. Do not praise or add facts.
        - topic: the historical subject in 2-6 words, in the teacher's own language. Normalize informal names and numeric aliases to the canonical historical subject (for example, "80 years war" means "Eighty Years' War").
        - age: the student age (4-18) only if the goal implies one (grade, group, age), else null.
        - preset_key: the best-fitting lesson type key from this list, else null:
        {$types}
        - Prefer deep_dive when the goal combines a major historical transformation, a person's rise or fall, and long-term effects such as legal reform.
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
