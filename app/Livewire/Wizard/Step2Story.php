<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\NarrativeFramework;
use App\Models\Corpus\Figure;
use App\Models\Lesson;
use App\Models\StrategyGame;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Wizard step 2 — Story. The teacher picks a narrative arc (Hero's journey by default), and for
 * Hero's journey a protagonist from the corpus (rulers first, with portraits). The game config
 * lives here too, pre-paired to the arc. The CTA kicks off the generation pipeline and advances
 * to the Generate progress step.
 */
class Step2Story extends Component
{
    public Lesson $lesson;

    public string $narrative_framework = 'heros_journey';

    public ?string $protagonist_qid = null;

    public ?string $protagonist_name = null;

    // ── Game config (relocated from Settings; pre-paired to the arc) ──────────
    public bool $include_game = false;

    public ?string $game_type = null;

    public int $quiz_question_count = 4;

    public ?string $quiz_timing = null;

    public ?string $strategy_game = null;

    public ?int $strategy_game_id = null;

    public ?int $team_count = null;

    public int $game_split_count = 1;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        $this->narrative_framework = ($lesson->narrative_framework instanceof NarrativeFramework)
            ? $lesson->narrative_framework->value
            : ($lesson->narrative_framework ?? NarrativeFramework::default()->value);
        $this->protagonist_qid = $lesson->protagonist_qid;
        $this->protagonist_name = $lesson->protagonist_name;

        $this->include_game = (bool) ($lesson->include_game ?? false);
        $this->game_type = $lesson->game_type;
        $this->quiz_question_count = (int) ($lesson->quiz_question_count ?? 4);
        $this->quiz_timing = $lesson->quiz_timing;
        $this->strategy_game = $lesson->strategy_game ? (string) $lesson->strategy_game : null;
        $this->strategy_game_id = $lesson->strategy_game_id;
        $this->team_count = $lesson->team_count;
        $this->game_split_count = (int) ($lesson->game_split_count ?? 1);

        // First visit with no choice yet → apply the default arc's paired game.
        if (! $lesson->narrative_framework) {
            $this->applyArcDefaults(NarrativeFramework::from($this->narrative_framework));
        }
    }

    /** @return list<NarrativeFramework> */
    #[Computed]
    public function frameworks(): array
    {
        return NarrativeFramework::cases();
    }

    public function framework(): NarrativeFramework
    {
        return NarrativeFramework::tryFrom($this->narrative_framework) ?? NarrativeFramework::default();
    }

    /**
     * Corpus heroes for this lesson's topic — rulers first, then fame. Empty when the topic isn't a
     * catalog polity; the teacher then types a name (free-text fallback).
     */
    #[Computed]
    public function heroes()
    {
        if (! $this->framework()->needsHero() || ! $this->lesson->topic_id) {
            return collect();
        }

        $parentQid = Str::after($this->lesson->topic_id, ':'); // "polity:Q12560" → "Q12560"

        return Figure::resilient(
            fn () => Figure::query()
                ->forTopic($parentQid)
                ->limit(12)
                ->get(['qid', 'name', 'figure_kind', 'image_url', 'sitelinks', 'era_start', 'era_end'])
        );
    }

    #[Computed]
    public function games()
    {
        return StrategyGame::active()->orderBy('title')->get();
    }

    public function selectFramework(string $value): void
    {
        $framework = NarrativeFramework::tryFrom($value) ?? NarrativeFramework::default();
        $this->narrative_framework = $framework->value;

        if (! $framework->needsHero()) {
            $this->protagonist_qid = null;
            $this->protagonist_name = null;
        }

        $this->applyArcDefaults($framework);
    }

    /** Pre-pair the arc's suggested game (teacher can still override below). */
    private function applyArcDefaults(NarrativeFramework $framework): void
    {
        $paired = $framework->defaultGameType();

        if ($paired === null) {
            $this->include_game = false;
            $this->game_type = null;

            return;
        }

        $this->include_game = true;
        $this->game_type = $paired;
        if ($paired === 'quiz' && ! $this->quiz_timing) {
            $this->quiz_timing = 'after';
        }
    }

    public function selectHero(string $qid): void
    {
        $hero = $this->heroes()->firstWhere('qid', $qid);
        if (! $hero) {
            return;
        }

        $this->protagonist_qid = $hero->qid;
        $this->protagonist_name = $hero->name;
    }

    /** Typing a custom name clears the corpus link (free-text fallback). */
    public function updatedProtagonistName(): void
    {
        $this->protagonist_qid = null;
    }

    public function updatedIncludeGame(): void
    {
        if ($this->include_game) {
            $this->game_type = $this->framework()->defaultGameType() ?? 'quiz';
            $this->quiz_timing = 'after';
        } else {
            $this->game_type = null;
            $this->quiz_question_count = 4;
            $this->quiz_timing = null;
            $this->strategy_game = null;
            $this->strategy_game_id = null;
            $this->team_count = null;
            $this->game_split_count = 1;
        }
    }

    public function updatedGameType(): void
    {
        $this->quiz_question_count = 4;
        $this->quiz_timing = 'after';
        $this->strategy_game = null;
        $this->strategy_game_id = null;
    }

    /** Persist the story + game choices, start generation, advance to the Generate step. */
    public function generate(): void
    {
        $this->lesson->fill([
            'narrative_framework' => $this->narrative_framework,
            'protagonist_qid' => $this->protagonist_qid,
            'protagonist_name' => trim((string) $this->protagonist_name) ?: null,
            'include_game' => $this->include_game,
            'game_type' => $this->include_game ? $this->game_type : null,
            'quiz_question_count' => $this->include_game && $this->game_type === 'quiz' ? $this->quiz_question_count : null,
            'quiz_timing' => $this->include_game && $this->game_type === 'quiz' ? $this->quiz_timing : null,
            'strategy_game' => $this->include_game && $this->game_type === 'strategy' ? $this->strategy_game : null,
            'strategy_game_id' => $this->include_game && $this->game_type === 'strategy' ? $this->strategy_game_id : null,
            'team_count' => $this->include_game && $this->game_type === 'strategy' ? $this->team_count : null,
            'game_split_count' => $this->include_game && $this->game_type === 'strategy' ? $this->game_split_count : 1,
        ]);
        $this->lesson->save();

        $this->lesson->refresh()->startGenerationPipeline();
        $this->lesson->update(['wizard_step' => 3]);

        $this->redirect(
            route('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 3]),
            navigate: true,
        );
    }

    public function back(): void
    {
        $this->lesson->update(['wizard_step' => 1]);
        $this->redirect(
            route('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 1]),
            navigate: true,
        );
    }

    public function render()
    {
        return view('livewire.wizard.step2-story');
    }
}
