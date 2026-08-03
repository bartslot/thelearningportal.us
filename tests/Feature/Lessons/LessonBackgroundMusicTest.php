<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\AudioManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The background music bed.
 *
 * One switch per lesson, off unless the teacher asks for it: most lessons run on a classroom
 * speaker, where music under a voice makes the narration harder to follow. There is one
 * soundtrack (config/audio.php) which the player shuffles and crossfades, so there is nothing
 * else for the teacher to choose.
 */
class LessonBackgroundMusicTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(User $teacher): Lesson
    {
        $lesson = Lesson::factory()->create([
            'teacher_id' => $teacher->id,
            'status' => LessonStatus::Published->value,
        ]);
        // The player only opens a lesson that has something to play (see CatalogEmptyLessonTest);
        // these tests are about what the payload carries, so give them a lesson worth opening.
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);

        return $lesson->refresh();
    }

    public function test_a_lesson_is_silent_unless_the_teacher_asks_for_music(): void
    {
        // fresh() because the column's default is applied by the database, not by the model.
        $this->assertFalse($this->lesson(User::factory()->create())->fresh()->background_music);
    }

    public function test_the_teacher_can_turn_it_on_and_off_again(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->lesson($teacher);

        $component = Livewire::actingAs($teacher)->test(Step3SceneConfigurator::class, ['lesson' => $lesson]);

        $component->call('setBackgroundMusic', true);
        $this->assertTrue($lesson->fresh()->background_music);

        $component->call('setBackgroundMusic', false);
        $this->assertFalse($lesson->fresh()->background_music);
    }

    public function test_the_player_is_told_whether_this_lesson_has_music(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->lesson($teacher);
        $lesson->update(['background_music' => true]);

        $this->get(route('lesson.play', ['lessonCode' => $lesson->lesson_code]))
            ->assertOk()
            ->assertSee('"background_music":true', false);
    }

    public function test_the_player_ships_the_soundtrack_it_needs_to_shuffle(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->lesson($teacher);

        $response = $this->get(route('lesson.play', ['lessonCode' => $lesson->lesson_code]))->assertOk();

        // The manifest is on the page whether or not THIS lesson plays music: the right-answer
        // chime rides on it too, and every quiz has one.
        //
        // Filenames rather than whole paths, because @js escapes the slashes out of a URL.
        $response->assertSee('window.LP_AUDIO', false);
        foreach (config('audio.background_music.tracks') as $track) {
            $response->assertSee(basename($track), false);
        }
        $response->assertSee(basename(config('audio.sfx.correct')), false);
    }

    public function test_every_track_and_effect_in_the_manifest_is_a_file_that_exists(): void
    {
        // A missing file is silence in a classroom with no error anywhere to explain it, so the
        // catalogue and what is actually on disk have to agree.
        $paths = array_merge(
            (array) config('audio.background_music.tracks'),
            array_values((array) config('audio.sfx')),
        );

        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertFileExists(public_path($path));
        }
    }

    public function test_the_manifest_hands_the_browser_absolute_urls(): void
    {
        $manifest = AudioManifest::forBrowser();

        $this->assertCount(count(config('audio.background_music.tracks')), $manifest['music']['tracks']);
        foreach ($manifest['music']['tracks'] as $url) {
            $this->assertStringStartsWith('http', $url);
        }
        $this->assertStringStartsWith('http', $manifest['sfx']['correct']);

        // The bed sits well under the narration — it is scenery, not the thing being listened to.
        $this->assertGreaterThan(0, $manifest['music']['volume']);
        $this->assertLessThan(0.5, $manifest['music']['volume']);
        $this->assertGreaterThan(0, $manifest['music']['fade_ms']);
    }
}
