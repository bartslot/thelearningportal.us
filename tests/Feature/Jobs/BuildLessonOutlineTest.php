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

    public function test_writes_scene_rows_from_llm_json_and_dispatches_script_job(): void
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

        // The whole-lesson script job owns the per-scene asset fan-out now.
        Bus::assertDispatched(\App\Jobs\GenerateLessonScript::class);
        Bus::assertNothingBatched();
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
        // The mid-list game brief was removed and the remaining scenes re-indexed to contiguous orders.
        $this->assertSame([1, 2], Scene::where('kind', 'narration')->orderBy('order')->pluck('order')->all());
    }

    public function test_keeps_game_scenes_when_games_are_enabled(): void
    {
        $this->lesson->update(['include_game' => true, 'game_type' => 'quiz']);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title'        => 'Napoleon',
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris',  'beat' => 'intro', 'image_prompt_seed' => 'Paris'],
                    ['order' => 2, 'kind' => 'game',      'year' => '1812', 'location' => 'Moscow', 'beat' => 'quiz',  'image_prompt_seed' => 'Quiz', 'game_segment_index' => 1],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(1, Scene::where('kind', 'game')->count(), 'Game scenes must be kept when include_game is on');
    }

    public function test_catalog_story_grounds_the_source_and_overrides_objectives(): void
    {
        $story = \App\Models\Story::create([
            'slug' => 'napoleon-russia',
            'title' => 'Napoleon in Russia',
            'status' => 'published',
            'learning_objectives' => [
                ['id' => 'LO1', 'text' => 'Explain why the invasion of Russia failed.', 'bloom' => 'understand'],
            ],
        ]);
        \App\Models\StorySource::create([
            'story_id' => $story->id,
            'origin' => 'gutenberg',
            'title' => 'Famous Men of Modern Times — Napoleon',
            'excerpt' => 'In 1812 Napoleon led the Grande Armée into Russia…',
        ]);
        $this->lesson->update(['story_id' => $story->id]);
        $this->lesson->source->update(['extracted_text' => '']);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title' => 'Napoleon in Russia',
                'learning_objectives' => [
                    ['id' => 'LOX', 'text' => 'Model-invented objective (must be overridden).'],
                ],
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'beat' => 'march'],
                    ['order' => 2, 'kind' => 'narration', 'beat' => 'winter'],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->lesson->refresh();
        // Source text came from the story excerpt, not Wikipedia.
        $this->assertStringContainsString('Grande Armée', (string) $this->lesson->source->extracted_text);
        // Curated objectives override whatever the model emitted.
        $this->assertSame('LO1', $this->lesson->outline['learning_objectives'][0]['id']);
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
