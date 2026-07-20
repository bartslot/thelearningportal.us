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
        // Controller schema simplified to idle/expression/dance (was idle/presenting/greeting).
        $data = AvatarAnimationController::defaultControllerData();

        $this->assertSame(['idle', 'expression', 'dance'], array_keys($data));

        foreach ($data as $category => $clips) {
            $this->assertIsArray($clips, "Category {$category} must be an array");
            $this->assertEmpty($clips, "Category {$category} must start empty");
        }
    }

    public function test_controller_is_cast_to_array(): void
    {
        $avatar = Avatar::factory()->create();

        $controller = AvatarAnimationController::create([
            'avatar_id'  => $avatar->id,
            'controller' => ['idle' => ['1', '2'], 'expression' => [], 'dance' => []],
        ]);

        $this->assertIsArray($controller->fresh()->controller);
        $this->assertSame(['1', '2'], $controller->fresh()->controller['idle']);
    }
}
