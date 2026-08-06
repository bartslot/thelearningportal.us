<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CreateAvatar;
use App\Models\Narrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CreateAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_livewire_temporary_upload_limit_supports_intro_videos(): void
    {
        $this->assertContains('max:51200', config('livewire.temporary_file_upload.rules'));
        $this->assertContains('webm', config('livewire.temporary_file_upload.preview_mimes'));
    }

    public function test_admin_can_open_the_add_avatar_workflow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.narrators.create'))
            ->assertOk()
            ->assertSee('Build a voice');
    }

    public function test_non_admin_cannot_open_the_workflow(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->get(route('admin.narrators.create'))
            ->assertForbidden();
    }

    public function test_workflow_validates_each_step(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CreateAvatar::class)
            ->call('nextStep')
            ->assertHasErrors(['name'])
            ->assertSet('step', 1)
            ->set('name', 'Dr. Maya Okafor')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->call('nextStep')
            ->assertHasErrors(['portrait'])
            ->assertSet('step', 2);
    }

    public function test_admin_can_create_an_inactive_avatar_with_a_unique_slug(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        Narrator::factory()->create(['slug' => 'dr-maya-okafor', 'sort_order' => 4]);

        Livewire::actingAs($admin)
            ->test(CreateAvatar::class)
            ->set('name', 'Dr. Maya Okafor')
            ->set('short_name', 'Maya')
            ->set('avatar_title', 'Historian')
            ->set('description', 'A warm guide to world history.')
            ->set('subject', 'history')
            ->set('portrait', UploadedFile::fake()->image('maya.webp', 600, 600))
            ->set('intro_video', UploadedFile::fake()->create('hello.mp4', 1024, 'video/mp4'))
            ->set('voice_provider', 'edge_tts')
            ->set('voice_id', 'nl-NL-FennaNeural')
            ->set('voice_speed', 0.9)
            ->call('createAvatar')
            ->assertHasNoErrors()
            ->assertRedirect();

        $narrator = Narrator::where('slug', 'dr-maya-okafor-2')->firstOrFail();

        $this->assertSame('edge_tts', $narrator->voice_provider);
        $this->assertSame(5, $narrator->sort_order);
        $this->assertFalse($narrator->is_active);
        Storage::disk('public')->assertExists($narrator->portrait_path);
        Storage::disk('public')->assertExists($narrator->intro_video_path);
        $this->assertSame(Storage::disk('public')->url($narrator->intro_video_path), $narrator->welcomeVideoUrl());
    }

    public function test_julians_existing_welcome_video_is_connected_by_the_seeder(): void
    {
        $this->seed(\Database\Seeders\DemoNarratorSeeder::class);

        $julian = Narrator::where('name', 'Julian')->firstOrFail();

        $this->assertSame('avatars/31/welcome.mp4', $julian->intro_video_path);
        $this->assertSame(asset('avatars/31/welcome.mp4'), $julian->welcomeVideoUrl());
    }

    public function test_a_narrator_can_be_given_an_elevenlabs_voice(): void
    {
        // Every narrator in the catalogue is ElevenLabs, and a cloned voice cannot be offered as a
        // list — it belongs to one account and nothing enumerates it. So the id is typed in, and
        // this is the path that actually gets used.
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CreateAvatar::class)
            ->set('name', 'Ron Slot')
            ->set('subject', 'history')
            ->set('portrait', UploadedFile::fake()->image('ron.jpg', 640, 640))
            ->set('voice_provider', 'elevenlabs')
            ->set('elevenlabs_voice_id', 'Gwv6uO8RNVuOAN68JgUb')
            ->set('voice_speed', 0.95)
            ->call('createAvatar')
            ->assertHasNoErrors()
            ->assertRedirect();

        $narrator = Narrator::where('slug', 'ron-slot')->firstOrFail();

        $this->assertSame('elevenlabs', $narrator->voice_provider);
        $this->assertSame('Gwv6uO8RNVuOAN68JgUb', $narrator->voice_id);
    }

    public function test_a_malformed_elevenlabs_voice_id_is_refused(): void
    {
        // A wrong id fails silently at narration time, hours later and in someone else's lesson.
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(CreateAvatar::class)
            ->set('name', 'Wrong Voice')
            ->set('subject', 'history')
            ->set('portrait', UploadedFile::fake()->image('x.jpg', 640, 640))
            ->set('voice_provider', 'elevenlabs')
            ->set('elevenlabs_voice_id', 'not-a-real-id')
            ->set('voice_speed', 0.95)
            ->call('createAvatar')
            ->assertHasErrors(['elevenlabs_voice_id']);
    }
}
