<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Http\Requests\StoreWizardSettingsRequest;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\StrategyGame;
use App\Services\DocumentExtractor;
use App\Services\Support\GradeBandStyleRecommender;
use App\Services\Support\HistoryTaxonomy;
use App\Services\Support\ImageStyleTemplate;
use App\Services\Support\ToneRecommender;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class Step1Settings extends Component
{
    use WithFileUploads;

    public ?Lesson $lesson = null;

    // Display name of the chosen catalog topic (set on select; also the search box value).
    public string $topic = '';

    // Curated-catalog reference, e.g. "polity:Q2277". Null until a catalog item is picked (A1).
    public ?string $topicId = null;

    // Exact Wikipedia article URL for the chosen topic — the pipeline fetches THIS (kills France→Egypt bug).
    public ?string $topicWikipediaUrl = null;

    // Name of the locked catalog topic — guards against a trailing debounce clearing the lock.
    public string $lockedTopicName = '';

    // Optional free-text angle/focus, e.g. "daily life of a soldier".
    public string $focus = '';

    public string $subject = 'history';

    public string $grade_level = 'Age 12';

    public string $audience_system = 'age';     // 'age' | 'local'

    public string $local_grade = '';        // locale-specific value, e.g. "Groep 7"

    public int $audience_age = 12;

    // Legacy — kept to hydrate old lessons stored with grade strings
    public string $audience_mode = 'age';

    public string $grade_choice = '';

    public string $tone = '';

    public string $details = '';

    public string $source_mode = 'internet';

    public string $source_url = '';

    public $sourceUpload = null;

    public string $image_style = 'realistic';

    public ?int $avatar_id = null;

    public ?string $region = null;

    public ?string $era = null;

    public bool $show_region_era = false;

    public bool $include_game = false;

    public ?string $game_type = null;   // quiz | strategy | debate

    public int $quiz_question_count = 4;

    public ?string $quiz_timing = null;   // during | after | both

    public ?string $strategy_game = null;

    // Legacy columns — kept for DB compat, not rendered
    public ?int $strategy_game_id = null;

    public ?int $team_count = null;

    public int $game_split_count = 1;

    public string $lesson_code = '';

    public ?int $duration_minutes = null;

    public ?int $duration_seconds = null;

    public $portrait = null;

    public function mount(?Lesson $lesson = null): void
    {
        if ($lesson?->exists) {
            $this->lesson = $lesson;
            $this->topic = $lesson->topic ?? '';
            $this->topicId = $lesson->topic_id;
            $this->lockedTopicName = $lesson->topic_id ? ($lesson->topic ?? '') : '';
            $this->topicWikipediaUrl = $lesson->wikipedia_source;
            $this->focus = $lesson->focus ?? '';
            $this->subject = $lesson->subject ?? 'history';
            $this->grade_level = $lesson->grade_level ?? 'Age 12';
            $this->hydrateAudienceFromGradeLevel($this->grade_level);
            $this->tone = $lesson->tone ?? '';
            $this->details = $lesson->details ?? '';
            $this->source_mode = $lesson->source_mode ?? 'internet';
            $this->source_url = $lesson->source?->source_url ?? '';
            $this->image_style = $lesson->image_style ?? 'realistic';
            $this->avatar_id = $lesson->avatar_id;
            $this->region = $lesson->region;
            $this->era = $lesson->era;
            $this->show_region_era = $lesson->region !== null || $lesson->era !== null;
            $this->include_game = (bool) ($lesson->include_game ?? false);
            $this->game_type = $lesson->game_type;
            $this->quiz_question_count = (int) ($lesson->quiz_question_count ?? 4);
            $this->quiz_timing = $lesson->quiz_timing;
            $this->strategy_game = $lesson->strategy_game ? (string) $lesson->strategy_game : null;
            $this->strategy_game_id = $lesson->strategy_game_id;
            $this->team_count = $lesson->team_count;
            $this->game_split_count = (int) ($lesson->game_split_count ?? 1);
            $this->lesson_code = $lesson->lesson_code ?? '';
            if ($lesson->duration_seconds !== null) {
                $this->duration_minutes = (int) floor($lesson->duration_seconds / 60);
                $this->duration_seconds = $lesson->duration_seconds % 60;
            }
        } else {
            $this->lesson_code = strtoupper(Str::random(6));
            $this->image_style = GradeBandStyleRecommender::recommend($this->grade_level)[0];
            $this->avatar_id = Avatar::where('is_active', true)->orderBy('sort_order')->value('id');
            $this->tone = 'storytelling';
        }

        // Prefill the topic from the Time-Map "Create lesson" button. The map passes a catalog
        // id (?topic_id=polity:Q2277) when available, else a name (?topic=…) we resolve to the catalog.
        if (! ($lesson?->exists)) {
            if (is_string($tid = request()->query('topic_id')) && trim($tid) !== '') {
                $this->selectTopic(trim($tid));
            } elseif (is_string($t = request()->query('topic')) && trim($t) !== '') {
                $match = \App\Models\Corpus\Topic::search(trim($t), 1)->first();
                if ($match) {
                    $this->selectTopic($match->id);
                } else {
                    $this->topic = trim($t);
                }
            }
        }
    }

    public const AGE_MIN = 4;

    public const AGE_MAX = 18;

    public function setAudienceSystem(string $system): void
    {
        $this->audience_system = in_array($system, ['age', 'local'], true) ? $system : 'age';
        $this->syncGradeLevel();
    }

    public function updatedLocalGrade(): void
    {
        $this->syncGradeLevel();
    }

    public function updatedAudienceAge(): void
    {
        $this->syncGradeLevel();
    }

    public function updatedTopic(): void
    {
        // Only break the lock when the text actually diverges from the selected topic — a trailing
        // debounce that re-sends the same name must NOT clear an already-locked selection.
        if (trim($this->topic) !== $this->lockedTopicName) {
            $this->topicId = null;
            $this->topicWikipediaUrl = null;
            $this->lockedTopicName = '';
        }

        if (trim($this->topic) === '') {
            $this->region = null;
            $this->era = null;
            $this->show_region_era = false;
        }
    }

    public function updatedRegion(): void
    {
        $this->era = null; // reset era when region changes
    }

    public function updatedIncludeGame(): void
    {
        if ($this->include_game) {
            $this->game_type = 'quiz';
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

    private function syncGradeLevel(): void
    {
        if ($this->audience_system === 'local' && $this->local_grade !== '') {
            $this->grade_level = $this->local_grade;
        } else {
            $age = max(self::AGE_MIN, min(self::AGE_MAX, $this->audience_age));
            $this->grade_level = 'Age '.$age;
        }
    }

    private function hydrateAudienceFromGradeLevel(string $level): void
    {
        // Age string from new system
        if (preg_match('/^Age\s*(\d+)$/i', $level, $m)) {
            $this->audience_system = 'age';
            $this->audience_age = max(self::AGE_MIN, min(self::AGE_MAX, (int) $m[1]));

            return;
        }

        // Legacy US grade strings (e.g. "9th grade") — convert to age
        $legacyMap = [
            '3rd grade' => 8, '4th grade' => 9, '5th grade' => 10,
            '6th grade' => 11, '7th grade' => 12, '8th grade' => 13,
            '9th grade' => 14,
        ];
        if (isset($legacyMap[$level])) {
            $this->audience_system = 'age';
            $this->audience_age = $legacyMap[$level];
            $this->grade_level = 'Age '.$legacyMap[$level];

            return;
        }

        // Locale-specific value — store as local_grade
        $this->audience_system = 'local';
        $this->local_grade = $level;
    }

    /**
     * Curated-catalog search results for the picker (A1/A2). Each row carries the catalog id so
     * selecting it locks the lesson to a Wikipedia-grounded topic.
     *
     * @return list<array{id:string,name:string,type:string,figure_kind:?string,era:string,region:?string}>
     */
    #[Computed]
    public function topicSuggestions(): array
    {
        if (strlen(trim($this->topic)) < 2) {
            return [];
        }

        return \App\Models\Corpus\Topic::resilient(
            fn () => \App\Models\Corpus\Topic::search($this->topic, 10)->get()
        )->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
            'figure_kind' => $t->figure_kind,
            'era' => $t->eraLabel(),
            'region' => $t->region_label,
        ])->all();
    }

    /** Select a catalog topic by its id — locks the lesson to a grounded source (A1). */
    public function selectTopic(string $id): void
    {
        $topic = \App\Models\Corpus\Topic::resilient(fn () => \App\Models\Corpus\Topic::find($id));
        if (! $topic) {
            $this->addError('topic', 'That topic is not in the catalog. Pick one from the list.');

            return;
        }

        $this->topic = $topic->name;
        $this->lockedTopicName = $topic->name;
        $this->topicId = $topic->id;
        $this->topicWikipediaUrl = $topic->wikipedia_url;
        $this->region = $topic->region_label ?: $this->region;
        $this->era = $topic->eraLabel() ?: $this->era;

        if ($this->region || $this->era) {
            $this->show_region_era = true;
        }
    }

    #[Computed]
    public function gradeSystem(): ?array
    {
        return HistoryTaxonomy::gradeSystemFor(app()->getLocale());
    }

    #[Computed]
    public function regionOptions(): array
    {
        $locale = app()->getLocale();

        return array_map(
            fn ($r) => ['value' => $r['value'], 'label' => $r['label']],
            HistoryTaxonomy::regionsFor($locale),
        );
    }

    #[Computed]
    public function eraOptions(): array
    {
        if (! $this->region) {
            return [];
        }

        return array_map(
            fn ($e) => ['value' => $e, 'label' => $e],
            HistoryTaxonomy::erasFor($this->region),
        );
    }

    #[Computed]
    public function avatars()
    {
        return Avatar::where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function games()
    {
        return StrategyGame::active()->orderBy('title')->get();
    }

    #[Computed]
    public function styleOptions(): array
    {
        return array_map(fn (string $k) => [
            'key' => $k,
            'label' => ucfirst($k),
            'thumb' => asset("assets/style-{$k}.webp"),
        ], ImageStyleTemplate::styles());
    }

    #[Computed]
    public function recommendedStyles(): array
    {
        return GradeBandStyleRecommender::recommend($this->grade_level);
    }

    #[Computed]
    public function tones(): array
    {
        return ToneRecommender::tones();
    }

    #[Computed]
    public function recommendedTones(): array
    {
        // Always use the resolved age (audience_age) so local grade systems
        // (e.g. "Groep 7" → age 11) give correct recommendations instead of
        // treating the raw grade number as an age.
        return ToneRecommender::recommend('Age '.$this->audience_age);
    }

    protected function rules(): array
    {
        $rules = (new StoreWizardSettingsRequest)->rules();

        // sourceUpload is required when source_mode === 'local' (file upload mode)
        if ($this->source_mode === 'local') {
            $rules['sourceUpload'] = ['required', 'file', 'mimes:pdf,docx', 'max:10240'];
        }

        return $rules;
    }

    /**
     * Server-side guard (A1): the chosen topic must come from the curated catalog. Rejects
     * free-text topics with no catalog id, or an id that isn't in public.topics.
     */
    private function validateTopicCatalog(): void
    {
        $exists = $this->topicId
            && \App\Models\Corpus\Topic::resilient(
                fn () => \App\Models\Corpus\Topic::whereKey($this->topicId)->exists()
            );
        if (! $exists) {
            $this->addError('topic', 'Choose a topic from the list — this grounds the lesson in a real source.');
            throw \Illuminate\Validation\ValidationException::withMessages([
                'topic' => 'Choose a topic from the list.',
            ]);
        }
    }

    public function saveDraft(): Lesson
    {
        $this->validate();
        $this->validateTopicCatalog();

        return $this->persist(LessonStatus::Draft);
    }

    public function generate(): void
    {
        $this->validate();
        $this->validateTopicCatalog();
        $lesson = $this->persist(LessonStatus::Draft);
        $lesson->refresh()->startGenerationPipeline();

        // The parent LessonWizard reads $lesson->wizard_step on mount and uses it as the
        // authoritative step (it overrides the URL's ?step). Advance it here so the
        // redirect actually lands on Step 2 instead of bouncing back to Step 1.
        $lesson->update(['wizard_step' => 2]);

        $this->redirect(
            route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2]),
            navigate: true,
        );
    }

    private function persist(LessonStatus $status): Lesson
    {
        $lesson = $this->lesson ?? new Lesson;

        $lesson->fill([
            'teacher_id' => auth()->id(),
            'topic' => trim($this->topic),
            'topic_id' => $this->topicId,
            'focus' => trim($this->focus) ?: null,
            'wikipedia_source' => $this->topicWikipediaUrl,
            'subject' => 'history',
            'region' => $this->region ?: null,
            'era' => $this->era ?: null,
            'grade_level' => $this->grade_level,
            'tone' => trim($this->tone) ?: null,
            'details' => trim($this->details) ?: null,
            'source_mode' => $this->source_mode,
            'image_style' => $this->image_style,
            'avatar_id' => $this->avatar_id,
            'include_game' => $this->include_game,
            'game_type' => $this->include_game ? $this->game_type : null,
            'quiz_question_count' => $this->include_game && $this->game_type === 'quiz' ? $this->quiz_question_count : null,
            'quiz_timing' => $this->include_game && $this->game_type === 'quiz' ? $this->quiz_timing : null,
            'strategy_game' => $this->include_game && $this->game_type === 'strategy' ? $this->strategy_game : null,
            'strategy_game_id' => $this->include_game && $this->game_type === 'strategy' ? $this->strategy_game_id : null,
            'team_count' => $this->include_game && $this->game_type === 'strategy' ? $this->team_count : null,
            'game_split_count' => $this->include_game && $this->game_type === 'strategy' ? $this->game_split_count : 1,
            'lesson_code' => strtoupper($this->lesson_code),
            'duration_seconds' => $this->duration_minutes !== null
                ? ($this->duration_minutes * 60) + ($this->duration_seconds ?? 0)
                : null,
            'status' => $status,
            'wizard_step' => 1,
        ]);
        $lesson->save();

        if ($this->portrait) {
            $path = $this->portrait->storeAs("lessons/{$lesson->id}", 'portrait.jpg', 'public');
            $lesson->update(['portrait_path' => $path]);
        }

        $this->buildSource($lesson);

        $this->lesson = $lesson;

        return $lesson;
    }

    private function buildSource(Lesson $lesson): void
    {
        $source = \App\Models\LessonSource::firstOrNew(['lesson_id' => $lesson->id]);

        $combinedText = '';
        $kind = null;
        $filePath = null;
        $original = null;

        if ($this->source_mode === 'local' && $this->sourceUpload) {
            $extension = strtolower($this->sourceUpload->getClientOriginalExtension());
            $kindUp = $extension === 'docx' ? 'docx' : 'pdf';

            $filePath = $this->sourceUpload->storeAs("lessons/{$lesson->id}", "source.{$extension}", 'public');
            $original = $this->sourceUpload->getClientOriginalName();

            $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($filePath);
            $combinedText = app(DocumentExtractor::class)->extractFromPath($absolute);

            $kind = $kindUp;
        }

        if ($this->source_mode === 'internet') {
            // Actual fetch is deferred to BuildLessonOutline job (can take 10-20s).
            // Just mark the kind so the job knows to fetch worldhistory.org → wikipedia.
            $kind ??= 'internet';
        }

        if ($kind === null) {
            return;
        }

        $source->fill([
            'lesson_id' => $lesson->id,
            'kind' => $kind,
            'original_filename' => $original,
            'file_path' => $filePath,
            'extracted_text' => $combinedText,
            'source_url' => $this->source_mode === 'local' ? trim($this->source_url) : null,
            'wikipedia_topic' => $this->source_mode === 'internet' ? $lesson->topic : null,
        ])->save();
    }

    public function render()
    {
        return view('livewire.wizard.step1-settings');
    }
}
