<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvatarAnimationControllerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_controller_data_returns_flat_category_arrays(): void
    {
        $data = AvatarAnimationController::defaultControllerData();

        $this->assertArrayHasKey('idle', $data);
        $this->assertArrayHasKey('presenting', $data);
        $this->assertArrayHasKey('greeting', $data);
        $this->assertIsArray($data['idle']);
        $this->assertEmpty($data['idle']);
        $this->assertIsArray($data['presenting']);
        $this->assertEmpty($data['presenting']);
        $this->assertIsArray($data['greeting']);
        $this->assertEmpty($data['greeting']);
    }

    public function test_controller_is_cast_to_array(): void
    {
        $avatar = Avatar::factory()->create();

        $controller = AvatarAnimationController::create([
            'avatar_id'  => $avatar->id,
            'controller' => ['idle' => ['1', '2'], 'presenting' => [], 'greeting' => []],
        ]);

        $this->assertIsArray($controller->fresh()->controller);
        $this->assertSame(['1', '2'], $controller->fresh()->controller['idle']);
    }
}
