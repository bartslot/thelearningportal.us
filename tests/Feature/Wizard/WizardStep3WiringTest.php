<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardStep3WiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_3_renders_the_scene_configurator_not_the_composer(): void
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::factory()->create(['teacher_id' => $teacher->id]);

        $this->actingAs($teacher)
            ->get(route('teacher.lessons.wizard', ['lesson' => $lesson, 'step' => 3]))
            ->assertOk()
            ->assertSee('Inspector')               // the configurator's panel header
            ->assertDontSee('Build Your Lesson');  // the removed composer heading
    }
}
