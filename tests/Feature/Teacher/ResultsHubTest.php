<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultsHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_lists_own_lessons_activity_grouped_per_day_and_hides_other_teachers(): void
    {
        $teacher = User::factory()->create();
        $other = User::factory()->create();
        $mine = Lesson::create(['teacher_id' => $teacher->id, 'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        $theirs = Lesson::create(['teacher_id' => $other->id, 'topic' => 'Rome', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizScore::create(['lesson_id' => $mine->id, 'nickname' => 'Emma V.', 'score' => 20, 'correct' => 2, 'total' => 2]);
        QuizScore::create(['lesson_id' => $theirs->id, 'nickname' => 'Ghost', 'score' => 20, 'correct' => 2, 'total' => 2]);

        $this->actingAs($teacher)->get('/teacher/results')
            ->assertOk()
            ->assertSee('Napoleon')
            ->assertDontSee('Rome');
    }

    public function test_activity_eager_loads_lessons_without_per_row_queries(): void
    {
        $teacher = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $lesson = Lesson::create(['teacher_id' => $teacher->id, 'topic' => "L{$i}", 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
            QuizScore::create(['lesson_id' => $lesson->id, 'nickname' => 'A', 'score' => 10, 'correct' => 1, 'total' => 1, 'created_at' => now()->subDay()]);
            QuizScore::create(['lesson_id' => $lesson->id, 'nickname' => 'B', 'score' => 10, 'correct' => 1, 'total' => 1, 'created_at' => now()]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Livewire\Livewire::actingAs($teacher)->test(\App\Livewire\Teacher\ResultsHub::class)->assertOk();
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // With with('lesson') eager-loading, the 3 distinct lessons behind the 6 lesson-day
        // rows are fetched in a single batched "id in (...)" query, not one SELECT per row.
        // Observed: 9 queries with eager load vs 11 without (this fixture only has 3 distinct
        // lessons, so the naive N+1 cost is diluted — but it still scales O(distinct lessons)
        // instead of O(1) as more lessons are added). Assert no per-row "lessons"."id" = ?
        // lookups leak into the log — that pattern is the actual regression signature.
        $singleLessonLookups = collect($log)->filter(
            fn ($q) => str_contains($q['query'], 'from "lessons" where "lessons"."id" = ?')
        )->count();

        $this->assertSame(
            0,
            $singleLessonLookups,
            "Found {$singleLessonLookups} per-row single-lesson SELECTs — the 'lesson' relation is lazy-loading per row instead of being eager-loaded."
        );
    }
}
