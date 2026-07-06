<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Corpus\Article;
use App\Models\Story;
use App\Services\OpenAiLlmService;
use Illuminate\Console\Command;

/**
 * LLM-assisted drafting of a story's pedagogy: learning objectives, key facts,
 * misconceptions, and a quiz blueprint — proposed from the curated source excerpts,
 * then human-reviewed via the admin story list (draft → reviewed → published).
 *
 *   php artisan story:draft horatius-at-the-bridge
 */
class DraftStory extends Command
{
    protected $signature = 'story:draft
        {story        : Story id or slug}
        {--fresh      : Overwrite existing pedagogy fields}';

    protected $description = 'Draft learning objectives, key facts, and misconceptions for a catalog story via LLM.';

    public function handle(OpenAiLlmService $llm): int
    {
        $ref = (string) $this->argument('story');
        $story = ctype_digit($ref) ? Story::find((int) $ref) : Story::where('slug', $ref)->first();

        if (! $story) {
            $this->error("Story '{$ref}' not found.");

            return self::FAILURE;
        }

        $story->load('sources');
        $sourceText = $story->combinedSourceText();
        if ($sourceText === '') {
            $this->error('Story has no source excerpts — import one first (story:import-gutenberg).');

            return self::FAILURE;
        }

        if (! empty($story->learning_objectives) && ! $this->option('fresh')) {
            $this->warn('Story already has objectives — use --fresh to overwrite.');

            return self::FAILURE;
        }

        $result = $llm->json(system: self::systemPrompt(), user: self::userPrompt($story, $sourceText));

        $story->update([
            'subtitle' => $story->subtitle ?? ($result['subtitle'] ?? null),
            'protagonist_name' => $story->protagonist_name ?? ($result['protagonist_name'] ?? null),
            'narrative_framework' => $result['narrative_framework'] ?? $story->narrative_framework,
            'era_start' => $story->era_start ?? ($result['era_start'] ?? null),
            'era_end' => $story->era_end ?? ($result['era_end'] ?? null),
            'region' => $story->region ?? ($result['region'] ?? null),
            'grade_band' => $story->grade_band ?? ($result['grade_band'] ?? null),
            'learning_objectives' => $result['learning_objectives'] ?? [],
            'key_facts' => $result['key_facts'] ?? [],
            'misconceptions' => $result['misconceptions'] ?? [],
            'quiz_blueprint' => $result['quiz_blueprint'] ?? [],
        ]);

        $this->enrichKeyFactsFromCorpus($story);

        $this->info("Drafted pedagogy for '{$story->slug}':");
        foreach ($story->fresh()->learning_objectives ?? [] as $objective) {
            $this->line('  - '.($objective['id'] ?? '?').': '.($objective['text'] ?? ''));
        }
        $this->line('Review + publish it in /admin/stories.');

        return self::SUCCESS;
    }

    private static function systemPrompt(): string
    {
        return <<<'SYS'
You are a K-12 history curriculum designer. From the narrative source text, draft the
pedagogical scaffolding for this story. Objectives must be specific and testable from the
story itself ("The student can explain why…", never "learn about…"). Misconceptions must be
errors real students actually make. Only use facts present in the source.

Return ONLY JSON:
{
  "subtitle": string (one enticing line, max 12 words),
  "protagonist_name": string | null,
  "narrative_framework": "heros_journey" | "in_medias_res" | "rise_and_fall" | "mystery" | "branching",
  "era_start": integer | null (year, negative = BC),
  "era_end": integer | null,
  "region": string | null,
  "grade_band": "K-2" | "3-5" | "6-8" | "9-12",
  "learning_objectives": [{ "id": "LO1", "text": string, "bloom": "remember"|"understand"|"apply"|"analyze" }],
  "key_facts": [{ "fact": string, "source_ref": string (quote fragment from the source) }],
  "misconceptions": [{ "misconception": string, "correction": string }],
  "quiz_blueprint": [{ "objective_id": string, "style": "cause_effect"|"who_why"|"consequence"|"concept", "count": integer }]
}
3-5 learning_objectives, 5-8 key_facts, 2-4 misconceptions.
SYS;
    }

    private static function userPrompt(Story $story, string $sourceText): string
    {
        return <<<USR
Story title: {$story->title}
Locale: {$story->locale}

Source text:
"""
{$sourceText}
"""

Draft the JSON now.
USR;
    }

    /** Attach corroborating corpus articles as extra source references (best effort). */
    private function enrichKeyFactsFromCorpus(Story $story): void
    {
        if ($story->era_start === null || $story->region === null) {
            return;
        }

        try {
            $articles = Article::query()
                ->where('era_start', '<=', $story->era_end ?? $story->era_start)
                ->where('era_end', '>=', $story->era_start)
                ->limit(3)
                ->get(['title']);

            if ($articles->isNotEmpty()) {
                $this->line('Corpus corroboration: '.$articles->pluck('title')->implode(' · '));
            }
        } catch (\Throwable) {
            // Corpus unreachable — enrichment is optional.
        }
    }
}
