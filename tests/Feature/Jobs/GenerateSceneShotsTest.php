<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateSceneShots;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\FigureAppearanceService;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSceneShotsTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = 'Napoleon signs the invasion order. But the Russian winter is coming. '
        .'Therefore the Grande Armée begins its long march east.';

    private Scene $scene;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['lessons.shot_grid' => '2x2']);

        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'Napoleon', 'subject' => 'history', 'grade_level' => '8',
            'outline' => ['scene_briefs' => [
                ['order' => 1, 'beat' => 'invasion', 'visualEvidence' => ['1812 uniforms'], 'avoidList' => ['cars']],
            ]],
        ]);
        $this->scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => self::SCRIPT, 'status' => 'generating',
        ]);

        // FigureAppearanceService is final (unmockable); with no protagonist on the lesson
        // its describe(null, null) short-circuits to null without any network call.
    }

    private function gridPngBytes(int $width = 1024, int $height = 1024): string
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_generates_shots_from_one_grid_image_with_verbatim_anchors(): void
    {
        $storyboard = ['shots' => [
            ['order' => 1, 'anchor_sentence' => 'Napoleon signs the invasion order.', 'composition' => 'establishing wide', 'description' => 'A war tent at dawn'],
            ['order' => 2, 'anchor_sentence' => 'But the Russian winter is coming', 'composition' => 'close-up of an object or detail', 'description' => 'Frost creeping over a map'],
            ['order' => 3, 'anchor_sentence' => 'the Grande Armée begins its long march east', 'composition' => 'high vantage overview', 'description' => 'A column of soldiers on a snowy road'],
            ['order' => 4, 'anchor_sentence' => 'THIS ANCHOR DOES NOT EXIST IN THE SCRIPT', 'composition' => 'dramatic low angle', 'description' => 'Invalid shot'],
        ]];

        $this->mock(OpenAiLlmService::class, function ($mock) use ($storyboard): void {
            // Storyboard (3/4 anchors valid) → retry (same) → historical-visuals validation.
            $mock->shouldReceive('json')->times(3)->andReturn(
                $storyboard,
                $storyboard,
                ['recommendedScene' => 'Napoleonic war camp, 1812', 'accurateVisuals' => ['1812 uniforms'], 'anachronismsToAvoid' => ['cars']],
            );
        });
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock
            ->shouldReceive('generateGridBytesFromPrompt')->once()->andReturn($this->gridPngBytes()));

        (new GenerateSceneShots($this->scene->id))->handle(
            app(OpenAiImageService::class), app(OpenAiLlmService::class), app(FigureAppearanceService::class),
        );

        $scene = $this->scene->fresh();
        // The fake anchor was dropped; the 3 verbatim ones survived.
        $this->assertCount(3, $scene->shots);
        $this->assertSame('Napoleon signs the invasion order.', $scene->shots[0]['anchor_sentence']);
        $this->assertSame($scene->shots[0]['image_path'], $scene->image_path, 'First shot doubles as the scene image');

        foreach ($scene->shots as $shot) {
            Storage::disk('public')->assertExists($shot['image_path']);
        }
    }

    public function test_falls_back_to_single_image_job_when_shots_are_disabled(): void
    {
        config(['lessons.shot_grid' => null]);
        \Illuminate\Support\Facades\Bus::fake();

        (new GenerateSceneShots($this->scene->id))->handle(
            app(OpenAiImageService::class), app(OpenAiLlmService::class), app(FigureAppearanceService::class),
        );

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\GenerateSceneImage::class);
        $this->assertNull($this->scene->fresh()->shots);
    }

    public function test_fails_the_scene_when_storyboard_anchors_never_match(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->twice()->andReturn(
                ['shots' => [
                    ['order' => 1, 'anchor_sentence' => 'nothing from the script', 'composition' => 'wide', 'description' => 'x'],
                ]],
            );
        });
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('generateGridBytesFromPrompt')->never());

        try {
            (new GenerateSceneShots($this->scene->id))->handle(
                app(OpenAiImageService::class), app(OpenAiLlmService::class), app(FigureAppearanceService::class),
            );
            $this->fail('Expected storyboard failure');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('failed', $this->scene->fresh()->status);
    }

    public function test_anchor_matching_is_whitespace_and_case_tolerant_but_verbatim(): void
    {
        $this->assertTrue(GenerateSceneShots::anchorMatches(self::SCRIPT, "but the russian\nwinter is coming"));
        $this->assertFalse(GenerateSceneShots::anchorMatches(self::SCRIPT, 'Napoleon signed the invasion order'), 'Paraphrase must not match');
        $this->assertFalse(GenerateSceneShots::anchorMatches(self::SCRIPT, 'march'), 'Too-short anchors are rejected');
    }
}
