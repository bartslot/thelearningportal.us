<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\LessonStatus;
use App\Jobs\GenerateLessonScript;
use App\Jobs\GenerateSceneAudio;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class GenerateLessonScriptTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    private const PASSING_CRITIQUE = [
        'causality' => 4, 'character_agency' => 4, 'objective_coverage' => 4,
        'grade_fit' => 4, 'variety' => 4, 'issues' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Napoleonic Campaigns',
            'subject' => 'history',
            'grade_level' => '9th grade',
            'status' => LessonStatus::ScenesGenerating,
            'outline' => [
                'learning_objectives' => [
                    ['id' => 'LO1', 'text' => 'Explain why Napoleon invaded Russia.', 'bloom' => 'understand'],
                ],
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'beat' => 'intro'],
                    ['order' => 2, 'kind' => 'narration', 'beat' => 'invasion'],
                ],
            ],
        ]);
        LessonSource::create([
            'lesson_id' => $this->lesson->id,
            'kind' => 'wikipedia',
            'extracted_text' => 'Napoleon Bonaparte was a French statesman…',
        ]);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'map', 'status' => 'ready']);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration', 'status' => 'pending']);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration', 'status' => 'pending']);
    }

    public function test_writes_all_scene_scripts_and_batches_asset_jobs(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->twice()->andReturn(
                ['scenes' => [
                    ['order' => 2, 'script' => 'Napoleon paces his tent. "We march on Moscow," he says.'],
                    ['order' => 3, 'script' => 'But the Russian winter has other plans.'],
                ]],
                self::PASSING_CRITIQUE,
            );
        });

        Bus::fake();

        (new GenerateLessonScript($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertStringContainsString('Moscow', (string) Scene::where('order', 2)->first()->script_segment);
        $this->assertStringContainsString('winter', (string) Scene::where('order', 3)->first()->script_segment);
        $this->assertNull(Scene::where('kind', 'map')->first()->script_segment, 'Map blocks get no narration');

        // 2 visual (multi-shot) + 2 audio jobs; the map block gets none.
        Bus::assertBatched(function ($batch): bool {
            $visuals = collect($batch->jobs)->whereInstanceOf(\App\Jobs\GenerateSceneShots::class)->count();
            $audio = collect($batch->jobs)->whereInstanceOf(GenerateSceneAudio::class)->count();

            return $visuals === 2 && $audio === 2;
        });

        $critique = $this->lesson->fresh()->outline['critique'] ?? null;
        $this->assertSame(4, $critique['causality'] ?? null, 'Critique scores must be persisted to the outline');
    }

    public function test_regenerates_once_when_critique_fails(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->times(4)->andReturn(
                // Draft 1
                ['scenes' => [
                    ['order' => 2, 'script' => 'You are standing in a field.'],
                    ['order' => 3, 'script' => 'You are standing in another field.'],
                ]],
                // Critique 1: fails character_agency
                ['causality' => 4, 'character_agency' => 1, 'objective_coverage' => 4,
                    'grade_fit' => 4, 'variety' => 2, 'issues' => ['Scene 2: nobody acts']],
                // Draft 2 (revised)
                ['scenes' => [
                    ['order' => 2, 'script' => 'Napoleon signs the order to invade.'],
                    ['order' => 3, 'script' => 'Therefore the Grande Armée crosses the Niemen.'],
                ]],
                // Critique 2: passes
                self::PASSING_CRITIQUE,
            );
        });

        Bus::fake();

        (new GenerateLessonScript($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertStringContainsString('Napoleon signs', (string) Scene::where('order', 2)->first()->script_segment);
    }

    public function test_skips_image_job_for_scene_with_preassigned_hero_image(): void
    {
        Scene::where('order', 2)->first()->update(['image_path' => 'lessons/1/hero.jpg']);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->twice()->andReturn(
                ['scenes' => [
                    ['order' => 2, 'script' => 'Napoleon decides.'],
                    ['order' => 3, 'script' => 'Therefore war.'],
                ]],
                self::PASSING_CRITIQUE,
            );
        });

        Bus::fake();

        (new GenerateLessonScript($this->lesson->id))->handle(app(OpenAiLlmService::class));

        Bus::assertBatched(function ($batch): bool {
            return collect($batch->jobs)->whereInstanceOf(\App\Jobs\GenerateSceneShots::class)->count() === 1
                && collect($batch->jobs)->whereInstanceOf(GenerateSceneAudio::class)->count() === 2;
        });
    }

    public function test_marks_lesson_failed_when_scenes_stay_unscripted_after_retry(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            // Both attempts skip scene 3.
            $mock->shouldReceive('json')->twice()->andReturn(
                ['scenes' => [['order' => 2, 'script' => 'Only one scene.']]],
            );
        });

        Bus::fake();

        try {
            (new GenerateLessonScript($this->lesson->id))->handle(app(OpenAiLlmService::class));
            $this->fail('Expected a RuntimeException for the unscripted scene.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(LessonStatus::Failed, $this->lesson->fresh()->status);
        Bus::assertNothingBatched();
    }
}
