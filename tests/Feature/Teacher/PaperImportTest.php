<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Livewire\Teacher\LessonReport;
use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\QuizQuestion;
use App\Models\QuizScore;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PaperImportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create(['teacher_id' => $this->teacher->id, 'topic' => 'X', 'subject' => 'history', 'grade_level' => '8', 'status' => LessonStatus::Published]);
        QuizQuestion::create(['lesson_id' => $this->lesson->id, 'order' => 1, 'question' => 'Why X?', 'options' => ['Right', 'W1', 'W2', 'W3'], 'correct_index' => 0]);
        QuizQuestion::create(['lesson_id' => $this->lesson->id, 'order' => 2, 'question' => 'Why Z?', 'options' => ['W1', 'Right2', 'W2', 'W3'], 'correct_index' => 1]);

        $classroom = Classroom::create(['teacher_id' => $this->teacher->id, 'name' => '7B']);
        $this->lesson->classrooms()->attach($classroom->id, ['assigned_at' => now()]);
        ClassroomMember::create(['classroom_id' => $classroom->id, 'display_name' => 'Emma V.']);
    }

    public function test_upload_extracts_review_rows_and_confirm_imports_scores(): void
    {
        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('describeImage')->once()->andReturn(json_encode([
                'sheets' => [
                    ['name' => 'Emma Visser', 'answers' => ['A', 'B']],
                    ['name' => 'Onbekend Kind', 'answers' => ['B', null]],
                ],
            ])));

        $component = Livewire::actingAs($this->teacher)
            ->test(LessonReport::class, ['lesson' => $this->lesson])
            ->set('paperPhotos', [UploadedFile::fake()->image('sheets.jpg')])
            ->call('extractPaper');

        $rows = $component->get('paperRows');
        $this->assertCount(2, $rows);
        $this->assertSame('Emma V.', $rows[0]['matched_name'], 'Fuzzy-matched to roster');
        $this->assertNull($rows[1]['matched_name'], 'Unknown kid stays unmatched (amber)');

        // Teacher fixes row 2's name + missing answer, then confirms.
        $component->set('paperRows.1.matched_name', 'Daan K.')
            ->set('paperRows.1.answers.1', 'C')
            ->call('confirmPaperImport');

        $this->assertSame(2, QuizScore::where('source', 'paper')->count());
        $emma = QuizScore::where('nickname', 'Emma V.')->sole();
        $this->assertSame(2, $emma->correct);              // A + B were both correct options
        $this->assertNotNull($emma->classroom_member_id);
        $this->assertCount(2, $emma->answers);
        $this->assertNull($emma->answers[0]->response_ms); // paper has no timing
    }
}
