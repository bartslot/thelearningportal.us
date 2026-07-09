<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\ModuleType;
use App\Lessons\Modules\Audience;
use App\Lessons\Modules\LessonModuleType;
use App\Lessons\Modules\ModuleRegistry;
use App\Lessons\Modules\Types\ConclusionModule;
use App\Livewire\LessonComposer;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** K-6 framing bookends — Conclusion module (Intro shipped in K-1). */
class FramingModulesTest extends TestCase
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

    public function test_registry_resolves_conclusion_to_the_contract(): void
    {
        $implementation = ModuleRegistry::for(ModuleType::Conclusion);

        $this->assertInstanceOf(LessonModuleType::class, $implementation);
        $this->assertInstanceOf(ConclusionModule::class, $implementation);
        $this->assertTrue(ModuleRegistry::has(ModuleType::Conclusion));
        $this->assertContains(ModuleType::Conclusion, ModuleRegistry::available());
    }

    public function test_default_config_has_heading_takeaways_and_whats_next(): void
    {
        $this->assertSame(
            ['heading' => '', 'takeaways' => [], 'whats_next' => ''],
            ConclusionModule::defaultConfig()
        );
    }

    public function test_adding_a_conclusion_prefills_takeaways_from_lesson_objectives(): void
    {
        $this->lesson->update([
            'outline' => [
                'learning_objectives' => [
                    ['id' => 'obj1', 'text' => 'Explain why Caesar crossed the Rubicon'],
                    ['id' => 'obj2', 'text' => 'Describe the fall of the Republic'],
                ],
            ],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson->fresh()])
            ->call('addModule', ModuleType::Conclusion->value)
            ->assertSuccessful();

        $module = $this->lesson->modules()->first();

        $this->assertSame(ModuleType::Conclusion, $module->type);
        $this->assertSame(
            ['Explain why Caesar crossed the Rubicon', 'Describe the fall of the Republic'],
            $module->config['takeaways']
        );
    }

    public function test_objective_prefill_caps_at_five_takeaways(): void
    {
        $objectives = array_map(
            static fn (int $i): array => ['id' => "obj{$i}", 'text' => "Objective {$i}"],
            range(1, 7)
        );
        $this->lesson->update(['outline' => ['learning_objectives' => $objectives]]);

        $config = ConclusionModule::configWithLessonDefaults($this->lesson->fresh());

        $this->assertCount(5, $config['takeaways']);
        $this->assertSame('Objective 1', $config['takeaways'][0]);
        $this->assertSame('Objective 5', $config['takeaways'][4]);
    }

    public function test_adding_a_conclusion_without_objectives_leaves_takeaways_empty(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', ModuleType::Conclusion->value)
            ->assertSuccessful();

        $this->assertSame([], $this->lesson->modules()->first()->config['takeaways']);
    }

    public function test_editor_renders_via_the_composer(): void
    {
        $this->lesson->modules()->create([
            'type' => ModuleType::Conclusion,
            'order' => 0,
            'config' => ConclusionModule::defaultConfig(),
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->assertSee(__('Takeaways (one per line, max :max)', ['max' => ConclusionModule::MAX_TAKEAWAYS]))
            ->assertSee(__('Conclusion'));
    }

    public function test_slide_renders_the_recap_and_whats_next(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::Conclusion,
            'order' => 0,
            'config' => [
                'heading' => 'The Republic in three ideas',
                'takeaways' => ['Power was shared', 'Ambition broke the rules'],
                'whats_next' => 'Next lesson: the rise of Augustus',
            ],
        ]);

        $html = ModuleRegistry::for(ModuleType::Conclusion)
            ->renderSlide($module, Audience::Student)
            ->render();

        $this->assertStringContainsString('The Republic in three ideas', $html);
        $this->assertStringContainsString('Power was shared', $html);
        $this->assertStringContainsString('Ambition broke the rules', $html);
        $this->assertStringContainsString('Next lesson: the rise of Augustus', $html);
    }

    public function test_slide_falls_back_to_a_default_heading(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::Conclusion,
            'order' => 0,
            'config' => ConclusionModule::defaultConfig(),
        ]);

        $html = ModuleRegistry::for(ModuleType::Conclusion)
            ->renderSlide($module, Audience::Student)
            ->render();

        $this->assertStringContainsString(__('What we learned'), $html);
    }

    public function test_teacher_can_edit_takeaways_capped_at_five(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::Conclusion,
            'order' => 0,
            'config' => ConclusionModule::defaultConfig(),
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('updateModuleLines', $module->id, 'takeaways', "One\nTwo\nThree\nFour\nFive\nSix")
            ->call('updateModuleText', $module->id, 'heading', 'Recap')
            ->assertSuccessful();

        $fresh = $module->fresh();

        $this->assertSame(['One', 'Two', 'Three', 'Four', 'Five'], $fresh->config['takeaways']);
        $this->assertSame('Recap', $fresh->config['heading']);
    }

    public function test_a_lesson_can_hold_both_new_module_types_in_order(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', ModuleType::PriorKnowledge->value)
            ->call('addModule', ModuleType::Conclusion->value)
            ->assertSuccessful();

        $types = $this->lesson->modules()->ordered()->pluck('type')->all();

        $this->assertSame([ModuleType::PriorKnowledge, ModuleType::Conclusion], $types);
    }

    public function test_intro_slide_still_renders_objectives(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::Intro,
            'order' => 0,
            'config' => [
                'heading' => 'Rome',
                'subheading' => '',
                'objectives' => ['Understand the Senate'],
            ],
        ]);

        $html = ModuleRegistry::for(ModuleType::Intro)
            ->renderSlide($module, Audience::Student)
            ->render();

        $this->assertStringContainsString('Understand the Senate', $html);
    }
}
