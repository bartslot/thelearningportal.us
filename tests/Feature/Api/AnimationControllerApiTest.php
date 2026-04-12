<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
                'controller' => ['idle', 'presenting', 'greeting'],
                'clip_urls',
            ]);

        $this->assertSame([], $response->json('controller.idle'));
        $this->assertSame([], $response->json('controller.presenting'));
        $this->assertSame([], $response->json('controller.greeting'));
        $this->assertSame([], $response->json('clip_urls'));
    }

    public function test_returns_saved_controller_with_empty_clip_urls(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $controllerData = AvatarAnimationController::defaultControllerData();
        $controllerData['idle'] = ['clip_a'];

        AvatarAnimationController::create([
            'avatar_id'  => $this->avatar->id,
            'controller' => $controllerData,
        ]);

        $response = $this->getJson(route('api.v1.avatars.controller', $this->avatar))
            ->assertOk();

        $this->assertContains('clip_a', $response->json('controller.idle'));
        $this->assertSame([], $response->json('clip_urls'));
    }
}
