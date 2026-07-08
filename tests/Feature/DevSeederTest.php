<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\QuizAnswer;
use App\Models\QuizScore;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\DevSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevSeederTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(): Lesson
    {
        return Lesson::create([
            'teacher_id' => User::factory()->create()->id,
            'topic' => 'French Revolution', 'subject' => 'history',
            'grade_level' => '8', 'status' => LessonStatus::Draft,
        ]);
    }

    public function test_seeds_scores_with_faithful_answer_snapshots_from_real_questions(): void
    {
        $lesson = $this->lesson();
        QuizQuestion::create([
            'lesson_id' => $lesson->id, 'order' => 1,
            'question' => 'When did it begin?', 'options' => ['1789', '1815'],
            'correct_index' => 0, 'asks_ahead' => false,
        ]);

        $made = app(DevSeeder::class)->seedResults($lesson, 5);

        $this->assertSame(5, $made);
        $this->assertSame(5, QuizScore::where('lesson_id', $lesson->id)->count());
        // Every answer snapshot carries the real correct text, and score = correct * 10.
        $score = QuizScore::where('lesson_id', $lesson->id)->with('answers')->first();
        $this->assertSame('1789', $score->answers->first()->correct_text);
        $this->assertSame($score->correct * 10, $score->score);
        // was_correct snapshot must agree with whether chosen matched correct.
        foreach ($score->answers as $a) {
            $this->assertSame($a->was_correct, $a->chosen_text === $a->correct_text);
        }
    }

    public function test_synthesizes_questions_when_lesson_has_none(): void
    {
        $lesson = $this->lesson();

        $made = app(DevSeeder::class)->seedResults($lesson, 3);

        $this->assertSame(3, $made);
        $this->assertGreaterThan(0, QuizAnswer::whereIn(
            'quiz_score_id', QuizScore::where('lesson_id', $lesson->id)->pluck('id'),
        )->count());
    }

    public function test_attach_class_creates_members_and_links_scores(): void
    {
        $lesson = $this->lesson();

        app(DevSeeder::class)->seedResults($lesson, 4, attachClass: true);

        $this->assertTrue($lesson->classrooms()->exists());
        $this->assertSame(4, QuizScore::where('lesson_id', $lesson->id)
            ->whereNotNull('classroom_member_id')->count());
    }

    public function test_clear_removes_scores_and_cascades_answers(): void
    {
        $lesson = $this->lesson();
        app(DevSeeder::class)->seedResults($lesson, 4);

        $removed = app(DevSeeder::class)->clearResults($lesson);

        $this->assertSame(4, $removed);
        $this->assertSame(0, QuizScore::where('lesson_id', $lesson->id)->count());
        $this->assertSame(0, QuizAnswer::count());
    }
}
