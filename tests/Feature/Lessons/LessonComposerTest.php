<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\ModuleType;
use App\Livewire\LessonComposer;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LessonComposerTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->lesson = Lesson::factory()->create(['teacher_id' => $this->teacher->id]);
    }

    public function test_mount_denies_non_owner(): void
    {
        $other = User::factory()->create();

        Livewire::actingAs($other)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->assertForbidden();
    }

    public function test_add_module_appends_to_end_with_default_config(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', ModuleType::Intro->value)
            ->assertSuccessful();

        $module = $this->lesson->modules()->first();

        $this->assertNotNull($module);
        $this->assertSame(ModuleType::Intro, $module->type);
        $this->assertSame(0, $module->order);
        $this->assertIsArray($module->config);
        $this->assertSame('ready', $module->status);
    }

    public function test_add_multiple_modules_maintains_order(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', ModuleType::Intro->value)
            ->call('addModule', ModuleType::QuizMcq->value)
            ->call('addModule', ModuleType::Intro->value)
            ->assertSuccessful();

        $types = $this->lesson->modules()->ordered()->pluck('type')->all();

        $this->assertSame(
            [ModuleType::Intro, ModuleType::QuizMcq, ModuleType::Intro],
            $types
        );
    }

    public function test_add_module_rejects_unimplemented_types(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', ModuleType::StoryBlock->value)
            ->assertSuccessful();

        $this->assertSame(0, $this->lesson->modules()->count());
    }

    public function test_add_module_rejects_invalid_type(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', 'not_a_real_type')
            ->assertSuccessful();

        $this->assertSame(0, $this->lesson->modules()->count());
    }

    public function test_delete_module_removes_it(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);
        $m2 = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'order' => 1,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('deleteModule', $m1->id)
            ->assertSuccessful();

        $this->assertSame(1, $this->lesson->modules()->count());
        $this->assertFalse($this->lesson->modules()->where('id', $m1->id)->exists());
    }

    public function test_delete_module_renumbers_remaining(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);
        $m2 = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'order' => 1,
            'config' => [],
        ]);
        $m3 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 2,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('deleteModule', $m2->id);

        $orders = $this->lesson->modules()->ordered()->pluck('order')->all();

        $this->assertSame([0, 1], $orders);
    }

    public function test_duplicate_module_creates_copy_after_original(): void
    {
        $original = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'title' => 'First Intro',
            'order' => 0,
            'config' => ['heading' => 'Test'],
            'estimated_duration_seconds' => 30,
        ]);
        $after = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'order' => 1,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('duplicateModule', $original->id)
            ->assertSuccessful();

        $modules = $this->lesson->modules()->ordered()->get();

        $this->assertSame(3, $modules->count());
        $this->assertSame($original->id, $modules[0]->id);
        $this->assertSame('First Intro', $modules[1]->title); // Duplicate
        $this->assertSame($after->id, $modules[2]->id);
    }

    public function test_duplicate_module_preserves_config(): void
    {
        $original = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'title' => 'Quiz 1',
            'order' => 0,
            'config' => [
                'question' => 'What is 2+2?',
                'answers' => [
                    ['label' => 'A', 'text' => '3'],
                    ['label' => 'B', 'text' => '4'],
                ],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('duplicateModule', $original->id);

        $duplicate = $this->lesson->modules()->where('order', 1)->first();

        $this->assertNotNull($duplicate);
        $this->assertSame($original->config, $duplicate->config);
    }

    public function test_reorder_persists_new_order(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);
        $m2 = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'order' => 1,
            'config' => [],
        ]);
        $m3 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 2,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('reorder', [$m3->id, $m1->id, $m2->id])
            ->assertSuccessful();

        $orderedIds = $this->lesson->fresh()->modules()->ordered()->pluck('id')->all();

        $this->assertSame([$m3->id, $m1->id, $m2->id], $orderedIds);
    }

    public function test_move_up_swaps_with_previous(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);
        $m2 = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'order' => 1,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('moveUp', $m2->id);

        $orderedIds = $this->lesson->fresh()->modules()->ordered()->pluck('id')->all();

        $this->assertSame([$m2->id, $m1->id], $orderedIds);
    }

    public function test_move_down_swaps_with_next(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);
        $m2 = $this->lesson->modules()->create([
            'type' => ModuleType::QuizMcq,
            'order' => 1,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('moveDown', $m1->id)
            ->assertSuccessful();

        $orderedIds = $this->lesson->fresh()->modules()->ordered()->pluck('id')->all();

        // moveDown on m1 (first position) swaps it with m2, so m2 is now first
        $this->assertSame([$m2->id, $m1->id], $orderedIds);
    }

    public function test_move_up_at_top_does_nothing(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('moveUp', $m1->id);

        $order = $this->lesson->fresh()->modules()->first()->order;

        $this->assertSame(0, $order);
    }

    public function test_move_down_at_bottom_does_nothing(): void
    {
        $m1 = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('moveDown', $m1->id);

        $order = $this->lesson->fresh()->modules()->first()->order;

        $this->assertSame(0, $order);
    }
}
