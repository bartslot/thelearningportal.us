<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Earth map style, and the silent fallback that would have hidden it.
 *
 * setLessonMapStyle keeps an allow-list and quietly writes 'soft-atlas' for anything not on it. That
 * is right for a lesson carrying a style this build no longer knows — but it also means a style the
 * picker offers and the list does not accept looks exactly like a dead button: the teacher clicks
 * Earth, the map redraws as a drawn atlas, and nothing anywhere reports a problem.
 *
 * So the rule under test is not "earth is allowed", it is "everything the picker offers is allowed".
 */
class MapStyleEarthTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::create([
            'name' => 'Teacher', 'email' => 'teacher@example.com',
            'password' => 'secret-password', 'role' => 'teacher',
        ]);

        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id, 'title' => 'Tasman', 'topic' => 'Tasman',
            'subject' => 'history', 'grade_level' => '7', 'status' => LessonStatus::Configuring,
        ]);
    }

    private function set(string $style): string
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('setLessonMapStyle', $style);

        return (string) $this->lesson->refresh()->map_style;
    }

    public function test_earth_is_stored_rather_than_falling_back(): void
    {
        $this->assertSame('earth', $this->set('earth'), 'the picker offers Earth, so the server has to accept it');
    }

    public function test_every_style_the_picker_offers_survives_the_round_trip(): void
    {
        // Read the offered styles out of the picker itself, so adding a swatch without adding it to
        // the allow-list fails here rather than in a teacher's lesson.
        $picker = file_get_contents(resource_path('views/components/lesson/map-style-picker.blade.php'));
        preg_match("/\\\$styleOptions = \[(.*?)\];/s", $picker, $m);
        preg_match_all("/'([a-z-]+)'\s*=>/", $m[1] ?? '', $offered);

        $this->assertNotEmpty($offered[1], 'could not read the picker — this test is worthless if it silently matches nothing');

        foreach ($offered[1] as $style) {
            $this->assertSame($style, $this->set($style), "the picker offers '{$style}' but the server rejects it");
        }
    }

    public function test_a_style_this_build_does_not_know_still_falls_back(): void
    {
        // The fallback itself is deliberate and stays: a lesson carrying a retired style must render
        // as something rather than as nothing.
        $this->assertSame('soft-atlas', $this->set('holographic'));
    }

    public function test_a_retired_style_a_lesson_already_uses_is_still_accepted(): void
    {
        // Hidden from the picker, not removed. A lesson on Satellite keeps rendering as Satellite.
        $this->assertSame('satellite', $this->set('satellite'));
        $this->assertSame('pen-ink', $this->set('pen-ink'));
    }
}
