<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SceneModelTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->lesson  = Lesson::create([
            'teacher_id'  => $this->teacher->id,
            'topic'       => 'Napoleonic Campaigns',
            'subject'     => 'history',
            'grade_level' => '9th grade',
        ]);
    }

    public function test_belongs_to_a_lesson(): void
    {
        $scene = Scene::create([
            'lesson_id' => $this->lesson->id,
            'order'     => 1,
            'kind'      => 'narration',
        ]);

        $this->assertInstanceOf(Lesson::class, $scene->lesson);
        $this->assertSame($this->lesson->id, $scene->lesson->id);
    }

    public function test_casts_audio_alignment_to_array(): void
    {
        $scene = Scene::create([
            'lesson_id'       => $this->lesson->id,
            'order'           => 1,
            'kind'            => 'narration',
            'audio_alignment' => ['characters' => ['H', 'i']],
        ]);

        $fresh = $scene->fresh();
        $this->assertIsArray($fresh->audio_alignment);
        $this->assertSame(['H', 'i'], $fresh->audio_alignment['characters']);
    }

    public function test_orders_scenes_by_order_via_the_scope(): void
    {
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration']);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration']);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'game']);

        $this->assertSame([1, 2, 3], Scene::ordered()->pluck('order')->all());
    }
}
