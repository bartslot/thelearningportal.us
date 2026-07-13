<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Jobs\GenerateLessonScript;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Jobs\GenerateSceneShots;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\Story;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * E3b Segment B: a lesson grounded in a story WITH an asset pack makes ZERO
 * image-generation calls — narration shots are assembled from the pack.
 */
class StoryPackLessonTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    private const PASSING_CRITIQUE = [
        'causality' => 4, 'character_agency' => 4, 'objective_coverage' => 4,
        'grade_fit' => 4, 'variety' => 4, 'issues' => [],
    ];

    private const SCRIPTS = ['scenes' => [
        ['order' => 2, 'script' => 'Horatius steps onto the bridge alone.'],
        ['order' => 3, 'script' => 'But behind him, Rome tears the bridge down.'],
    ]];

    private const BG_RIVER = 'stories/assets/test/bg_river_bridge.png';

    private const BG_SQUARE = 'stories/assets/test/bg_city_square.png';

    private const BG_CAMP = 'stories/assets/test/bg_night_camp.png';

    private const HERO_CALM = 'stories/assets/test/hero_standing_calm.png';

    private const HERO_POINTING = 'stories/assets/test/hero_pointing.png';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Horatius at the Bridge',
            'subject' => 'history',
            'grade_level' => '7th grade',
            'status' => LessonStatus::ScenesGenerating,
            'story_id' => $this->makeStory($this->packAssets())->id,
            'outline' => [
                'learning_objectives' => [
                    ['id' => 'LO1', 'text' => 'Explain why the bridge mattered.', 'bloom' => 'understand'],
                ],
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'beat' => 'intro'],
                    ['order' => 2, 'kind' => 'narration', 'beat' => 'last stand'],
                ],
            ],
        ]);
        LessonSource::create([
            'lesson_id' => $this->lesson->id,
            'kind' => 'wikipedia',
            'extracted_text' => 'Horatius Cocles defended the Pons Sublicius…',
        ]);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'map', 'status' => 'ready']);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'narration', 'status' => 'pending']);
        Scene::create(['lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration', 'status' => 'pending']);
    }

    private function makeStory(?array $assets): Story
    {
        return Story::create([
            'slug' => 'horatius-at-the-bridge-'.uniqid(),
            'title' => 'Horatius at the Bridge',
            'subtitle' => 'One man holds back an army',
            'protagonist_name' => 'Horatius Cocles',
            'era_start' => -509,
            'region' => 'Rome',
            'status' => 'draft',
            'assets' => $assets,
        ]);
    }

    /** A Segment-A-shaped asset pack: 3 tagged backgrounds + 2 hero poses. */
    private function packAssets(): array
    {
        return [
            'backgrounds' => [
                ['tag' => 'river_bridge', 'lighting' => 'day', 'image_path' => self::BG_RIVER],
                ['tag' => 'city_square', 'lighting' => 'dusk', 'image_path' => self::BG_SQUARE],
                ['tag' => 'night_camp', 'lighting' => 'night', 'image_path' => self::BG_CAMP],
            ],
            'hero' => [
                ['pose' => 'standing_calm', 'image_path' => self::HERO_CALM],
                ['pose' => 'pointing', 'image_path' => self::HERO_POINTING],
            ],
            'generated_at' => now()->toIso8601String(),
            'style' => 'ink',
        ];
    }

    /** Mock the 3 LLM calls of a pack run: scripts, critique, then the pack mapping. */
    private function mockLlm(mixed $mapping): void
    {
        $call = 0;
        $this->mock(OpenAiLlmService::class, function ($mock) use (&$call, $mapping): void {
            $mock->shouldReceive('json')->times(3)->andReturnUsing(function () use (&$call, $mapping) {
                $call++;
                if ($call === 1) {
                    return self::SCRIPTS;
                }
                if ($call === 2) {
                    return self::PASSING_CRITIQUE;
                }
                if ($mapping instanceof \Throwable) {
                    throw $mapping;
                }

                return $mapping;
            });
        });
    }

    private function runJob(): void
    {
        (new GenerateLessonScript($this->lesson->id))->handle(app(OpenAiLlmService::class));
    }

    public function test_packed_story_lesson_dispatches_zero_image_jobs_and_assembles_shots_from_pack(): void
    {
        $this->mockLlm(['scenes' => [
            ['order' => 2, 'bg_tag' => 'city_square', 'hero_pose' => 'pointing'],
            ['order' => 3, 'bg_tag' => 'night_camp', 'hero_pose' => null],
        ]]);
        Bus::fake();

        $this->runJob();

        Bus::assertBatched(function ($batch): bool {
            $jobs = collect($batch->jobs);

            return $jobs->whereInstanceOf(GenerateSceneShots::class)->isEmpty()
                && $jobs->whereInstanceOf(GenerateSceneImage::class)->isEmpty()
                && $jobs->whereInstanceOf(GenerateSceneAudio::class)->count() === 2;
        });

        $scene2 = Scene::where('order', 2)->first();
        $this->assertSame([[
            'order' => 0,
            'image_path' => self::BG_SQUARE,
            'bg_path' => self::BG_SQUARE,
            'hero_path' => self::HERO_POINTING,
            'anchor_sentence' => null,
        ]], $scene2->shots);
        $this->assertSame(self::BG_SQUARE, $scene2->image_path, 'image_path mirrors bg for cards/PDF/fallback');
        $this->assertSame('done', $scene2->upscale_status, 'Pack images are full-res — Upscayl must not run');

        $scene3 = Scene::where('order', 3)->first();
        $this->assertSame(self::BG_CAMP, $scene3->shots[0]['bg_path']);
        $this->assertNull($scene3->shots[0]['hero_path'], 'No hero when the LLM assigns none');

        $this->assertNull(Scene::where('kind', 'map')->first()->shots, 'Map blocks stay untouched');
    }

    public function test_garbage_mapping_falls_back_to_whitelisted_round_robin(): void
    {
        $this->mockLlm(['scenes' => [
            ['order' => 2, 'bg_tag' => 'bogus_tag', 'hero_pose' => 'bogus_pose'],
            'not-even-an-array',
        ]]);
        Bus::fake();

        $this->runJob();

        // Unknown tag → round-robin (scene index 0 → bg[0]); unknown pose → no hero.
        $scene2 = Scene::where('order', 2)->first();
        $this->assertSame(self::BG_RIVER, $scene2->shots[0]['bg_path']);
        $this->assertNull($scene2->shots[0]['hero_path']);

        // Missing entry entirely → round-robin (scene index 1 → bg[1]).
        $scene3 = Scene::where('order', 3)->first();
        $this->assertSame(self::BG_SQUARE, $scene3->shots[0]['bg_path']);

        Bus::assertBatched(fn ($batch): bool => collect($batch->jobs)
            ->whereInstanceOf(GenerateSceneShots::class)->isEmpty());
    }

    public function test_mapping_llm_throw_never_fails_the_pipeline(): void
    {
        $this->mockLlm(new \RuntimeException('LLM down'));
        Bus::fake();

        $this->runJob();

        $this->assertNotSame(LessonStatus::Failed, $this->lesson->fresh()->status);
        $this->assertSame(self::BG_RIVER, Scene::where('order', 2)->first()->shots[0]['bg_path']);
        $this->assertSame(self::BG_SQUARE, Scene::where('order', 3)->first()->shots[0]['bg_path']);
        Bus::assertBatched(fn ($batch): bool => collect($batch->jobs)
            ->whereInstanceOf(GenerateSceneAudio::class)->count() === 2);
    }

    public function test_story_without_assets_keeps_live_image_generation_path(): void
    {
        $this->lesson->update(['story_id' => $this->makeStory(null)->id]);

        // Only 2 LLM calls now: scripts + critique — no pack mapping.
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->twice()->andReturn(self::SCRIPTS, self::PASSING_CRITIQUE);
        });
        Bus::fake();

        $this->runJob();

        Bus::assertBatched(function ($batch): bool {
            $jobs = collect($batch->jobs);

            return $jobs->whereInstanceOf(GenerateSceneShots::class)->count() === 2
                && $jobs->whereInstanceOf(GenerateSceneAudio::class)->count() === 2;
        });
        $this->assertNull(Scene::where('order', 2)->first()->shots);
    }

    public function test_player_payload_serializes_bg_and_hero_urls_for_pack_shots(): void
    {
        $this->lesson->update(['status' => LessonStatus::Published]);
        Scene::where('order', 2)->first()->update([
            'script_segment' => 'Horatius steps onto the bridge alone.',
            'shots' => [[
                'order' => 0,
                'image_path' => self::BG_SQUARE,
                'bg_path' => self::BG_SQUARE,
                'hero_path' => self::HERO_POINTING,
                'anchor_sentence' => null,
            ]],
            'image_path' => self::BG_SQUARE,
            'upscale_status' => 'done',
            'status' => 'ready',
        ]);

        $response = $this->get(route('lesson.play', ['lessonCode' => $this->lesson->fresh()->lesson_code]));

        $response->assertOk();
        $response->assertSee('bg_url', false);
        $response->assertSee('hero_url', false);
        $response->assertSee('bg_city_square.png', false);
        $response->assertSee('hero_pointing.png', false);
    }
}
