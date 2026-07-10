<?php

declare(strict_types=1);

namespace App\Livewire\Teacher;

use App\Enums\LessonStatus;
use App\Lessons\LessonPreset;
use App\Lessons\LessonPresets;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Story;
use App\Services\LessonGoalParser;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * K-12 agentic lesson creation: a short, server-driven chat — the teacher types ONE
 * learning goal, everything else is tappable chips backed by LessonPresets (K-13).
 * Not a freeform agent: a four-state machine (goal → proposals → confirm → creating)
 * with exactly one LLM call (LessonGoalParser) that only suggests, never decides.
 */
class LessonChat extends Component
{
    /** Grade chips — canonical Step1Settings "Age N" values, one per band. */
    public const GRADE_CHIP_AGES = [6, 8, 10, 12, 14, 16];

    private const DEFAULT_AGE = 12;

    private const STORY_MATCH_LIMIT = 3;

    private const GOAL_AS_DETAILS_LIMIT = 500;

    public string $state = 'goal';   // goal | proposals | confirm | creating

    public string $goal = '';

    // ── Suggestions from the single LLM call (null = no suggestion, manual chips) ──
    public ?string $suggestedPresetKey = null;

    public ?string $storyQuery = null;

    // ── Teacher selections (chips; editable until Create) ─────────────────────────
    public ?string $presetKey = null;

    public string $gradeLevel = 'Age '.self::DEFAULT_AGE;

    public ?int $storyId = null;

    public bool $freeTopic = false;

    public string $topic = '';

    /** Idempotency guard — the second click of a double-click redirects, never re-creates. */
    public ?int $createdLessonId = null;

    // ── Step 1: goal ───────────────────────────────────────────────────────────────

    public function submitGoal(LessonGoalParser $parser): void
    {
        $this->validate(['goal' => 'required|string|min:5|max:500']);

        if ($this->state !== 'goal') {
            return;
        }

        $parsed = $parser->parse($this->goal, app()->getLocale());

        $this->suggestedPresetKey = $parsed['preset_key'];
        $this->presetKey = $parsed['preset_key'];
        $this->storyQuery = $parsed['story_query'];
        $this->gradeLevel = 'Age '.$this->snapToGradeChip($parsed['age'] ?? self::DEFAULT_AGE);
        // Fallback: without a parsed topic the goal itself is the free-topic label.
        $this->topic = $parsed['topic'] ?? Str::limit(trim($this->goal), 80, '');

        $this->state = 'proposals';
    }

    // ── Step 2: proposals (chips) ──────────────────────────────────────────────────

    /** @return list<array{key: string, label: string, description: string, suggested: bool}> */
    #[Computed]
    public function presetChips(): array
    {
        return collect(LessonPresets::all())->map(fn (LessonPreset $preset): array => [
            'key' => $preset->key,
            'label' => $preset->label,
            'description' => $preset->description,
            'suggested' => $preset->key === $this->suggestedPresetKey,
        ])->values()->all();
    }

    /**
     * Published catalog stories matching the LLM's story_query (or the topic). Simple
     * ILIKE on title/subtitle, top 3 — the "Ground in: <story>" chips.
     *
     * @return list<array{id: int, title: string, subtitle: ?string}>
     */
    #[Computed]
    public function storyMatches(): array
    {
        $query = trim((string) ($this->storyQuery ?? $this->topic));
        if ($query === '') {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';

        return Story::published()
            ->where(fn ($q) => $q->where('title', 'ilike', $like)->orWhere('subtitle', 'ilike', $like))
            ->limit(self::STORY_MATCH_LIMIT)
            ->get(['id', 'title', 'subtitle'])
            ->map(fn (Story $story): array => [
                'id' => $story->id,
                'title' => $story->title,
                'subtitle' => $story->subtitle,
            ])->all();
    }

    public function selectPreset(string $key): void
    {
        if (LessonPresets::find($key) === null || $this->createdLessonId !== null) {
            return;
        }

        $this->presetKey = $key;
        $this->maybeAdvanceToConfirm();
    }

    public function selectGrade(int $age): void
    {
        if (! in_array($age, self::GRADE_CHIP_AGES, true) || $this->createdLessonId !== null) {
            return;
        }

        $this->gradeLevel = 'Age '.$age;
        $this->maybeAdvanceToConfirm();
    }

    public function selectStory(int $id): void
    {
        if ($this->createdLessonId !== null || ! Story::published()->whereKey($id)->exists()) {
            return;
        }

        $this->storyId = $id;
        $this->freeTopic = false;
        $this->maybeAdvanceToConfirm();
    }

    public function selectFreeTopic(): void
    {
        if ($this->createdLessonId !== null) {
            return;
        }

        $this->storyId = null;
        $this->freeTopic = true;
        $this->maybeAdvanceToConfirm();
    }

    private function maybeAdvanceToConfirm(): void
    {
        if ($this->presetKey !== null && ($this->storyId !== null || $this->freeTopic)) {
            $this->state = 'confirm';
        }
    }

    #[Computed]
    public function selectedPreset(): ?LessonPreset
    {
        return $this->presetKey !== null ? LessonPresets::find($this->presetKey) : null;
    }

    #[Computed]
    public function selectedStory(): ?Story
    {
        return $this->storyId !== null ? Story::published()->find($this->storyId) : null;
    }

    // ── Step 4: create ─────────────────────────────────────────────────────────────

    public function create(): void
    {
        // Server-side double-click guard: the first click created the lesson — just go there.
        if ($this->createdLessonId !== null) {
            $this->redirectToWizard($this->createdLessonId);

            return;
        }

        if ($this->state !== 'confirm') {
            return;
        }

        $preset = $this->selectedPreset;
        $story = $this->selectedStory;
        if ($preset === null || ($story === null && ! $this->freeTopic)) {
            return;
        }

        $this->state = 'creating';

        // Story picks pre-seed the protagonist + exact grounding (mirrors Step1Settings::selectStory
        // + persist); the preset supplies framework/game/tone/duration — title stays null on purpose,
        // the pipeline titles the lesson.
        $storyAttributes = $story !== null ? array_filter([
            'story_id' => $story->id,
            'protagonist_qid' => $story->protagonist_qid,
            'protagonist_name' => $story->protagonist_name,
            ...$this->corpusGrounding($story),
        ], fn ($value) => $value !== null) : [];

        $lesson = Lesson::create($preset->apply([
            'teacher_id' => auth()->id(),
            'topic' => $story?->title ?? trim($this->topic),
            'subject' => 'history',
            'grade_level' => $this->gradeLevel,
            'details' => Str::limit(trim($this->goal), self::GOAL_AS_DETAILS_LIMIT, '') ?: null,
            'source_mode' => 'internet',
            'avatar_id' => Avatar::where('is_active', true)->orderBy('sort_order')->value('id'),
            'lesson_code' => strtoupper(Str::random(6)),
            'status' => LessonStatus::Draft,
            'wizard_step' => 3,
            ...$storyAttributes,
        ]));

        // Same as Step1Settings::buildSource (internet mode): the actual fetch is deferred to
        // BuildLessonOutline; the row just tells the job to fetch worldhistory.org → wikipedia.
        LessonSource::create([
            'lesson_id' => $lesson->id,
            'kind' => 'internet',
            'extracted_text' => '',
            'wikipedia_topic' => $lesson->topic,
        ]);

        // Same as Step2Story::generate — sets SourceReady + dispatches BuildLessonOutline,
        // then lands on the wizard's Generate progress view (step 3).
        $lesson->refresh()->startGenerationPipeline();

        $this->createdLessonId = $lesson->id;
        $this->redirectToWizard($lesson->id);
    }

    /**
     * Best-effort corpus enrichment for a story's linked topic (exact Wikipedia URL, region,
     * era) — mirrors Step1Settings::selectStory. The corpus DB may be unreachable; grounding
     * is a bonus, never a blocker.
     *
     * @return array{wikipedia_source: ?string, region: ?string, era: ?string, topic_id: ?string}
     */
    private function corpusGrounding(Story $story): array
    {
        $none = ['wikipedia_source' => null, 'region' => null, 'era' => null, 'topic_id' => null];

        if (! $story->topic_id) {
            return $none;
        }

        try {
            $topic = \App\Models\Corpus\Topic::resilient(
                fn () => \App\Models\Corpus\Topic::find($story->topic_id)
            );
        } catch (\Throwable) {
            return $none;
        }

        if (! $topic) {
            return $none;
        }

        return [
            'wikipedia_source' => $topic->wikipedia_url,
            'region' => $topic->region_label ?: null,
            'era' => $topic->eraLabel() ?: null,
            'topic_id' => $topic->id,
        ];
    }

    private function redirectToWizard(int $lessonId): void
    {
        $this->redirect(
            route('teacher.lessons.wizard', ['lesson' => $lessonId, 'step' => 3]),
            navigate: true,
        );
    }

    private function snapToGradeChip(int $age): int
    {
        return collect(self::GRADE_CHIP_AGES)
            ->sortBy(fn (int $chip): int => abs($chip - $age))
            ->first();
    }

    public function render()
    {
        return view('livewire.teacher.lesson-chat')
            ->layout('components.layouts.app', ['title' => __('Quick lesson chat')]);
    }
}
