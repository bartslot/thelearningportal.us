<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_is_not_ready_when_audio_file_is_missing(): void
    {
        Storage::fake('public');

        $lesson = Lesson::factory()
            ->ready()
            ->withQuiz(1)
            ->create([
                'audio_path' => 'lessons/999/audio.mp3',
            ]);

        $this->assertSame(LessonStatus::Ready, $lesson->status);
        $this->assertFalse($lesson->hasGeneratedAssets());
        $this->assertFalse($lesson->isReady());
        $this->assertNull($lesson->audioUrl());
    }
}
