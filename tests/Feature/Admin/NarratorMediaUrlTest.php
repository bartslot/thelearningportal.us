<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Narrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A narrator's picture has to resolve to wherever the file actually is.
 *
 * Two places hold narrator media and the path alone cannot tell them apart. Assets bundled with the
 * app live in `public/avatars/{id}/…`; anything uploaded through the Studio or the Add-narrator form
 * is written to the public STORAGE disk and served through the /storage link. Both are spelled
 * `avatars/…`.
 *
 * The URL used to be chosen by prefix, so every uploaded portrait 404'd: the Studio wrote
 * storage/app/public/avatars/1/portrait.jpg and then linked /avatars/1/portrait.jpg, a different
 * file that does not exist. Ron Slot's portrait had been broken since the day he was created.
 */
class NarratorMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private function narrator(array $attributes = []): Narrator
    {
        return Narrator::create($attributes + [
            'name' => 'Test Narrator',
            'slug' => 'test-narrator-'.uniqid(),
            'voice_provider' => 'azure',
            'voice_id' => 'en-GB-Ollie:DragonHDLatestNeural',
            'voice_speed' => 1.0,
            'is_active' => true,
        ]);
    }

    public function test_an_uploaded_portrait_resolves_to_the_storage_url(): void
    {
        // The exact shape NarratorStudio writes: the public DISK, under an `avatars/` path.
        Storage::disk('public')->put('avatars/1/portrait.jpg', 'jpeg-bytes');
        $narrator = $this->narrator(['portrait_path' => 'avatars/1/portrait.jpg']);

        $this->assertStringContainsString(
            '/storage/avatars/1/portrait.jpg',
            (string) $narrator->portraitUrl(),
            'an uploaded portrait was linked as a bundled asset, which 404s',
        );
    }

    public function test_the_add_narrator_form_shape_also_resolves(): void
    {
        // CreateNarrator stores under avatars/portraits/… — the same prefix, the same old bug.
        Storage::disk('public')->put('avatars/portraits/ron-slot.jpg', 'jpeg-bytes');
        $narrator = $this->narrator(['portrait_path' => 'avatars/portraits/ron-slot.jpg']);

        $this->assertStringContainsString('/storage/avatars/portraits/ron-slot.jpg', (string) $narrator->portraitUrl());
    }

    /**
     * A scratch directory under public/ that is guaranteed NOT to be a real avatar's.
     *
     * The first version of these tests wrote to public/avatars/{id} using the new record's
     * auto-increment id and deleted that directory afterwards. The id reached 4, and the cleanup
     * removed public/avatars/4 — Joan of Arc's committed avatarinfo.json and thumbnail — which then
     * failed AvatarSeederTest, because the seeder builds its roster by scanning those folders.
     *
     * A test that writes inside a directory holding real repository files must never delete by
     * directory. This name cannot collide with a numbered narrator folder.
     */
    private const SCRATCH = 'avatars/__media_url_test__';

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path(self::SCRATCH));

        parent::tearDown();
    }

    public function test_a_bundled_public_asset_still_resolves_without_the_storage_prefix(): void
    {
        $relative = self::SCRATCH.'/thumbnail.webp';
        File::ensureDirectoryExists(public_path(self::SCRATCH));
        File::put(public_path($relative), 'webp-bytes');

        $narrator = $this->narrator(['portrait_path' => $relative]);

        $url = (string) $narrator->portraitUrl();
        $this->assertStringContainsString($relative, $url);
        $this->assertStringNotContainsString('/storage/', $url, 'a bundled asset was sent through /storage');
    }

    public function test_an_upload_wins_over_a_bundled_file_of_the_same_name(): void
    {
        // Replacing a bundled portrait in the Studio must actually replace it on screen.
        $relative = self::SCRATCH.'/portrait.jpg';
        File::ensureDirectoryExists(public_path(self::SCRATCH));
        File::put(public_path($relative), 'old-bundled-bytes');
        Storage::disk('public')->put($relative, 'freshly-uploaded-bytes');

        $narrator = $this->narrator(['portrait_path' => $relative]);

        $this->assertStringContainsString('/storage/', (string) $narrator->portraitUrl(), 'the stale bundled file shadowed the upload');
    }

    public function test_a_missing_file_returns_null_rather_than_a_url_that_404s(): void
    {
        // Callers render a fallback for null. A broken image is worse than no image.
        $narrator = $this->narrator(['portrait_path' => 'avatars/999/nothing-here.jpg']);

        $this->assertNull($narrator->portraitUrl());
    }

    public function test_no_portrait_at_all_is_null(): void
    {
        $this->assertNull($this->narrator(['portrait_path' => null])->portraitUrl());
    }

    public function test_an_uploaded_introduction_video_resolves_to_storage(): void
    {
        Storage::disk('public')->put('avatar-introductions/ron-slot/introduction.mp4', 'mp4-bytes');
        $narrator = $this->narrator(['intro_video_path' => 'avatar-introductions/ron-slot/introduction.mp4']);

        $this->assertStringContainsString(
            '/storage/avatar-introductions/ron-slot/introduction.mp4',
            (string) $narrator->welcomeVideoUrl(),
        );
    }
}
