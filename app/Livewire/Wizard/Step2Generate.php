<?php

declare(strict_types=1);

namespace App\Livewire\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Models\Lesson;
use App\Models\Scene;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Step2Generate extends Component
{
    public Lesson $lesson;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;

        if ($lesson->status === LessonStatus::ScenesReady) {
            $lesson->update(['wizard_step' => 3]);
            $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 3], navigate: true);
            return;
        }
    }

    #[Computed]
    public function scenes()
    {
        return $this->lesson->scenes()->ordered()->get();
    }

    #[Computed]
    public function canContinue(): bool
    {
        return $this->lesson->scenes()->where('status', 'ready')->exists();
    }

    #[Computed]
    public function overallProgress(): int
    {
        $scenes = $this->scenes;
        if ($scenes->isEmpty()) {
            return 0;
        }

        $total = $scenes->count() * 3;
        $done  = 0;
        foreach ($scenes as $s) {
            if ((string) $s->script_segment !== '') $done++;
            if ($s->image_path !== null)            $done++;
            if ($s->audio_path !== null)            $done++;
        }

        return (int) floor(($done / max(1, $total)) * 100);
    }

    public function retryAsset(int $sceneId, string $asset): void
    {
        $scene = Scene::where('lesson_id', $this->lesson->id)->findOrFail($sceneId);
        $scene->update(['status' => 'generating', 'error_message' => null]);

        match ($asset) {
            'script' => GenerateSceneScript::dispatch($scene->id),
            'image'  => GenerateSceneImage::dispatch($scene->id),
            'audio'  => GenerateSceneAudio::dispatch($scene->id),
            default  => null,
        };
    }

    public function retryOutline(): void
    {
        $this->lesson->scenes()->delete();
        $this->lesson->update([
            'status'        => LessonStatus::SourceReady,
            'error_message' => null,
            'outline'       => null,
        ]);
        BuildLessonOutline::dispatch($this->lesson->id);
    }

    public function continueToConfigure(): void
    {
        $this->lesson->update(['wizard_step' => 3]);
        $this->redirectRoute('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 3], navigate: true);
    }

    public function render()
    {
        return view('livewire.wizard.step2-generate');
    }
}
