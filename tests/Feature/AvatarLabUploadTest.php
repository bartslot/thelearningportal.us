<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\AvatarLab;
use App\Models\AnimationClip;
use App\Models\Avatar;
use App\Models\AvatarAnimationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class AvatarLabUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_upload_an_idle_fbx_clip(): void
    {
        $file = UploadedFile::fake()->create('idle_wave.fbx', 512, 'application/octet-stream');

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->set('idleFile', $file);

        $this->assertTrue(
            AnimationClip::where('category', 'idle')->where('name', 'idle_wave')->exists()
        );
        $clip = AnimationClip::where('category', 'idle')->first();
        $this->assertStringContainsString('avatars/animations/idle/', $clip->fbx_path);
    }

    public function test_admin_can_upload_a_presenting_fbx_clip(): void
    {
        $file = UploadedFile::fake()->create('walking_talk.fbx', 512, 'application/octet-stream');

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->set('presentingFile', $file);

        $this->assertTrue(AnimationClip::where('category', 'presenting')->exists());
    }

    public function test_admin_can_upload_a_greeting_fbx_clip(): void
    {
        $file = UploadedFile::fake()->create('waving.fbx', 512, 'application/octet-stream');

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->set('greetingFile', $file);

        $this->assertTrue(AnimationClip::where('category', 'greeting')->exists());
    }

    public function test_non_fbx_files_are_rejected(): void
    {
        $file = UploadedFile::fake()->create('animation.glb', 512, 'application/octet-stream');

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->set('idleFile', $file);

        $this->assertSame(0, AnimationClip::count());
    }

    public function test_admin_can_delete_a_clip(): void
    {
        $clip = AnimationClip::factory()->idle()->create();

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->call('deleteClip', $clip->id);

        $this->assertNull(AnimationClip::find($clip->id));
    }

    public function test_deleting_a_clip_removes_it_from_all_controllers(): void
    {
        $avatar = Avatar::factory()->create();
        $clip   = AnimationClip::factory()->idle()->create();

        $ctrl = AvatarAnimationController::create([
            'avatar_id'  => $avatar->id,
            'controller' => ['idle' => [(string) $clip->id], 'presenting' => [], 'greeting' => []],
        ]);

        Livewire::actingAs($this->admin)
            ->test(AvatarLab::class)
            ->call('deleteClip', $clip->id);

        $this->assertEmpty($ctrl->fresh()->controller['idle']);
    }
}
