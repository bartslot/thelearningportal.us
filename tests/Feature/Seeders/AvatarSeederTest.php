<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Avatar;
use Database\Seeders\AvatarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvatarSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AvatarSeeder::class);
    }

    public function test_julian_is_seeded_as_the_default_active_narrator(): void
    {
        // The "default" narrator is the active avatar with the lowest sort_order — this is what
        // pre-selects in the lesson wizard, sorts first in the picker, and drives the narrator card.
        $default = Avatar::where('is_active', true)->orderBy('sort_order')->first();

        $this->assertNotNull($default);
        $this->assertSame('Julian', $default->name);
        $this->assertSame(0, $default->sort_order);
    }

    public function test_julian_uses_the_configured_elevenlabs_voice(): void
    {
        $julian = Avatar::where('name', 'Julian')->firstOrFail();

        $this->assertSame('elevenlabs', $julian->voice_provider);
        $this->assertSame('7p1Ofvcwsv7UBPoFNcpI', $julian->voice_id);
        $this->assertSame('avatars/31/thumbnail.webp', $julian->portrait_path);
        $this->assertTrue($julian->is_active);
    }

    public function test_julian_is_active_alongside_napoleon_and_joan_of_arc(): void
    {
        $activeNames = Avatar::where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

        $this->assertSame(['Julian', 'Napoleon', 'Joan of Arc'], $activeNames);
    }
}
