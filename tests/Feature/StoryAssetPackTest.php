<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\StoryReview;
use App\Models\Story;
use App\Models\User;
use App\Services\OpenAiImageService;
use App\Services\OpenAiLlmService;
use App\Services\StoryAssetPackGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StoryAssetPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeStory(array $overrides = []): Story
    {
        return Story::create(array_merge([
            'slug' => 'horatius-at-the-bridge',
            'title' => 'Horatius at the Bridge',
            'subtitle' => 'One man holds back an army',
            'protagonist_name' => 'Horatius Cocles',
            'era_start' => -509,
            'region' => 'Rome',
            'status' => 'draft',
            'learning_objectives' => [['id' => 'LO1', 'text' => 'Explain why the bridge mattered', 'bloom' => 'understand']],
            'key_facts' => [['fact' => 'The Sublician bridge was Rome\'s only Tiber crossing', 'source_ref' => 'the bridge']],
        ], $overrides));
    }

    /** A well-formed LLM plan: N backgrounds, 4 hero poses. */
    private function llmPlan(int $backgrounds = 6): array
    {
        $plan = ['backgrounds' => [], 'hero_poses' => []];
        $lighting = ['day', 'dusk', 'night'];
        for ($i = 1; $i <= $backgrounds; $i++) {
            $plan['backgrounds'][] = [
                'tag' => 'scene_setting_'.$i,
                'description' => "Setting {$i}: a riverside vista of ancient Rome",
                'lighting' => $lighting[$i % 3],
            ];
        }
        foreach (['standing_calm', 'pointing', 'walking', 'arms_raised'] as $pose) {
            $plan['hero_poses'][] = ['pose' => $pose, 'description' => "Hero {$pose} with resolute body language"];
        }

        return $plan;
    }

    private function mockLlm(array $plan): void
    {
        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('json')->once()->andReturn($plan));
    }

    // ── Generator ────────────────────────────────────────────────────────────

    public function test_generate_persists_backgrounds_and_hero_poses(): void
    {
        $story = $this->makeStory();
        $this->mockLlm($this->llmPlan(6));

        $this->mock(OpenAiImageService::class, function ($mock): void {
            $mock->shouldReceive('generateStillPngBytes')
                ->with(Mockery::type('string'), '1536x1024', false)
                ->times(6)->andReturn('fake-bg-png');
            $mock->shouldReceive('generateStillPngBytes')
                ->with(Mockery::type('string'), '1024x1536', true)
                ->times(4)->andReturn('fake-hero-png');
        });

        $assets = app(StoryAssetPackGenerator::class)->generate($story, 6);

        $this->assertCount(6, $assets['backgrounds']);
        $this->assertCount(4, $assets['hero']);
        $this->assertSame('ink', $assets['style']);
        $this->assertNotEmpty($assets['generated_at']);

        foreach ($assets['backgrounds'] as $background) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $background['tag']);
            $this->assertContains($background['lighting'], ['day', 'dusk', 'night']);
            Storage::disk('public')->assertExists($background['image_path']);
            $this->assertStringStartsWith("stories/{$story->id}/assets/", $background['image_path']);
        }
        foreach ($assets['hero'] as $pose) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $pose['pose']);
            Storage::disk('public')->assertExists($pose['image_path']);
        }
    }

    public function test_llm_garbage_is_clamped_and_whitelisted(): void
    {
        $story = $this->makeStory();

        // 20 backgrounds with invalid lighting + 60-char shouty tags, 6 poses (cap is 5).
        $garbage = ['backgrounds' => [], 'hero_poses' => []];
        for ($i = 1; $i <= 20; $i++) {
            $garbage['backgrounds'][] = [
                'tag' => str_repeat('The Grand City! ', 4).$i, // ~60+ chars, spaces, punctuation
                'description' => "Setting {$i} of the city",
                'lighting' => 'noon', // not in the whitelist
            ];
        }
        for ($i = 1; $i <= 6; $i++) {
            $garbage['hero_poses'][] = ['pose' => 'POSE NUMBER '.$i.'!!!', 'description' => "Pose {$i}"];
        }
        $this->mockLlm($garbage);

        // 6 backgrounds (requested) + 5 hero poses (clamp ceiling) = 11 images.
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock
            ->shouldReceive('generateStillPngBytes')->times(11)->andReturn('fake-png'));

        $assets = app(StoryAssetPackGenerator::class)->generate($story, 6);

        $this->assertCount(6, $assets['backgrounds'], 'excess backgrounds clamped to requested count');
        $this->assertCount(5, $assets['hero'], 'hero poses capped at 5');

        foreach ($assets['backgrounds'] as $background) {
            $this->assertSame('day', $background['lighting'], 'invalid lighting whitelisted to day');
            $this->assertLessThanOrEqual(32, strlen($background['tag']));
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $background['tag']);
        }
        foreach ($assets['hero'] as $pose) {
            $this->assertLessThanOrEqual(32, strlen($pose['pose']));
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $pose['pose']);
        }
    }

    public function test_backgrounds_count_is_clamped_to_4_to_8(): void
    {
        $story = $this->makeStory();
        $this->mockLlm($this->llmPlan(8));

        // Asked for 20 → clamped to 8 backgrounds + 4 poses = 12 images.
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock
            ->shouldReceive('generateStillPngBytes')->times(12)->andReturn('fake-png'));

        $assets = app(StoryAssetPackGenerator::class)->generate($story, 20);

        $this->assertCount(8, $assets['backgrounds']);
    }

    public function test_image_failure_mid_pack_leaves_no_partial_files_and_assets_untouched(): void
    {
        $story = $this->makeStory();
        $this->mockLlm($this->llmPlan(6));

        $calls = 0;
        $this->mock(OpenAiImageService::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('generateStillPngBytes')->andReturnUsing(function () use (&$calls): string {
                if (++$calls > 2) {
                    throw new RuntimeException('Image API returned 500: boom');
                }

                return 'fake-png';
            });
        });

        try {
            app(StoryAssetPackGenerator::class)->generate($story, 6);
            $this->fail('generate() should throw when an image fails mid-pack');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Asset pack generation failed', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles("stories/{$story->id}"), 'no half-pack files remain');
        $this->assertNull($story->fresh()->assets, 'story.assets stays untouched on failure');
    }

    // ── Command ──────────────────────────────────────────────────────────────

    public function test_command_refuses_overwrite_without_force(): void
    {
        $story = $this->makeStory(['assets' => [
            'backgrounds' => [['tag' => 'old', 'lighting' => 'day', 'image_path' => 'stories/x/assets/old/bg_old.png']],
            'hero' => [], 'generated_at' => '2026-01-01T00:00:00+00:00', 'style' => 'ink',
        ]]);

        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock->shouldReceive('json')->never());
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('generateStillPngBytes')->never());

        $this->artisan('story:pack', ['story' => $story->slug])
            ->expectsOutputToContain('already has an asset pack')
            ->assertExitCode(1);

        $this->assertSame('old', $story->fresh()->assets['backgrounds'][0]['tag']);
    }

    public function test_command_force_regenerates_and_persists(): void
    {
        $story = $this->makeStory(['assets' => [
            'backgrounds' => [['tag' => 'old', 'lighting' => 'day', 'image_path' => 'stories/x/assets/old/bg_old.png']],
            'hero' => [], 'generated_at' => '2026-01-01T00:00:00+00:00', 'style' => 'ink',
        ]]);

        $this->mockLlm($this->llmPlan(6));
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock
            ->shouldReceive('generateStillPngBytes')->times(10)->andReturn('fake-png'));

        $this->artisan('story:pack', ['story' => (string) $story->id, '--force' => true])
            ->assertExitCode(0);

        $fresh = $story->fresh();
        $this->assertCount(6, $fresh->assets['backgrounds']);
        $this->assertCount(4, $fresh->assets['hero']);
        $this->assertNotSame('old', $fresh->assets['backgrounds'][0]['tag']);
    }

    // ── StoryReview UI ───────────────────────────────────────────────────────

    public function test_story_review_shows_pack_gallery_when_assets_present(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $story = $this->makeStory(['assets' => [
            'backgrounds' => [['tag' => 'city_square', 'lighting' => 'dusk', 'image_path' => 'stories/1/assets/b1/bg_city_square.png']],
            'hero' => [['pose' => 'standing_calm', 'image_path' => 'stories/1/assets/b1/hero_standing_calm.png']],
            'generated_at' => '2026-07-13T00:00:00+00:00', 'style' => 'ink',
        ]]);

        Livewire::actingAs($admin)
            ->test(StoryReview::class)
            ->call('toggle', $story->id)
            ->assertSee('city_square')
            ->assertSee('dusk')
            ->assertSee('standing_calm')
            ->assertSee('Regenerate pack');
    }

    public function test_story_review_shows_generate_button_when_no_assets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $story = $this->makeStory();

        Livewire::actingAs($admin)
            ->test(StoryReview::class)
            ->call('toggle', $story->id)
            ->assertSee('Generate asset pack')
            ->assertDontSee('Regenerate pack');
    }

    public function test_generate_pack_action_persists_assets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $story = $this->makeStory();

        $this->mockLlm($this->llmPlan(6));
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock
            ->shouldReceive('generateStillPngBytes')->times(10)->andReturn('fake-png'));

        Livewire::actingAs($admin)
            ->test(StoryReview::class)
            ->call('generatePack', $story->id)
            ->assertHasNoErrors();

        $this->assertCount(6, $story->fresh()->assets['backgrounds']);
    }

    public function test_generate_pack_without_force_is_a_noop_when_pack_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $story = $this->makeStory(['assets' => [
            'backgrounds' => [], 'hero' => [],
            'generated_at' => '2026-01-01T00:00:00+00:00', 'style' => 'ink',
        ]]);

        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock->shouldReceive('json')->never());
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('generateStillPngBytes')->never());

        Livewire::actingAs($admin)
            ->test(StoryReview::class)
            ->call('generatePack', $story->id)
            ->assertHasNoErrors();

        $this->assertSame('2026-01-01T00:00:00+00:00', $story->fresh()->assets['generated_at']);
    }

    public function test_non_admin_cannot_generate_pack(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $story = $this->makeStory();

        $this->mock(OpenAiLlmService::class, fn ($mock) => $mock->shouldReceive('json')->never());
        $this->mock(OpenAiImageService::class, fn ($mock) => $mock->shouldReceive('generateStillPngBytes')->never());

        Livewire::actingAs($teacher)
            ->test(StoryReview::class)
            ->call('generatePack', $story->id)
            ->assertForbidden();

        $this->assertNull($story->fresh()->assets);
    }
}
