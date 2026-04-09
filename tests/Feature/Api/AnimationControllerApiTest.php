<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnimationControllerApiTest extends TestCase
{
    use RefreshDatabase;

    private Avatar $avatar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->avatar = Avatar::factory()->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(route('api.v1.avatars.controller', $this->avatar))
            ->assertUnauthorized();
    }

    public function test_returns_default_controller_when_none_saved(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        $response = $this->getJson(route('api.v1.avatars.controller', $this->avatar))
            ->assertOk()
            ->assertJsonStructure([
                'controller' => ['version', 'slots', 'transitions'],
                'clip_urls',
            ]);

        $this->assertSame(1, $response->json('controller.version'));
        $this->assertIsArray($response->json('clip_urls'));
    }

    public function test_returns_saved_controller_with_clip_urls(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $clip = AnimationClip::factory()->create([
            'clip_id'  => '138_11',
            'glb_path' => 'avatars/animations/walking_talking/138_11.glb',
            'status'   => 'ready',
        ]);

        $controllerData = AvatarAnimationController::defaultControllerData();
        $controllerData['slots']['walk']['clips'] = ['138_11'];

        AvatarAnimationController::create([
            'avatar_id'  => $this->avatar->id,
            'controller' => $controllerData,
        ]);

        $response = $this->getJson(route('api.v1.avatars.controller', $this->avatar))
            ->assertOk();

        $this->assertContains('138_11', $response->json('controller.slots.walk.clips'));
        $this->assertArrayHasKey('138_11', $response->json('clip_urls'));
        $this->assertStringContainsString('138_11.glb', $response->json('clip_urls.138_11'));
    }

    public function test_clip_urls_only_includes_assigned_ready_clips(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));

        // Pending clip — should NOT appear in clip_urls
        AnimationClip::factory()->create([
            'clip_id'  => '138_20',
            'status'   => 'pending',
            'glb_path' => null,
        ]);

        $controllerData = AvatarAnimationController::defaultControllerData();
        $controllerData['slots']['walk']['clips'] = ['138_20'];

        AvatarAnimationController::create([
            'avatar_id'  => $this->avatar->id,
            'controller' => $controllerData,
        ]);

        $response = $this->getJson(route('api.v1.avatars.controller', $this->avatar))->assertOk();

        $this->assertArrayNotHasKey('138_20', $response->json('clip_urls'));
    }
}
