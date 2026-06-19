<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneScript;
use App\Livewire\Wizard\Step2Generate;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class Step2GenerateTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'status' => LessonStatus::ScenesGenerating,
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'generating',
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration',
            'script_segment' => 'ok', 'image_path' => 'x.png', 'audio_path' => 'a.mp3', 'status' => 'ready',
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration',
            'status' => 'failed', 'error_message' => 'boom',
        ]);
    }

    public function test_does_not_auto_advance_when_all_scenes_are_ready(): void
    {
        // Lesson 3's situation: fully generated (ScenesReady). Returning to step 2 must NOT redirect.
        $lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'Y', 'subject' => 'history', 'grade_level' => '9th',
            'status' => LessonStatus::ScenesReady,
        ]);
        Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'ok', 'image_path' => 'x.png', 'audio_path' => 'a.mp3', 'status' => 'ready',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $lesson])
            ->call('checkAndAutoAdvance')
            ->assertNoRedirect();
    }

    public function test_auto_advances_during_generation_when_a_scene_is_ready(): void
    {
        // The setup lesson is ScenesGenerating with a ready scene — the convenience auto-advance fires.
        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $this->lesson])
            ->call('checkAndAutoAdvance')
            ->assertRedirect(route('teacher.lessons.wizard', ['lesson' => $this->lesson->id, 'step' => 3]));
    }

    public function test_shows_per_scene_asset_states(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $this->lesson])
            ->assertSee('Scene 1')
            ->assertSee('Scene 2')
            ->assertSee('Scene 3')
            ->assertSee('boom');
    }

    public function test_enables_continue_when_at_least_one_scene_is_ready(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $this->lesson])
            ->assertSet('canContinue', true);
    }

    public function test_per_asset_retry_dispatches_the_matching_job_for_one_scene(): void
    {
        Bus::fake();
        $failed = Scene::where('status', 'failed')->first();

        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $this->lesson])
            ->call('retryAsset', $failed->id, 'script');

        Bus::assertDispatched(GenerateSceneScript::class, fn ($j) => $j->sceneId === $failed->id);
        Bus::assertNotDispatched(GenerateSceneImage::class);
    }

    public function test_outline_retry_deletes_scenes_and_redispatches_build_lesson_outline(): void
    {
        Bus::fake();
        $this->lesson->update(['status' => LessonStatus::Failed, 'error_message' => 'outline died']);

        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $this->lesson])
            ->call('retryOutline');

        $this->assertSame(0, Scene::where('lesson_id', $this->lesson->id)->count());
        Bus::assertDispatched(BuildLessonOutline::class);
    }

    public function test_visiting_step2_when_scenes_ready_does_not_auto_redirect(): void
    {
        $this->lesson->update(['status' => LessonStatus::ScenesReady]);

        Livewire::actingAs($this->teacher)
            ->test(Step2Generate::class, ['lesson' => $this->lesson])
            ->assertNoRedirect();
    }
}
