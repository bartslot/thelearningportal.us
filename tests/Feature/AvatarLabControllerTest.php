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

class AvatarLabControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Avatar $avatar;
    private AnimationClip $clip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin  = User::factory()->create(['role' => 'admin']);
        $this->avatar = Avatar::factory()->create();
        $this->clip   = AnimationClip::factory()->idle()->create();
    }

    public function test_assign_clip_to_controller_creates_controller_record_if_none_exists(): void
    {
        $this->assertFalse(
            AvatarAnimationController::where('avatar_id', $this->avatar->id)->exists()
        );

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->call('assignToController', $this->avatar->id, 'idle', $this->clip->id);

        $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
        $this->assertNotNull($ctrl);
        $this->assertContains((string) $this->clip->id, $ctrl->controller['idle']);
    }

    public function test_assigning_the_same_clip_twice_does_not_duplicate_it(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->call('assignToController', $this->avatar->id, 'idle', $this->clip->id)
            ->call('assignToController', $this->avatar->id, 'idle', $this->clip->id);

        $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
        $this->assertSame(1, count($ctrl->controller['idle']));
    }

    public function test_remove_clip_from_controller_removes_it_from_the_category_array(): void
    {
        AvatarAnimationController::create([
            'avatar_id'  => $this->avatar->id,
            'controller' => ['idle' => [(string) $this->clip->id], 'presenting' => [], 'greeting' => []],
        ]);

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->call('removeFromController', $this->avatar->id, 'idle', $this->clip->id);

        $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
        $this->assertEmpty($ctrl->controller['idle']);
    }

    public function test_use_clip_assigns_previewed_clip_to_its_category(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->set('selectedAvatarId', $this->avatar->id)
            ->set('previewClipId', $this->clip->id)
            ->call('useClip');

        $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
        $this->assertContains((string) $this->clip->id, $ctrl->controller['idle']);
    }
}
