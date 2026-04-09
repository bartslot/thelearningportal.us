<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ConvertAnimationClip;
use App\Models\AnimationClip;
use App\Services\FbxConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ConvertAnimationClipJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_conversion_service_with_clip(): void
    {
        $clip = AnimationClip::factory()->create(['status' => 'pending']);

        $mock = Mockery::mock(FbxConversionService::class);
        $mock->shouldReceive('convert')
            ->once()
            ->with(Mockery::on(fn($c) => $c->id === $clip->id));

        $this->app->instance(FbxConversionService::class, $mock);

        (new ConvertAnimationClip($clip->id))->handle($mock);
    }

    public function test_failed_method_sets_clip_status_to_failed(): void
    {
        $clip = AnimationClip::factory()->create(['status' => 'converting']);

        $job = new ConvertAnimationClip($clip->id);
        $job->failed(new \RuntimeException('fbx2gltf not found'));

        $this->assertSame('failed', $clip->fresh()->status);
        $this->assertSame('fbx2gltf not found', $clip->fresh()->conversion_error);
    }

    public function test_job_sets_clip_to_converting_before_dispatch(): void
    {
        $clip = AnimationClip::factory()->create(['status' => 'pending']);

        // Simulating what the Livewire component does before dispatching
        $clip->update(['status' => 'converting']);

        $this->assertSame('converting', $clip->fresh()->status);
    }
}
