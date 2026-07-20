<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AnimationClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnimationClipModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_fields_are_set_correctly(): void
    {
        $clip = new AnimationClip([
            'name'       => 'Idle Wave',
            'category'   => 'idle',
            'fbx_path'   => 'avatars/animations/idle/1.fbx',
            'sort_order' => 3,
        ]);

        $this->assertSame('Idle Wave', $clip->name);
        $this->assertSame('idle', $clip->category);
        $this->assertSame('avatars/animations/idle/1.fbx', $clip->fbx_path);
        $this->assertSame(3, $clip->sort_order);
    }

    public function test_fbx_url_returns_the_correct_asset_url(): void
    {
        $clip = AnimationClip::factory()->create([
            'fbx_path' => 'avatars/animations/idle/42.fbx',
        ]);

        $this->assertStringContainsString('avatars/animations/idle/42.fbx', $clip->fbxUrl());
    }

    public function test_category_accepts_values_beyond_the_original_enum(): void
    {
        // 2026_04_24_123621 widened category from the idle/presenting/greeting enum to a
        // plain string (dropping the Postgres CHECK constraint) so new categories like
        // 'introduction' can ship without a schema change.
        $clip = AnimationClip::factory()->create(['category' => 'introduction']);

        $this->assertSame('introduction', $clip->fresh()->category);
    }
}
