<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The dramatic spine a teacher chooses on the Story step. Each case drives the lesson outline's
 * beat structure (see App\Services\StorySpine) and the card shown in the picker. Hero's journey is
 * the default and the only arc that asks for a protagonist.
 */
enum NarrativeFramework: string
{
    case HerosJourney = 'heros_journey';
    case InMediasRes  = 'in_medias_res';
    case RiseAndFall  = 'rise_and_fall';
    case Mystery      = 'mystery';
    case Branching    = 'branching';

    public static function default(): self
    {
        return self::HerosJourney;
    }

    public function label(): string
    {
        return match ($this) {
            self::HerosJourney => "Hero's journey",
            self::InMediasRes  => 'In medias res',
            self::RiseAndFall  => 'Rise & fall',
            self::Mystery      => 'Mystery',
            self::Branching    => 'Branching story',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HerosJourney => 'One figure faces trials and is changed by them.',
            self::InMediasRes  => 'Open mid-action, then reveal how we got here.',
            self::RiseAndFall  => 'An empire or idea climbs, then unravels.',
            self::Mystery      => 'A puzzle pulls the story toward its answer.',
            self::Branching    => 'Kids choose which path the story follows.',
        };
    }

    /** Tabler outline icon name for the picker card. */
    public function icon(): string
    {
        return match ($this) {
            self::HerosJourney => 'ti-sword',
            self::InMediasRes  => 'ti-bolt',
            self::RiseAndFall  => 'ti-trending-up',
            self::Mystery      => 'ti-search',
            self::Branching    => 'ti-git-branch',
        };
    }

    /** Only Hero's journey asks the teacher to pick a protagonist from the corpus. */
    public function needsHero(): bool
    {
        return $this === self::HerosJourney;
    }

    /**
     * The game pre-selected when this arc is chosen (teacher can override). Branching needs no
     * game — the choices are the interactivity.
     */
    public function defaultGameType(): ?string
    {
        return match ($this) {
            self::HerosJourney, self::InMediasRes, self::Mystery => 'quiz',
            self::RiseAndFall => 'strategy',
            self::Branching   => null,
        };
    }
}
