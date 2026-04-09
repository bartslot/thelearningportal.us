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

    public function test_assign_clip_to_slot_adds_to_controller(): void
    {
        $clip = AnimationClip::factory()->create(['clip_id' => '138_11', 'status' => 'ready']);

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class, ['avatar' => $this->avatar])
            ->call('assignClipToSlot', $clip->id, 'walk')
            ->assertHasNoErrors();

        $controller = AvatarAnimationController::where('avatar_id', $this->avatar->id)->firstOrFail();
        $this->assertContains('138_11', $controller->controller['slots']['walk']['clips']);
    }

    public function test_assign_same_clip_twice_does_not_duplicate(): void
    {
        $clip = AnimationClip::factory()->create(['clip_id' => '138_11', 'status' => 'ready']);

        $component = Livewire::actingAs($this->admin)
            ->test(AvatarLab::class, ['avatar' => $this->avatar]);

        $component->call('assignClipToSlot', $clip->id, 'walk');
        $component->call('assignClipToSlot', $clip->id, 'walk');

        $controller = AvatarAnimationController::where('avatar_id', $this->avatar->id)->firstOrFail();
        $this->assertCount(1, $controller->controller['slots']['walk']['clips']);
    }

    public function test_remove_clip_from_slot(): void
    {
        $clip = AnimationClip::factory()->create(['clip_id' => '138_11', 'status' => 'ready']);

        $data = AvatarAnimationController::defaultControllerData();
        $data['slots']['walk'] = ['mode' => 'random', 'clips' => ['138_11', '138_14']];

        AvatarAnimationController::create([
            'avatar_id'  => $this->avatar->id,
            'controller' => $data,
        ]);

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class, ['avatar' => $this->avatar])
            ->call('removeClipFromSlot', $clip->id, 'walk')
            ->assertHasNoErrors();

        $controller = AvatarAnimationController::where('avatar_id', $this->avatar->id)->firstOrFail();
        $this->assertNotContains('138_11', $controller->controller['slots']['walk']['clips']);
        $this->assertContains('138_14', $controller->controller['slots']['walk']['clips']);
    }

    public function test_set_slot_mode(): void
    {
        AvatarAnimationController::create([
            'avatar_id'  => $this->avatar->id,
            'controller' => AvatarAnimationController::defaultControllerData(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class, ['avatar' => $this->avatar])
            ->call('setSlotMode', 'walk', 'sequential')
            ->assertHasNoErrors();

        $controller = AvatarAnimationController::where('avatar_id', $this->avatar->id)->firstOrFail();
        $this->assertSame('sequential', $controller->controller['slots']['walk']['mode']);
    }
}
