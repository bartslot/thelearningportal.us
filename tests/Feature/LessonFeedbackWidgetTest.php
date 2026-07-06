<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\LessonFeedbackWidget;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LessonFeedbackWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function makeLesson(User $teacher): Lesson
    {
        return Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Julius Caesar',
            'subject' => 'history',
            'grade_level' => '8',
        ]);
    }

    public function test_teacher_can_rate_and_re_rate_a_lesson(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->makeLesson($teacher);

        Livewire::actingAs($teacher)
            ->test(LessonFeedbackWidget::class, ['lesson' => $lesson])
            ->call('rate', 2)
            ->assertSet('saved', true);

        $this->assertSame(2, LessonFeedback::sole()->rating);

        Livewire::actingAs($teacher)
            ->test(LessonFeedbackWidget::class, ['lesson' => $lesson])
            ->assertSet('rating', 2)
            ->call('rate', 5)
            ->set('comment', 'Great story now')
            ->call('save');

        // Re-rating updates in place — never a second row.
        $feedback = LessonFeedback::sole();
        $this->assertSame(5, $feedback->rating);
        $this->assertSame('Great story now', $feedback->comment);
    }

    public function test_other_teachers_cannot_rate_someone_elses_lesson(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lesson = $this->makeLesson($owner);

        Livewire::actingAs($other)
            ->test(LessonFeedbackWidget::class, ['lesson' => $lesson])
            ->assertForbidden();
    }
}
