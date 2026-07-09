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
 * Map-block focus annotations — the annotationsChanged payload comes from the browser
 * (drag/drop on the editable map), so focus items must be coerced to a safe shape and
 * the array must not be a vector for unbounded config growth.
 */
class SceneAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;
    private Scene $scene;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->scene = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'map',
            'status' => 'ready', 'config' => ['qid' => null, 'year' => 1600],
        ]);
    }

    private function saveAnnotations(array $annotations): array
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->dispatch('annotationsChanged', sceneId: $this->scene->id, annotations: $annotations);

        return $this->scene->fresh()->config['annotations'] ?? [];
    }

    public function test_focus_items_are_coerced_and_labels_truncated(): void
    {
        $clean = $this->saveAnnotations([[
            'type' => 'focus', 'lng' => '12.5', 'lat' => 41.9,
            'label' => '  '.str_repeat('R', 200),
            'historical' => str_repeat('H', 200),
            'capital' => 1,
        ]]);

        $this->assertCount(1, $clean);
        $this->assertSame(80, mb_strlen($clean[0]['label']), 'label truncates to 80');
        $this->assertSame(80, mb_strlen($clean[0]['historical']), 'historical name truncates to 80');
        $this->assertSame(12.5, $clean[0]['lng']);
        $this->assertTrue($clean[0]['capital']);
    }

    public function test_malformed_focus_items_and_untyped_junk_are_dropped(): void
    {
        $clean = $this->saveAnnotations([
            ['type' => 'focus', 'lat' => 41.9, 'label' => 'no lng'],
            ['type' => 'focus', 'lng' => 'east', 'lat' => 'north', 'label' => 'non-numeric'],
            'a bare string',
            42,
            ['label' => 'no type at all'],
            ['type' => 'focus', 'lng' => 4.9, 'lat' => 52.4, 'label' => 'Amsterdam'],
        ]);

        $this->assertCount(1, $clean);
        $this->assertSame('Amsterdam', $clean[0]['label']);
    }

    public function test_unknown_annotation_types_pass_through_for_forward_compat(): void
    {
        $clean = $this->saveAnnotations([
            ['type' => 'arrow', 'from' => [1, 2], 'to' => [3, 4]],
        ]);

        $this->assertSame('arrow', $clean[0]['type']);
        $this->assertSame([1, 2], $clean[0]['from']);
    }

    public function test_annotation_count_is_capped(): void
    {
        $payload = array_map(fn (int $i) => [
            'type' => 'focus', 'lng' => $i % 180, 'lat' => $i % 80, 'label' => "City {$i}",
        ], range(0, 499));

        $clean = $this->saveAnnotations($payload);

        $this->assertLessThanOrEqual(50, count($clean),
            'a hostile client must not be able to bloat scene config with unbounded annotations');
    }
}
