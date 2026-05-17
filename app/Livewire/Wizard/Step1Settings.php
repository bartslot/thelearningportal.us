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
use App\Services\Support\ImageStyleTemplate;
use App\Services\WikipediaService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class Step1Settings extends Component
{
    use WithFileUploads;

    public ?Lesson $lesson = null;

    public string $topic            = '';
    public string $subject          = 'history';
    public string $grade_level      = '9th grade';
    public string $tone             = '';
    public string $details          = '';
    public string $source_mode      = 'wikipedia';
    public        $sourceUpload     = null;
    public string $image_style      = 'realistic';
    public ?int   $avatar_id        = null;
    public ?int   $strategy_game_id = null;
    public ?int   $team_count       = null;
    public int    $game_split_count = 1;
    public string $lesson_code      = '';
    public ?int   $duration_minutes = null;
    public ?int   $duration_seconds = null;
    public        $portrait         = null;

    public function mount(?Lesson $lesson = null): void
    {
        if ($lesson?->exists) {
            $this->lesson           = $lesson;
            $this->topic            = $lesson->topic ?? '';
            $this->subject          = $lesson->subject ?? 'history';
            $this->grade_level      = $lesson->grade_level ?? '9th grade';
            $this->tone             = $lesson->tone ?? '';
            $this->details          = $lesson->details ?? '';
            $this->source_mode      = $lesson->source_mode ?? 'wikipedia';
            $this->image_style      = $lesson->image_style ?? 'realistic';
            $this->avatar_id        = $lesson->avatar_id;
            $this->strategy_game_id = $lesson->strategy_game_id;
            $this->team_count       = $lesson->team_count;
            $this->game_split_count = (int) ($lesson->game_split_count ?? 1);
            $this->lesson_code      = $lesson->lesson_code ?? '';
            if ($lesson->duration_seconds !== null) {
                $this->duration_minutes = (int) floor($lesson->duration_seconds / 60);
                $this->duration_seconds = $lesson->duration_seconds % 60;
            }
        } else {
            $this->lesson_code = strtoupper(Str::random(6));
            $this->image_style = GradeBandStyleRecommender::recommend($this->grade_level)[0];
        }
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
            'key'   => $k,
            'label' => ucfirst($k),
            'thumb' => asset("assets/style-{$k}.png"),
        ], ImageStyleTemplate::styles());
    }

    #[Computed]
    public function recommendedStyles(): array
    {
        return GradeBandStyleRecommender::recommend($this->grade_level);
    }

    protected function rules(): array
    {
        return (new StoreWizardSettingsRequest())->rules();
    }

    public function saveDraft(): Lesson
    {
        $this->validate();
        return $this->persist(LessonStatus::Draft);
    }

    public function generate(): void
    {
        $lesson = $this->persist(LessonStatus::Draft);
        $lesson->refresh()->startGenerationPipeline();
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 2], navigate: true);
    }

    private function persist(LessonStatus $status): Lesson
    {
        $lesson = $this->lesson ?? new Lesson();

        $lesson->fill([
            'teacher_id'       => auth()->id(),
            'topic'            => trim($this->topic),
            'subject'          => $this->subject,
            'grade_level'      => $this->grade_level,
            'tone'             => trim($this->tone) ?: null,
            'details'          => trim($this->details) ?: null,
            'source_mode'      => $this->source_mode,
            'image_style'      => $this->image_style,
            'avatar_id'        => $this->avatar_id,
            'strategy_game_id' => $this->strategy_game_id,
            'team_count'       => $this->team_count,
            'game_split_count' => $this->game_split_count,
            'lesson_code'      => strtoupper($this->lesson_code),
            'duration_seconds' => $this->duration_minutes !== null
                ? ($this->duration_minutes * 60) + ($this->duration_seconds ?? 0)
                : null,
            'status'           => $status,
            'wizard_step'      => 1,
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
        $kind         = $this->source_mode;
        $filePath     = null;
        $original     = null;

        if (in_array($this->source_mode, ['upload', 'both'], true) && $this->sourceUpload) {
            $extension = strtolower($this->sourceUpload->getClientOriginalExtension());
            $kindUp    = $extension === 'docx' ? 'docx' : 'pdf';

            $filePath = $this->sourceUpload->storeAs("lessons/{$lesson->id}", "source.{$extension}", 'public');
            $original = $this->sourceUpload->getClientOriginalName();

            $absolute     = \Illuminate\Support\Facades\Storage::disk('public')->path($filePath);
            $combinedText = app(DocumentExtractor::class)->extractFromPath($absolute);

            if ($this->source_mode === 'upload') {
                $kind = $kindUp;
            }
        }

        if (in_array($this->source_mode, ['wikipedia', 'both'], true)) {
            $wiki = app(WikipediaService::class)->fetchFacts($lesson->topic) ?? '';
            $combinedText = trim($combinedText . "\n\n" . $wiki);
        }

        $source->fill([
            'lesson_id'         => $lesson->id,
            'kind'              => $kind,
            'original_filename' => $original,
            'file_path'         => $filePath,
            'extracted_text'    => $combinedText,
            'wikipedia_topic'   => in_array($this->source_mode, ['wikipedia', 'both'], true) ? $lesson->topic : null,
        ])->save();
    }

    public function render()
    {
        return view('livewire.wizard.step1-settings');
    }
}
