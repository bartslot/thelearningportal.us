<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BuildLessonOutlineTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'Napoleonic Campaigns',
            'subject'     => 'history',
            'grade_level' => '9th grade',
            'image_style' => 'painted',
            'source_mode' => 'wikipedia',
            'status'      => LessonStatus::SourceReady,
        ]);
        LessonSource::create([
            'lesson_id'      => $this->lesson->id,
            'kind'           => 'wikipedia',
            'extracted_text' => 'Napoleon Bonaparte was a French statesman…',
        ]);
    }

    public function test_writes_scene_rows_from_llm_json_and_dispatches_batch(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title'        => 'Napoleon: Rise and Fall',
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris',  'beat' => 'intro',    'image_prompt_seed' => 'Paris dusk 1810'],
                    ['order' => 2, 'kind' => 'narration', 'year' => '1812', 'location' => 'Moscow', 'beat' => 'invasion', 'image_prompt_seed' => 'Burning Moscow'],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->lesson->refresh();
        $this->assertSame('Napoleon: Rise and Fall', $this->lesson->title);
        $this->assertSame(LessonStatus::ScenesGenerating, $this->lesson->status);
        $this->assertSame(2, Scene::count());

        Bus::assertBatched(function ($batch): bool {
            return $batch->jobs->count() === 6;
        });
    }

    public function test_drops_game_scenes_when_games_are_disabled(): void
    {
        $this->lesson->update(['include_game' => false]);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title'        => 'Napoleon: Rise and Fall',
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris',    'beat' => 'intro',  'image_prompt_seed' => 'Paris dusk'],
                    ['order' => 2, 'kind' => 'game',      'year' => '1812', 'location' => 'Moscow',   'beat' => 'quiz',   'image_prompt_seed' => 'Quiz'],
                    ['order' => 3, 'kind' => 'narration', 'year' => '1815', 'location' => 'Waterloo', 'beat' => 'climax', 'image_prompt_seed' => 'Waterloo'],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(0, Scene::where('kind', 'game')->count(), 'Game scenes must be stripped when include_game is off');
        $this->assertSame(2, Scene::where('kind', 'narration')->count());
    }

    public function test_marks_lesson_failed_and_writes_error_message_on_llm_exception(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->andThrow(new \RuntimeException('OpenAI down'));
        });

        try {
            (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));
        } catch (\Throwable) {
            // expected
        }

        $this->lesson->refresh();
        $this->assertSame(LessonStatus::Failed, $this->lesson->status);
        $this->assertStringContainsString('OpenAI down', (string) $this->lesson->error_message);
    }
}
