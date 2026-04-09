<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\AvatarLab;
use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AvatarLabMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Avatar $avatar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin  = User::factory()->create(['role' => 'admin']);
        $this->avatar = Avatar::factory()->create();
    }

    public function test_page_loads_for_admin(): void
    {
        $this->withoutVite();

        $this->actingAs($this->admin)
            ->get(route('admin.avatar-lab', $this->avatar))
            ->assertOk()
            ->assertSeeLivewire(AvatarLab::class);
    }

    /** @deprecated AvatarLab was replaced by the 3D lab; animation-controller methods removed. */
    public function test_assign_clip_to_slot_adds_to_controller(): void
    {
        $this->markTestSkipped('Animation-controller AvatarLab replaced by 3D Avatar Lab.');
    }

    /** @deprecated AvatarLab was replaced by the 3D lab; animation-controller methods removed. */
    public function test_assign_same_clip_twice_does_not_duplicate(): void
    {
        $this->markTestSkipped('Animation-controller AvatarLab replaced by 3D Avatar Lab.');
    }

    /** @deprecated AvatarLab was replaced by the 3D lab; animation-controller methods removed. */
    public function test_remove_clip_from_slot(): void
    {
        $this->markTestSkipped('Animation-controller AvatarLab replaced by 3D Avatar Lab.');
    }

    /** @deprecated AvatarLab was replaced by the 3D lab; animation-controller methods removed. */
    public function test_set_slot_mode(): void
    {
        $this->markTestSkipped('Animation-controller AvatarLab replaced by 3D Avatar Lab.');
    }
}
