<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\LessonGoalParser;
use App\Services\OpenAiLlmService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonGoalParserTest extends TestCase
{
    #[DataProvider('dutchGradeGoals')]
    public function test_dutch_grade_phrase_maps_to_start_of_year_age(string $goal, ?int $expected): void
    {
        $this->assertSame($expected, LessonGoalParser::dutchGradeAge($goal));
    }

    /** @return array<string, array{0: string, 1: ?int}> */
    public static function dutchGradeGoals(): array
    {
        return [
            // Basisschool (primary): groep N → age (N + 3).
            'groep 5' => ['Groep 5 leert over de Romeinen', 8],
            'groep 7 (VOC repro)' => ['Groep 7 begrijpt waarom de VOC werd opgericht', 10],
            'groep 1 lower bound' => ['groep 1', 4],
            'groep 8 upper bound' => ['groep 8', 11],
            'groep without space' => ['groep7 snapt de Gouden Eeuw', 10],
            // Middelbare school (secondary): klas N → age (N + 11).
            'klas 3 havo' => ['klas 3 havo bespreekt de Franse Revolutie', 14],
            'klas 1 brugklas' => ['klas 1', 12],
            'klas 6 vwo' => ['klas 6 vwo', 17],
            // English / plain-age goals defer to the LLM — no deterministic override.
            'english grade 4' => ['grade 4 learns about Ancient Egypt', null],
            'plain age' => ['for 10 year olds', null],
            // Out-of-range grades are ignored (no groep 9, no klas 7-8).
            'groep 9 out of range' => ['groep 9', null],
            'klas 8 out of range' => ['klas 8', null],
        ];
    }

    public function test_dutch_grade_override_beats_the_llm_age_guess(): void
    {
        // The LLM mis-reads "groep 7" as US grade 7 and returns age 13; the override must win.
        $parser = $this->parserReturning(['age' => 13, 'language' => 'nl']);

        $result = $parser->parse('Groep 7 begrijpt waarom de VOC werd opgericht', 'nl');

        $this->assertSame(10, $result['age']);
    }

    public function test_non_dutch_goal_keeps_the_llm_age(): void
    {
        // No groep/klas phrase → no override → the LLM's (valid) age is preserved.
        $parser = $this->parserReturning(['age' => 9, 'language' => 'en']);

        $result = $parser->parse('grade 4 learns about Ancient Egypt', 'en');

        $this->assertSame(9, $result['age']);
    }

    /** Build a parser whose LLM call returns the given decoded JSON payload. */
    private function parserReturning(array $payload): LessonGoalParser
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($payload)]]],
            ]),
        ]);

        return new LessonGoalParser(
            new OpenAiLlmService(apiKey: 'test-key', model: 'test-model', baseUrl: 'https://llm.test/v1'),
        );
    }
}
