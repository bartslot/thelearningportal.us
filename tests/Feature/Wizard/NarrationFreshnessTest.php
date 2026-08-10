<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A corrected script must be heard, not just saved.
 *
 * GenerateSceneAudio writes to `…/scenes/{id}/narration.{ext}` — a path derived from the scene, not
 * from its contents — so a regenerated clip lands at a byte-identical URL and the browser keeps
 * serving the old bytes. Bart changed "loopt" to "vaart" because Tasman was sailing, waited for the
 * job, and heard "loopt". The database had the new script and a matching audio_script_hash, so
 * everything that could be inspected looked correct. Students got the old audio too.
 */
class NarrationFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private function sceneWithAudio(string $hash): Scene
    {
        $teacher = User::create([
            'name' => 'Teacher', 'email' => 'teacher@example.com',
            'password' => 'secret-password', 'role' => 'teacher',
        ]);

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'title' => 'Tasman', 'topic' => 'Tasman',
            'subject' => 'history', 'grade_level' => '7', 'status' => LessonStatus::Configuring,
        ]);

        return Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready',
            'script' => 'Vijftien juni 1643 loopt Tasman Batavia weer binnen.',
            'audio_path' => "lessons/{$lesson->id}/scenes/1/narration.mp3",
            'audio_script_hash' => $hash,
        ]);
    }

    public function test_the_audio_url_changes_when_the_narration_does(): void
    {
        $scene = $this->sceneWithAudio(sha1('loopt'));
        $before = $scene->audioUrl();

        // The path is identical after a regeneration — that is the whole problem.
        $scene->update(['audio_script_hash' => sha1('vaart')]);

        $this->assertNotSame($before, $scene->fresh()->audioUrl(), 'a new clip must not reuse the old URL');
        $this->assertStringContainsString('narration.mp3', (string) $before);
    }

    public function test_an_untouched_scene_keeps_its_url_so_the_cache_still_works(): void
    {
        // The version is the script hash rather than a timestamp precisely so that a scene nobody
        // edited is not re-downloaded by every child in the class on every visit.
        $scene = $this->sceneWithAudio(sha1('loopt'));

        $first = $scene->audioUrl();
        $scene->touch();

        $this->assertSame($first, $scene->fresh()->audioUrl());
    }

    public function test_a_scene_with_no_audio_has_no_url(): void
    {
        $scene = $this->sceneWithAudio(sha1('loopt'));
        $scene->update(['audio_path' => null]);

        $this->assertNull($scene->fresh()->audioUrl());
    }

    public function test_the_student_player_serves_the_versioned_url(): void
    {
        // The editor was only half the damage. Every child got the stale clip too.
        $scene = $this->sceneWithAudio(sha1('vaart'));
        $lesson = $scene->lesson;
        $lesson->update(['status' => LessonStatus::Published]);

        $this->get(route('lesson.play', $lesson->lesson_code))
            ->assertOk()
            ->assertSee('narration.mp3?v='.substr(sha1('vaart'), 0, 8), escape: false);
    }

    public function test_regenerating_refreshes_the_panel(): void
    {
        Queue::fake();
        $scene = $this->sceneWithAudio(sha1('loopt'));

        // Without the re-select the editor kept the state from before the click: the scene was
        // generating, but the panel still showed the old clip's transport, and then lost it.
        Livewire::actingAs($scene->lesson->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $scene->lesson])
            ->call('selectScene', $scene->id)
            ->call('regenerate', $scene->id, 'audio')
            ->assertDispatched('scene:load');

        $this->assertSame('generating', $scene->fresh()->status);
    }
}
