<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AnimationClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnimationClipModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_fbx_absolute_path_returns_public_path(): void
    {
        $clip = AnimationClip::factory()->create([
            'fbx_path' => 'avatars/animations/walking_talking/138_14.fbx',
        ]);

        $this->assertSame(
            public_path('avatars/animations/walking_talking/138_14.fbx'),
            $clip->fbxAbsolutePath()
        );
    }

    public function test_glb_absolute_path_returns_null_when_not_converted(): void
    {
        $clip = AnimationClip::factory()->create(['glb_path' => null]);

        $this->assertNull($clip->glbAbsolutePath());
    }

    public function test_glb_absolute_path_returns_public_path_when_converted(): void
    {
        $clip = AnimationClip::factory()->create([
            'glb_path' => 'avatars/animations/walking_talking/138_14.glb',
        ]);

        $this->assertSame(
            public_path('avatars/animations/walking_talking/138_14.glb'),
            $clip->glbAbsolutePath()
        );
    }

    public function test_is_converted_returns_true_only_when_status_ready(): void
    {
        $pending    = AnimationClip::factory()->create(['status' => 'pending']);
        $ready      = AnimationClip::factory()->create(['status' => 'ready']);
        $failed     = AnimationClip::factory()->create(['status' => 'failed']);
        $converting = AnimationClip::factory()->create(['status' => 'converting']);

        $this->assertFalse($pending->isConverted());
        $this->assertTrue($ready->isConverted());
        $this->assertFalse($failed->isConverted());
        $this->assertFalse($converting->isConverted());
    }
}
