<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lesson poster is never empty: a teacher override wins, otherwise it auto-picks an image
 * already loaded in the lesson, with the narrator portrait as a final guaranteed fallback.
 */
class LessonPosterTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(): Lesson
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        return Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'Columbus', 'subject' => 'history',
            'grade_level' => 'K-7', 'image_style' => 'realistic', 'status' => LessonStatus::Previewable->value,
        ]);
    }

    public function test_poster_url_is_never_empty_even_with_no_images(): void
    {
        $this->assertNotSame('', $this->lesson()->posterUrl());
    }

    public function test_poster_auto_picks_a_gallery_image_already_in_the_lesson(): void
    {
        $lesson = $this->lesson();
        Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'gallery', 'status' => 'ready',
            'config' => ['images' => [['url' => 'https://cdn.example.com/painting.jpg', 'credit' => '']]],
        ]);

        $this->assertSame('https://cdn.example.com/painting.jpg', $lesson->refresh()->posterUrl());
        $this->assertContains(
            'https://cdn.example.com/painting.jpg',
            collect($lesson->posterCandidates())->pluck('url')->all(),
        );
    }

    public function test_teacher_override_wins_over_auto_pick(): void
    {
        $lesson = $this->lesson();
        Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'voyage', 'status' => 'ready',
            'location' => 'Cuba',
            'config' => ['gallery' => ['images' => [['url' => 'https://cdn.example.com/auto.jpg']]],
                'stop_images' => ['https://cdn.example.com/pin.jpg']],
        ]);
        $lesson->refresh();

        // Auto-pick returns the first loaded image; an override to a DIFFERENT lesson image wins.
        $auto = $lesson->posterUrl();
        $this->assertNotSame('https://cdn.example.com/auto.jpg', $auto);
        $lesson->update(['poster_image' => 'https://cdn.example.com/auto.jpg']);
        $this->assertSame('https://cdn.example.com/auto.jpg', $lesson->fresh()->posterUrl());

        // …and it flows through to the card image too.
        $this->assertSame('https://cdn.example.com/auto.jpg', $lesson->fresh()->cardImageUrl());
    }
}
