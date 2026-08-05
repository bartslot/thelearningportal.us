<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A film is a scene type of its own (Add ▸ Video), not a background hung behind a narrated scene:
 * it needs its own place in the running order and its own playback controls.
 */
class AddVideoSceneTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'The Dutch Revolt', 'subject' => 'history', 'grade_level' => 'Age 12',
            'image_style' => 'realistic', 'status' => LessonStatus::ScenesReady,
        ]);
        Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'script_segment' => 'Opening.', 'image_path' => 'a.png', 'audio_path' => 'a.mp3',
            'status' => 'ready',
        ]);
    }

    private function wizard(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson]);
    }

    public function test_adding_a_video_scene_creates_a_ready_video_scene(): void
    {
        $this->wizard()->call('addScene', 'video');

        $scene = $this->lesson->scenes()->where('kind', 'video')->first();

        $this->assertNotNull($scene);
        $this->assertSame('embed', $scene->scene_view);
        // Nothing is generated for it, so a 'pending' video scene would block publishing for good.
        $this->assertSame('ready', $scene->status);
    }

    public function test_setting_a_link_stores_the_video_on_the_scene(): void
    {
        $this->wizard()
            ->call('addScene', 'video')
            ->call('setVideoEmbed', 'https://www.youtube.com/watch?v=-E9T6UWaDRA');

        $embed = $this->lesson->scenes()->where('kind', 'video')->first()->config['bg_embed'];

        $this->assertSame('video', $embed['kind']);
        $this->assertSame('youtube', $embed['provider']);
        $this->assertStringContainsString('-E9T6UWaDRA', $embed['src']);
    }

    public function test_clearing_the_link_keeps_the_scene_a_video_scene(): void
    {
        $this->wizard()
            ->call('addScene', 'video')
            ->call('setVideoEmbed', 'https://www.youtube.com/watch?v=-E9T6UWaDRA')
            ->call('clearBgEmbed');

        $scene = $this->lesson->scenes()->where('kind', 'video')->first();

        $this->assertArrayNotHasKey('bg_embed', $scene->config ?? []);
        $this->assertSame('embed', $scene->scene_view);
    }

    public function test_the_video_inspector_renders_for_a_video_scene(): void
    {
        $video = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'video',
            'scene_view' => 'embed', 'status' => 'ready',
            'config' => ['bg_embed' => [
                'kind' => 'video', 'provider' => 'youtube', 'id' => 'abc',
                'src' => 'https://www.youtube.com/embed/abc?autoplay=1', 'fit' => 'cover',
            ]],
        ]);

        $this->wizard()
            ->call('selectScene', $video->id)
            ->assertSee('Video link or embed code')
            ->assertSee('Remove video');
    }
}
