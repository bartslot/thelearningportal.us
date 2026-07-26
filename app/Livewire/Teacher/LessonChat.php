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
use App\Services\CanonThemeCatalog;
use App\Services\LessonGoalContextResolver;
use App\Services\LessonGoalParser;
use App\Services\Support\HistoryTaxonomy;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * K-12 agentic lesson creation: a short, server-driven dialogue. The teacher types
 * a learning goal, then chooses focus, Canon theme (Dutch teachers), era, lesson type,
 * age, and grounding with buttons.
 * LessonGoalParser makes one suggestion-only LLM call; every decision stays with the teacher.
 */
class LessonChat extends Component
{
    /** Canonical Step1Settings "Age N" values, presented here as two-year age bands. */
    public const GRADE_CHIP_AGES = [6, 8, 10, 12, 14, 16];

    private const DEFAULT_AGE = 12;

    private const STORY_MATCH_LIMIT = 3;

    private const GOAL_AS_DETAILS_LIMIT = 500;

    public string $state = 'goal';

    public string $goal = '';

    public string $goalSummary = '';

    public ?string $subjectDescription = null;

    public ?string $region = null;

    public ?string $regionLabel = null;

    public ?string $detectedEra = null;

    public ?int $periodStart = null;

    public ?int $periodEnd = null;

    public ?string $era = null;

    public ?string $suggestedPresetKey = null;

    public ?string $storyQuery = null;

    public ?string $presetKey = null;

    public string $gradeLevel = 'Age '.self::DEFAULT_AGE;

    public ?int $storyId = null;

    public bool $freeTopic = false;

    public string $topic = '';

    public array $focusTags = [];

    public ?string $focusChoice = null;

    public ?string $eraChoice = null;

    public ?string $presetAnswer = null;

    public ?string $presetChoice = null;

    public ?string $ageAnswer = null;

    public ?string $ageChoice = null;

    public ?string $groundingChoice = null;

    public ?string $suggestedCanonTheme = null;

    public ?string $canonTheme = null;

    public ?string $canonIntent = null;

    public ?string $canonThemeChoice = null;

    /** Idempotency guard — the second click of a double-click redirects, never re-creates. */
    public ?int $createdLessonId = null;

    public function toggleFocusTag(string $slug): void
    {
        if ($this->state !== 'focus' || $this->createdLessonId !== null) {
            return;
        }

        $this->focusTags = \App\Lessons\FocusTags::toggle($this->focusTags, $slug);
    }

    public function submitGoal(
        LessonGoalParser $parser,
        LessonGoalContextResolver $contextResolver,
        CanonThemeCatalog $canon,
    ): void {
        $goalText = trim($this->goal);
        if ($goalText === '') {
            $this->addError('goal', __('validation.required', ['attribute' => __('goal')]));

            return;
        }
        if (strlen($goalText) > 500) {
            $this->addError('goal', __('validation.max.string', ['attribute' => __('goal'), 'max' => 500]));

            return;
        }
        if ($goalText !== '' && strlen($goalText) < 5) {
            $this->addError('goal', __('validation.min.string', ['attribute' => __('goal'), 'min' => 5]));

            return;
        }

        if ($this->state !== 'goal') {
            return;
        }

        $parsed = $parser->parse($goalText, app()->getLocale());
        $context = $contextResolver->resolve($goalText, $parsed['topic']);

        $this->suggestedPresetKey = $parsed['preset_key'];
        $this->presetKey = null;
        $this->storyQuery = $parsed['story_query'];
        $this->gradeLevel = 'Age '.$this->snapToGradeChip($parsed['age'] ?? self::DEFAULT_AGE);
        $this->topic = $context['canonical_subject'] ?? $parsed['topic'] ?? $this->subjectFromGoal($goalText);
        $this->goalSummary = $parsed['summary'] ?? $this->topic;
        $this->subjectDescription = $context['description'];
        $this->region = $context['region'];
        $this->regionLabel = $context['region_label'];
        $this->detectedEra = $context['era'];
        $this->periodStart = $context['period_start'];
        $this->periodEnd = $context['period_end'];
        $this->era = $this->detectedEra;
        $this->suggestedCanonTheme = $this->usesCanon
            ? $canon->suggest($goalText, $this->topic, $this->goalSummary)
            : null;

        $this->state = 'focus';
    }

    #[Computed]
    public function usesCanon(): bool
    {
        return app(CanonThemeCatalog::class)->isDutchTeacher(auth()->user());
    }

    /**
     * @return list<array{
     *     slug:string,
     *     number:int,
     *     label:string,
     *     subtitle:string,
     *     lesson_count:int,
     *     taught_count:int,
     *     status:string,
     *     status_label:string,
     *     suggested:bool
     * }>
     */
    #[Computed]
    public function canonThemeOptions(): array
    {
        $teacher = auth()->user();
        if (! $this->usesCanon || $teacher === null) {
            return [];
        }

        return collect(app(CanonThemeCatalog::class)->coverageFor($teacher))
            ->map(function (array $theme, string $slug): array {
                $statusLabel = match ($theme['status']) {
                    'taught' => trans_choice(
                        'Taught · :count lesson|Taught · :count lessons',
                        $theme['taught_count'],
                        ['count' => $theme['taught_count']],
                    ),
                    'prepared' => __('Prepared · not taught yet'),
                    default => __('Not taught yet'),
                };

                return [
                    'slug' => $slug,
                    ...$theme,
                    'status_label' => $statusLabel,
                    'suggested' => $slug === $this->suggestedCanonTheme,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array{key: string, label: string, pitch: string}> */
    #[Computed]
    public function presetOptions(): array
    {
        return collect(LessonPresets::all())
            ->values()
            ->map(fn (LessonPreset $preset): array => [
                'key' => $preset->key,
                'label' => __($preset->label),
                'pitch' => $this->presetPitch($preset->key),
            ])
            ->all();
    }

    /** @return list<array{age: int, label: string}> */
    #[Computed]
    public function ageOptions(): array
    {
        return array_map(
            fn (int $age): array => [
                'age' => $age,
                'label' => $this->ageBand($age),
            ],
            self::GRADE_CHIP_AGES,
        );
    }

    /** @return list<array{index: int, value: string, label: string, suggested: bool}> */
    #[Computed]
    public function eraOptions(): array
    {
        $eras = $this->region !== null
            ? HistoryTaxonomy::erasFor($this->region)
            : [];

        if ($this->detectedEra !== null && ! in_array($this->detectedEra, $eras, true)) {
            array_unshift($eras, $this->detectedEra);
        }

        if ($eras === []) {
            $eras = [
                'Ancient world (before 500)',
                'Middle Ages (500–1500)',
                'Early modern period (1500–1800)',
                'Modern era (1800–1945)',
                'Contemporary era (1945–present)',
            ];
        }

        if ($this->detectedEra !== null) {
            $eras = array_values(array_unique([
                $this->detectedEra,
                ...array_filter($eras, fn (string $era): bool => $era !== $this->detectedEra),
            ]));
        }

        return array_map(
            fn (string $era, int $index): array => [
                'index' => $index,
                'value' => $era,
                'label' => $this->displayEraLabel($era),
                'suggested' => $era === $this->detectedEra,
            ],
            $eras,
            array_keys($eras),
        );
    }

    /**
     * Published catalog stories matching the LLM's story_query (or the topic), top 3 —
     * the "Ground in: <story>" options. Matches PER WORD (≥4 chars), not the whole phrase:
     * a goal like "Romeinse limes en soldaten" must still find the story titled
     * "De Romeinse Limes" (whole-phrase ILIKE silently missed it — caught in smoke test).
     * Includes selected focus labels so those themes can also surface relevant stories.
     *
     * @return list<array{id: int, title: string, subtitle: ?string}>
     */
    #[Computed]
    public function storyMatches(): array
    {
        $query = trim((string) ($this->storyQuery ?? $this->topic));
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $query) ?: [],
            fn (string $w) => mb_strlen($w) >= 4,
        ));

        // Include tag labels in word list for matching
        if (count($this->focusTags) > 0) {
            $tagLabels = preg_split('/[^\p{L}\p{N}]+/u', \App\Lessons\FocusTags::labels($this->focusTags)) ?: [];
            $tagWords = array_filter(
                $tagLabels,
                fn (string $w) => mb_strlen($w) >= 4,
            );
            $words = array_unique(array_merge($words, $tagWords));
        }

        if ($words === []) {
            return [];
        }

        return Story::published()
            ->where(function ($q) use ($words): void {
                foreach ($words as $word) {
                    $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%';
                    $q->orWhere('title', 'ilike', $like)->orWhere('subtitle', 'ilike', $like);
                }
            })
            ->limit(self::STORY_MATCH_LIMIT)
            ->get(['id', 'title', 'subtitle'])
            ->map(fn (Story $story): array => [
                'id' => $story->id,
                'title' => $story->title,
                'subtitle' => $story->subtitle,
            ])->all();
    }

    /** @return list<array{type: string, id: ?int, label: string}> */
    #[Computed]
    public function groundingOptions(): array
    {
        $options = collect($this->storyMatches)
            ->values()
            ->map(fn (array $story): array => [
                'type' => 'story',
                'id' => $story['id'],
                'label' => $story['title'],
            ])
            ->all();

        $options[] = [
            'type' => 'free_topic',
            'id' => null,
            'label' => $this->topic,
        ];

        return $options;
    }

    public function continueAfterFocus(): void
    {
        if ($this->state !== 'focus' || $this->createdLessonId !== null) {
            return;
        }

        $this->focusChoice = $this->focusTags === []
            ? __('No optional focus')
            : \App\Lessons\FocusTags::labels($this->focusTags);

        if ($this->usesCanon) {
            $this->suggestedCanonTheme = app(CanonThemeCatalog::class)->suggest(
                $this->goal,
                $this->topic,
                $this->goalSummary,
                \App\Lessons\FocusTags::labels($this->focusTags),
            );
            $this->state = 'canon_theme';

            return;
        }

        $this->advanceToEra();
    }

    public function selectCanonTheme(string $slug, CanonThemeCatalog $canon): void
    {
        if (
            $this->state !== 'canon_theme'
            || $this->createdLessonId !== null
            || ! $canon->isValidTheme($slug)
        ) {
            return;
        }

        $option = collect($this->canonThemeOptions)->firstWhere('slug', $slug);
        $this->canonTheme = $slug;
        $this->canonIntent = ($option['taught_count'] ?? 0) > 0 ? 'strengthen' : 'introduce';

        if ($slug === CanonThemeCatalog::OTHER) {
            $this->canonThemeChoice = __('Other · no reliable Canon match');
        } else {
            $this->canonThemeChoice = __('Hoofdlijn :number · :theme — :intent', [
                'number' => $option['number'],
                'theme' => $option['label'],
                'intent' => $this->canonIntent === 'strengthen'
                    ? __('strengthen')
                    : __('introduce'),
            ]);
        }

        $this->advanceToEra();
    }

    public function selectEra(int $index): void
    {
        if ($this->state !== 'era' || $this->createdLessonId !== null) {
            return;
        }

        $option = $this->eraOptions[$index] ?? null;
        if ($option === null) {
            return;
        }

        $this->era = $option['value'];
        $this->eraChoice = $option['label'];
        $this->state = $this->suggestedPresetKey !== null
            ? 'preset_confirmation'
            : 'preset_options';
    }

    public function continueWithSuggestedPreset(): void
    {
        if (
            $this->state !== 'preset_confirmation'
            || $this->suggestedPresetKey === null
            || $this->createdLessonId !== null
        ) {
            return;
        }

        $this->presetAnswer = 'continue';
        $this->presetKey = $this->suggestedPresetKey;
        $this->state = 'age_confirmation';
    }

    public function showPresetOptions(): void
    {
        if ($this->state !== 'preset_confirmation' || $this->createdLessonId !== null) {
            return;
        }

        $this->presetAnswer = 'change';
        $this->presetKey = null;
        $this->state = 'preset_options';
    }

    public function continueWithSuggestedAge(): void
    {
        if ($this->state !== 'age_confirmation' || $this->createdLessonId !== null) {
            return;
        }

        $this->ageAnswer = 'continue';
        $this->advanceAfterAge();
    }

    public function showAgeOptions(): void
    {
        if ($this->state !== 'age_confirmation' || $this->createdLessonId !== null) {
            return;
        }

        $this->ageAnswer = 'change';
        $this->state = 'age_options';
    }

    private function advanceAfterAge(): void
    {
        if ($this->storyMatches === []) {
            $this->storyId = null;
            $this->freeTopic = true;
            $this->state = 'confirm';

            return;
        }

        $this->state = 'grounding_options';
    }

    public function selectPreset(string $key): void
    {
        $preset = LessonPresets::find($key);
        if ($preset === null || $this->state !== 'preset_options' || $this->createdLessonId !== null) {
            return;
        }

        $this->presetKey = $key;
        $this->presetChoice = __($preset->label);
        $this->state = 'age_confirmation';
    }

    public function selectGrade(int $age): void
    {
        if (
            ! in_array($age, self::GRADE_CHIP_AGES, true)
            || $this->state !== 'age_options'
            || $this->createdLessonId !== null
        ) {
            return;
        }

        $this->gradeLevel = 'Age '.$age;
        $this->ageChoice = $this->ageBand($age);
        $this->advanceAfterAge();
    }

    public function selectStory(int $id): void
    {
        $story = Story::published()->find($id);
        if ($this->state !== 'grounding_options' || $this->createdLessonId !== null || $story === null) {
            return;
        }

        $this->storyId = $id;
        $this->freeTopic = false;
        $this->groundingChoice = $story->title;
        $this->state = 'confirm';
    }

    public function selectFreeTopic(): void
    {
        if ($this->state !== 'grounding_options' || $this->createdLessonId !== null) {
            return;
        }

        $this->storyId = null;
        $this->freeTopic = true;
        $this->groundingChoice = __('Research as a free topic');
        $this->state = 'confirm';
    }

    #[Computed]
    public function selectedPreset(): ?LessonPreset
    {
        return $this->presetKey !== null ? LessonPresets::find($this->presetKey) : null;
    }

    #[Computed]
    public function suggestedPreset(): ?LessonPreset
    {
        return $this->suggestedPresetKey !== null ? LessonPresets::find($this->suggestedPresetKey) : null;
    }

    #[Computed]
    public function selectedStory(): ?Story
    {
        return $this->storyId !== null ? Story::published()->find($this->storyId) : null;
    }

    #[Computed]
    public function openingProposalMessage(): string
    {
        if ($this->suggestedPreset === null) {
            return $this->presetOptionsMessage;
        }

        return __(
            'I suggest a :type. :pitch',
            [
                'type' => $this->presetTypePhrase($this->suggestedPreset),
                'pitch' => $this->presetPitch($this->suggestedPreset->key),
            ],
        );
    }

    /**
     * "a 10-minute <type>" — the preset label with the word "lesson" appended only when the label
     * doesn't already end in it. "Story lesson" + " lesson" read as "Story lesson lesson"; "Deep
     * dive" genuinely needs the noun.
     */
    private function presetTypePhrase(LessonPreset $preset): string
    {
        $label = __($preset->label);

        foreach ([__('lesson'), 'lesson', 'les'] as $noun) {
            if (Str::endsWith(Str::lower($label), Str::lower($noun))) {
                return $label;
            }
        }

        return trim($label.' '.__('lesson'));
    }

    /**
     * Last-resort subject when neither the catalogue nor the parser recognised the goal.
     *
     * A teacher types an instruction ("Teach my group 7 class about Columbus and the crossing of
     * 1492 — the voyage, …"), not a subject. Blindly truncating that to 80 characters produced
     * replies cut off mid-clause — "Ah, Teach my group 7 class about Columbus and the crossing of
     * 1492 — the voyage, the." So: drop the instruction opener, keep only the first clause, and
     * end on a word.
     */
    private function subjectFromGoal(string $goal): string
    {
        $text = trim($goal);

        // "Teach my group 7 class about X", "I want to teach them about X", "Lesson on X" → X
        $text = (string) preg_replace(
            '/^\s*(?:i\s+(?:want|would\s+like)\s+to\s+)?(?:teach|explain|show|tell|cover|make|create|build|give)\b'
            .'[^.]*?\b(?:about|on|over|regarding)\s+/iu',
            '',
            $text,
        );
        $text = (string) preg_replace('/^\s*(?:a\s+)?lesson\s+(?:about|on|over)\s+/iu', '', $text);

        // Keep the first clause only — an em dash, semicolon, colon or sentence end starts the
        // teacher's elaboration, which is detail rather than subject.
        $text = (string) preg_split('/\s*(?:—|–|;|:|\.|\?|!)\s*/u', trim($text), 2)[0];

        // Still long? Cut on a word boundary rather than mid-word.
        $text = trim($text, " \t\n\r,;:-–—");
        if (mb_strlen($text) > 80) {
            $text = (string) preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, 80));
        }

        $text = trim($text, " \t\n\r,;:-–—");

        // Give the reply a capital, and never hand back an empty subject.
        return $text === '' ? Str::limit(trim($goal), 60, '') : Str::ucfirst($text);
    }

    #[Computed]
    public function goalContextMessage(): string
    {
        if (
            $this->subjectDescription !== null
            && $this->periodStart !== null
            && $this->periodEnd !== null
            && $this->regionLabel !== null
        ) {
            $message = __('Ah, :subject — :description, from :start to :end in :region.', [
                'subject' => $this->topic,
                'description' => $this->subjectDescription,
                'start' => $this->periodStart,
                'end' => $this->periodEnd,
                'region' => $this->regionLabel,
            ]);
        } elseif ($this->detectedEra !== null && $this->regionLabel !== null) {
            $message = __('Ah, :subject. I found the period :era in :region.', [
                'subject' => $this->topic,
                'era' => $this->displayEraLabel($this->detectedEra),
                'region' => $this->regionLabel,
            ]);
        } else {
            $message = __('Ah, :subject. I found the subject, but you’ll choose the historical era.', [
                'subject' => $this->topic,
            ]);
        }

        return $message."\n\n".__(
            'You can now add up to three optional focus points, or continue without one.'
        );
    }

    #[Computed]
    public function eraPrompt(): string
    {
        if ($this->detectedEra !== null) {
            return __('I suggest :era.', [
                'era' => $this->displayEraLabel($this->detectedEra),
            ]);
        }

        return __('Which historical era should this lesson cover?');
    }

    #[Computed]
    public function presetOptionsMessage(): string
    {
        return __('Choose the lesson type that fits your class best.');
    }

    #[Computed]
    public function agePrompt(): string
    {
        return __('What age should we focus on? I suggest ages :ages.', [
            'ages' => $this->ageBand($this->selectedAge()),
        ]);
    }

    #[Computed]
    public function ageOptionsMessage(): string
    {
        return __('Which age range fits your class?');
    }

    #[Computed]
    public function groundingPrompt(): string
    {
        return __('I found source options for this lesson. Which should I use?');
    }

    #[Computed]
    public function canonThemePrompt(): string
    {
        $suggested = collect($this->canonThemeOptions)
            ->firstWhere('slug', $this->suggestedCanonTheme);

        if ($suggested !== null) {
            $message = __('This goal best fits Hoofdlijn :number — :theme.', [
                'number' => $suggested['number'],
                'theme' => $suggested['label'],
            ]);
        } else {
            $message = __('I could not match this goal confidently to a Canon theme.');
        }

        return $message.' '.__(
            'Choose the theme you want to teach or strengthen. “Not taught yet” means there is no classroom activity for that theme in your workspace.'
        );
    }

    #[Computed]
    public function confirmationMessage(): string
    {
        $preset = $this->selectedPreset;
        if ($preset === null) {
            return '';
        }

        $subject = $this->selectedStory?->title ?? $this->topic;
        $message = __('Great. I’ll build a :minutes-minute :type for ages :ages about :topic.', [
            'minutes' => $preset->durationTargetMinutes,
            'type' => $this->presetTypePhrase($preset),
            'ages' => $this->ageBand($this->selectedAge()),
            'topic' => $subject,
        ]);

        $message .= match ($preset->gameType) {
            'quiz' => ' '.__('It includes a quiz.'),
            'story_game' => ' '.__('It includes a branching story game.'),
            default => '',
        };

        if ($this->era !== null) {
            $message .= ' '.__('The historical setting is :era.', [
                'era' => $this->displayEraLabel($this->era),
            ]);
        }

        if ($this->canonTheme !== null && $this->canonTheme !== CanonThemeCatalog::OTHER) {
            $theme = app(CanonThemeCatalog::class)->themes()[$this->canonTheme] ?? null;
            if ($theme !== null) {
                $message .= ' '.__('I’ll connect it to Hoofdlijn :number — :theme.', [
                    'number' => $theme['number'],
                    'theme' => $theme['label'],
                ]);
            }
        }

        $message .= "\n\n".($this->selectedStory !== null
            ? __('I’ll ground it in the curated story “:story”.', ['story' => $this->selectedStory->title])
            : __('I’ll research the topic from vetted sources.'));

        return $message.' '.__('You can review everything before students see it.');
    }

    private function presetPitch(string $key): string
    {
        return match ($key) {
            'story' => __('It tells the topic as a story and ends with a short quiz.'),
            'game_story' => __('Teams steer a branching story through choices that shape the plot.'),
            'comprehension_check' => __('It keeps the lesson short and checks understanding as students go.'),
            'deep_dive' => __('It follows the rise and fall in depth and ends with a quiz.'),
            default => __('It turns the learning goal into a focused lesson.'),
        };
    }

    private function selectedAge(): int
    {
        return (int) Str::after($this->gradeLevel, 'Age ');
    }

    private function ageBand(int $age): string
    {
        return $age.'–'.min($age + 2, 18);
    }

    private function displayEraLabel(string $era): string
    {
        return match ($era) {
            'Opstand & Tachtigjarige Oorlog (1568–1648)' => __("Eighty Years' War (1568–1648)"),
            default => $era,
        };
    }

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
            'canon_theme' => $this->usesCanon ? ($this->canonTheme ?? CanonThemeCatalog::OTHER) : null,
            'canon_intent' => $this->usesCanon ? $this->canonIntent : null,
            'grade_level' => $this->gradeLevel,
            'details' => Str::limit(trim($this->goal), self::GOAL_AS_DETAILS_LIMIT, '') ?: null,
            'focus_tags' => count($this->focusTags) > 0 ? $this->focusTags : null,
            'focus' => count($this->focusTags) > 0 ? \App\Lessons\FocusTags::labels($this->focusTags) : null,
            'region' => $this->region,
            'era' => $this->era,
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

    private function advanceToEra(): void
    {
        $this->state = 'era';

        if ($this->detectedEra !== null && $this->era === $this->detectedEra) {
            $this->eraChoice = $this->displayEraLabel($this->detectedEra);
            $this->state = $this->suggestedPresetKey !== null
                ? 'preset_confirmation'
                : 'preset_options';
        }
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
