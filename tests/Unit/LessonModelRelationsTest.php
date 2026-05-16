<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_scenes_ordered_by_order(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'WWII',
            'subject'     => 'history',
            'grade_level' => '10th grade',
        ]);

        Scene::create(['lesson_id' => $lesson->id, 'order' => 2, 'kind' => 'narration']);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);

        $this->assertSame([1, 2], $lesson->scenes()->pluck('order')->all());
    }

    public function test_has_a_lesson_source(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'WWII',
            'subject'     => 'history',
            'grade_level' => '10th grade',
        ]);

        LessonSource::create([
            'lesson_id'       => $lesson->id,
            'kind'            => 'wikipedia',
            'extracted_text'  => 'WWII source text',
            'wikipedia_topic' => 'World_War_II',
        ]);

        $this->assertInstanceOf(LessonSource::class, $lesson->source);
    }

    public function test_accepts_the_new_wizard_fillable_fields(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'       => $teacher->id,
            'topic'            => 'Test',
            'subject'          => 'history',
            'grade_level'      => '8th',
            'image_style'      => 'painted',
            'source_mode'      => 'both',
            'team_count'       => 3,
            'game_split_count' => 2,
            'wizard_step'      => 3,
            'outline'          => ['title' => 'X', 'scene_briefs' => []],
        ]);

        $this->assertSame('painted', $lesson->image_style);
        $this->assertSame('both', $lesson->source_mode);
        $this->assertSame(3, $lesson->team_count);
        $this->assertSame(2, $lesson->game_split_count);
        $this->assertSame(3, $lesson->wizard_step);
        $this->assertIsArray($lesson->outline);
    }
}
