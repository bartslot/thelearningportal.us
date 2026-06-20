<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NarrativeFramework;

/**
 * The dramatic spine for a narrative framework: the ordered beats the lesson outline must map its
 * scenes onto, which beat the paired game lands at, the And-But-Therefore causality rule, and a
 * protagonist clause. Consumed by LessonOutlinePrompt to shape generation (wiring is a later task).
 *
 * Beats are dramatic functions, not a fixed scene count — the outline distributes its target scenes
 * across them, expanding the middle beats for longer lessons.
 */
final class StorySpine
{
    private const ABT_RULE = 'Connect each beat to the previous one with "but" or "therefore" — never "and then".';

    /**
     * @param  list<string>  $beatTemplate
     */
    private function __construct(
        public readonly array $beatTemplate,
        public readonly string $gamePlacementBeat,
        public readonly string $abtRule = self::ABT_RULE,
    ) {}

    public static function for(NarrativeFramework $framework): self
    {
        return match ($framework) {
            NarrativeFramework::HerosJourney => new self(['Before', 'Call', 'Trials', 'Crisis', 'Return'], 'Crisis'),
            NarrativeFramework::InMediasRes  => new self(['Cold open', 'Rewind', 'Build', 'Catch-up', 'Aftermath'], 'Catch-up'),
            NarrativeFramework::RiseAndFall  => new self(['Origin', 'Ascent', 'Peak', 'Cracks', 'Collapse'], 'Peak'),
            NarrativeFramework::Mystery      => new self(['Puzzle', 'Clues', 'Twist', 'Reveal', 'Meaning'], 'Reveal'),
            NarrativeFramework::Branching    => new self(['Setup', 'Approach', 'Choice', 'Rejoin', 'End'], 'Choice'),
        };
    }

    /** A one-line instruction anchoring the lesson on the chosen protagonist, or '' when none. */
    public function protagonistClause(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        return "Tell the story through {$name} as the central figure the student follows.";
    }

    /** The beats as a readable arrow chain for the outline prompt. */
    public function beatsLine(): string
    {
        return implode(' → ', $this->beatTemplate);
    }
}
