<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonWizardRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_the_wizard_at_teacher_lessons_create(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/teacher/lessons/create')
            ->assertOk()
            ->assertSeeLivewire(\App\Livewire\LessonWizard::class);
    }

    public function test_resumes_the_wizard_for_an_existing_lesson(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id' => $teacher->id, 'topic' => 'X',
            'subject' => 'history', 'grade_level' => '9th',
        ]);

        $this->actingAs($teacher)
            ->get("/teacher/lessons/{$lesson->id}/wizard?step=1")
            ->assertOk()
            ->assertSeeLivewire(\App\Livewire\LessonWizard::class);
    }

    public function test_forbids_accessing_another_teachers_wizard(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $owner->id, 'topic' => 'X',
            'subject' => 'history', 'grade_level' => '9th',
        ]);

        $this->actingAs($other)
            ->get("/teacher/lessons/{$lesson->id}/wizard?step=1")
            ->assertForbidden();
    }

    public function test_legacy_livewire_classes_are_removed(): void
    {
        $this->assertFalse(class_exists(\App\Livewire\CreateLesson::class));
        $this->assertFalse(class_exists(\App\Livewire\LessonSettings::class));
    }

    /**
     * A stray non-numeric {lesson} segment (e.g. a JS-built "/teacher/lessons/null/wizard"
     * where the id was null) must 404 — it must NOT reach route-model binding, where Postgres
     * would fail casting "null" to bigint and return a 500.
     *
     * @dataProvider nonNumericLessonUrls
     */
    public function test_non_numeric_lesson_id_404s_instead_of_a_db_error(string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertNotFound();
    }

    public static function nonNumericLessonUrls(): array
    {
        return [
            'wizard'   => ['/teacher/lessons/null/wizard'],
            'composer' => ['/teacher/lessons/null/composer'],
            'show'     => ['/teacher/lessons/null'],
            'junk'     => ['/teacher/lessons/abc/wizard'],
        ];
    }
}
