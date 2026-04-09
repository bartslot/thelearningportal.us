<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AnimationClip;
use App\Services\FbxConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FbxConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FbxConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FbxConversionService();
    }

    public function test_is_installed_returns_false_when_binary_not_found(): void
    {
        Config::set('services.fbx2gltf.binary', '/nonexistent/fbx2gltf');

        $this->assertFalse($this->service->isInstalled());
    }

    public function test_convert_sets_status_to_failed_when_binary_missing(): void
    {
        Config::set('services.fbx2gltf.binary', '/nonexistent/fbx2gltf');

        $clip = AnimationClip::factory()->create([
            'fbx_path' => 'avatars/animations/walking_talking/138_14.fbx',
            'status'   => 'converting',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/fbx2gltf not found/');

        $this->service->convert($clip);

        $this->assertSame('failed', $clip->fresh()->status);
        $this->assertNotNull($clip->fresh()->conversion_error);
    }

    public function test_expected_glb_path_is_same_directory_as_fbx(): void
    {
        $clip = AnimationClip::factory()->create([
            'fbx_path' => 'avatars/animations/walking_talking/138_14.fbx',
        ]);

        $expected = 'avatars/animations/walking_talking/138_14.glb';

        $this->assertSame(
            $expected,
            $this->service->expectedGlbPath($clip)
        );
    }
}
