<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AnimationClip;
use Illuminate\Database\QueryException;
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

    public function test_category_must_be_one_of_idle_presenting_greeting(): void
    {
        $this->expectException(QueryException::class);

        AnimationClip::factory()->create(['category' => 'invalid']);
    }
}
