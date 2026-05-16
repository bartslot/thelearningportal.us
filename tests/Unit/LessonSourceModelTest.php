<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonSourceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_lesson_and_stores_extracted_text(): void
    {
        $teacher = User::factory()->create();
        $lesson  = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'Roman Empire',
            'subject'     => 'history',
            'grade_level' => '7th grade',
        ]);

        $source = LessonSource::create([
            'lesson_id'      => $lesson->id,
            'kind'           => 'pdf',
            'file_path'      => "lessons/{$lesson->id}/source.pdf",
            'extracted_text' => 'In 27 BC, Augustus founded the Roman Empire.',
        ]);

        $this->assertInstanceOf(Lesson::class, $source->lesson);
        $this->assertStringContainsString('Augustus', $source->extracted_text);
    }
}
