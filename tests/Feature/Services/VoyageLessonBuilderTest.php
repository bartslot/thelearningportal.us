<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Scene;
use App\Models\User;
use App\Services\VoyageLessonBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The builder is the reusable core behind both the artisan command and the wizard's "Voyage" type,
 * turning a catalog voyage (voyages.json legs/fleet + <id>-tour.json copy) into a playable lesson.
 */
class VoyageLessonBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): VoyageLessonBuilder
    {
        // No Cloudinary in tests → remote images fall back to their source URL, no network needed
        // for voyages whose tour pack uses local image names (e.g. tasman).
        config(['services.cloudinary.url' => null]);

        return app(VoyageLessonBuilder::class);
    }

    public function test_playable_voyages_lists_only_voyages_that_have_legs(): void
    {
        $playable = $this->builder()->playableVoyages();

        // Columbus + Tasman have legs authored; the rest are waypoint-only (not lesson-ready yet).
        $this->assertArrayHasKey('columbus-1492', $playable);
        $this->assertArrayHasKey('tasman-1642', $playable);
        $this->assertArrayNotHasKey('gama-1497', $playable, 'da Gama has no legs → not offered');
        $this->assertArrayNotHasKey('magellan-1519', $playable);
    }

    public function test_is_playable_reflects_leg_presence(): void
    {
        $b = $this->builder();
        $this->assertTrue($b->isPlayable('columbus-1492'));
        $this->assertFalse($b->isPlayable('cook-1768'));
    }

    public function test_build_creates_voyage_legs_wrapped_by_intro_and_outro_story_scenes(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $lesson = $this->builder()->build('columbus-1492', $teacher);

        $this->assertSame('voyage', $lesson->game_type);
        $this->assertSame('columbus-1492', $lesson->game_config['voyage']);

        // The catalog's `unknown` default is seeded into the EDITABLE voyage_fog (not baked into the
        // map), so the teacher can reshape/erase it. Columbus ships one region by default.
        $this->assertNotEmpty($lesson->game_config['voyage_fog'] ?? []);
        $this->assertIsArray($lesson->game_config['voyage_fog'][0]);

        $scenes = $lesson->scenes()->orderBy('order')->get();
        // intro (gallery) + 4 voyage legs + outro (gallery).
        $this->assertCount(6, $scenes);
        $this->assertSame(['gallery', 'voyage', 'voyage', 'voyage', 'voyage', 'gallery'], $scenes->pluck('kind')->all());
        $scenes->each(fn (Scene $s) => $this->assertSame('ready', $s->status));

        // A "real start": the intro is a standalone story slide (not a voyage map), with its own copy.
        $intro = $scenes->first();
        $this->assertSame('Op zoek naar geld voor een droom', $intro->config['title']);
        $this->assertNotEmpty($intro->config['story']);
        $this->assertArrayNotHasKey('leg', $intro->config, 'the intro is not a voyage leg');

        // The voyage legs carry their leg index; only the first plays the space fly-in.
        $legs = $scenes->slice(1, 4)->values();
        $legs->each(fn (Scene $s, int $i) => $this->assertSame($i, $s->config['leg']));
        $this->assertTrue($legs[0]->config['intro']);
        $this->assertFalse($legs[1]->config['intro']);
        $this->assertSame('Canarische Eilanden', $legs[0]->config['gallery']['title']);

        // A "real end": the outro is a standalone story slide.
        $outro = $scenes->last();
        $this->assertSame('Een held én een omstreden man', $outro->config['title']);
    }

    public function test_build_is_idempotent_and_refreshes_the_same_lesson(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $b = $this->builder();

        $first = $b->build('columbus-1492', $teacher);
        $second = $b->build('columbus-1492', $teacher);

        $this->assertSame($first->id, $second->id, 'rebuilds the same lesson, not a duplicate');
        $this->assertSame(6, $second->scenes()->count(), 'scenes rebuilt cleanly (not doubled)');
    }

    public function test_build_rejects_a_voyage_without_legs(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->expectException(RuntimeException::class);
        $this->builder()->build('gama-1497', $teacher);
    }
}
