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

    public function test_controller_is_cast_to_array(): void
    {
        $avatar = Avatar::factory()->create();

        $model = AvatarAnimationController::create([
            'avatar_id'  => $avatar->id,
            'controller' => ['version' => 1, 'slots' => []],
        ]);

        $this->assertIsArray($model->fresh()->controller);
        $this->assertSame(1, $model->fresh()->controller['version']);
    }

    public function test_belongs_to_avatar(): void
    {
        $avatar = Avatar::factory()->create();
        $model  = AvatarAnimationController::create([
            'avatar_id'  => $avatar->id,
            'controller' => ['version' => 1, 'slots' => []],
        ]);

        $this->assertTrue($model->avatar->is($avatar));
    }

    public function test_cascades_on_avatar_delete(): void
    {
        $avatar = Avatar::factory()->create();
        AvatarAnimationController::create([
            'avatar_id'  => $avatar->id,
            'controller' => ['version' => 1, 'slots' => []],
        ]);

        $avatar->delete();

        $this->assertDatabaseMissing('avatar_animation_controllers', ['avatar_id' => $avatar->id]);
    }
}
