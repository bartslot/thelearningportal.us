<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SpriteStatus;
use App\Jobs\ProcessAvatarPortrait;
use App\Models\Avatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessAvatarPortraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_sprite_status_to_ready_on_success(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/1/portrait.jpg', 'fake-image-data');

        $avatar = Avatar::factory()->create([
            'portrait_path' => 'avatars/1/portrait.jpg',
            'sprite_status' => SpriteStatus::Pending,
        ]);

        Http::fake([
            config('services.vercel_functions.url') . '/api/detect-landmarks' => Http::response([
                'landmarks' => [
                    'mouth'       => ['x' => 100, 'y' => 200, 'w' => 80, 'h' => 40],
                    'left_eye'    => ['x' => 80,  'y' => 150, 'w' => 40, 'h' => 20],
                    'right_eye'   => ['x' => 180, 'y' => 150, 'w' => 40, 'h' => 20],
                    'face_bounds' => ['x' => 50,  'y' => 100, 'w' => 200,'h' => 250],
                ],
                'mouth_frames' => ['data:image/png;base64,abc', 'data:image/png;base64,def', 'data:image/png;base64,ghi', 'data:image/png;base64,jkl'],
                'eye_frames'   => [
                    'left_open'    => 'data:image/png;base64,lo',
                    'left_closed'  => 'data:image/png;base64,lc',
                    'right_open'   => 'data:image/png;base64,ro',
                    'right_closed' => 'data:image/png;base64,rc',
                ],
            ], 200),
        ]);

        (new ProcessAvatarPortrait($avatar->id))->handle();

        $avatar->refresh();
        $this->assertSame(SpriteStatus::Ready, $avatar->sprite_status);
        $this->assertNotNull($avatar->landmarks_json);
    }

    public function test_sets_sprite_status_to_failed_when_vercel_errors(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/1/portrait.jpg', 'fake-image-data');

        $avatar = Avatar::factory()->create([
            'portrait_path' => 'avatars/1/portrait.jpg',
            'sprite_status' => SpriteStatus::Pending,
        ]);

        Http::fake([
            config('services.vercel_functions.url') . '/api/detect-landmarks' => Http::response(['error' => 'No face detected'], 422),
        ]);

        (new ProcessAvatarPortrait($avatar->id))->handle();

        $avatar->refresh();
        $this->assertSame(SpriteStatus::Failed, $avatar->sprite_status);
    }
}
