<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step4Preview;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Step4PreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson  = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'status' => LessonStatus::Previewable,
        ]);
    }

    public function test_lists_scenes_for_the_player(): void
    {
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'year' => '1810', 'location' => 'Paris', 'image_path' => 'p.png',
            'audio_path' => 'a.mp3', 'audio_alignment' => [], 'duration_seconds' => 10,
            'status' => 'ready', 'script_segment' => 'A long enough script.',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->assertViewHas('lesson')
            ->assertSee('1810')
            ->assertSee('Paris');
    }

    public function test_publishes_the_lesson_only_when_all_scenes_are_ready(): void
    {
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready',
            'script_segment' => 's', 'image_path' => 'a', 'audio_path' => 'b',
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration', 'status' => 'failed',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->call('publish')
            ->assertSet('publishError', fn ($v) => is_string($v) && $v !== '');

        Scene::where('status', 'failed')->update([
            'status' => 'ready', 'script_segment' => 's', 'image_path' => 'a', 'audio_path' => 'b',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->call('publish');

        $this->assertSame(LessonStatus::Published, $this->lesson->fresh()->status);
    }

    public function test_forbids_another_teacher_from_accessing_the_preview(): void
    {
        $other = User::factory()->create();

        Livewire::actingAs($other)
            ->test(Step4Preview::class, ['lesson' => $this->lesson])
            ->assertForbidden();
    }
}
