<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Script panel spins while narration is being recorded and only stops when the scene payload
 * brings news. A failed job used to bring none, so the spinner ran for ever — the scene said
 * "failed" in the database while the panel still said "recording…".
 */
class SceneLoadStatusTest extends TestCase
{
    use RefreshDatabase;

    private function scene(array $attributes = []): Scene
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'topic' => 'The VOC',
            'subject' => 'history',
            'grade_level' => '6',
        ]);
        $scene = Scene::create(array_merge([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'ready',
            'script_segment' => 'The VOC was the first multinational.',
        ], $attributes));

        $this->actingAs($teacher);

        return $scene;
    }

    public function test_the_scene_payload_carries_why_a_narration_failed(): void
    {
        $scene = $this->scene(['status' => 'failed', 'error_message' => 'TTS service returned no audio.']);

        Livewire::test(Step3SceneConfigurator::class, ['lesson' => $scene->lesson])
            ->call('selectScene', $scene->id)
            ->assertDispatched('scene:load', function (string $event, array $params) {
                return ($params['payload']['status'] ?? null) === 'failed'
                    && ($params['payload']['errorMessage'] ?? null) === 'TTS service returned no audio.';
            });
    }

    public function test_a_healthy_scene_reports_its_status_too(): void
    {
        $scene = $this->scene(['audio_path' => 'lessons/1/a.mp3']);

        Livewire::test(Step3SceneConfigurator::class, ['lesson' => $scene->lesson])
            ->call('selectScene', $scene->id)
            ->assertDispatched('scene:load', function (string $event, array $params) {
                return ($params['payload']['status'] ?? null) === 'ready'
                    && ($params['payload']['errorMessage'] ?? null) === null
                    && str_contains((string) ($params['payload']['audioUrl'] ?? ''), 'a.mp3');
            });
    }
}
