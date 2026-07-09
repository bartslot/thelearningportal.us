<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\ModuleType;
use App\Lessons\Modules\Audience;
use App\Lessons\Modules\LessonModuleType;
use App\Lessons\Modules\ModuleRegistry;
use App\Lessons\Modules\Types\PriorKnowledgeModule;
use App\Livewire\LessonComposer;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** K-4 "What do you already know?" pre-quiz module. */
class PreQuizModuleTest extends TestCase
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

    public function test_registry_resolves_prior_knowledge_to_the_contract(): void
    {
        $implementation = ModuleRegistry::for(ModuleType::PriorKnowledge);

        $this->assertInstanceOf(LessonModuleType::class, $implementation);
        $this->assertInstanceOf(PriorKnowledgeModule::class, $implementation);
        $this->assertTrue(ModuleRegistry::has(ModuleType::PriorKnowledge));
        $this->assertContains(ModuleType::PriorKnowledge, ModuleRegistry::available());
    }

    public function test_default_config_has_question_and_seed_terms(): void
    {
        $config = PriorKnowledgeModule::defaultConfig();

        $this->assertSame(['question' => '', 'seed_terms' => []], $config);
    }

    public function test_composer_can_add_a_prior_knowledge_module(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('addModule', ModuleType::PriorKnowledge->value)
            ->assertSuccessful();

        $module = $this->lesson->modules()->first();

        $this->assertNotNull($module);
        $this->assertSame(ModuleType::PriorKnowledge, $module->type);
        $this->assertSame(PriorKnowledgeModule::defaultConfig(), $module->config);
    }

    public function test_editor_renders_via_the_composer(): void
    {
        $this->lesson->modules()->create([
            'type' => ModuleType::PriorKnowledge,
            'order' => 0,
            'config' => PriorKnowledgeModule::defaultConfig(),
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->assertSee(__('Prompt question'))
            ->assertSee(__('What do you already know?'));
    }

    public function test_slide_shows_the_question_and_hides_seed_terms_behind_a_reveal(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::PriorKnowledge,
            'order' => 0,
            'config' => [
                'question' => 'What do you know about the Roman Republic?',
                'seed_terms' => ['Senate', 'Legion'],
            ],
        ]);

        $html = ModuleRegistry::for(ModuleType::PriorKnowledge)
            ->renderSlide($module, Audience::Teacher)
            ->render();

        $this->assertStringContainsString('What do you know about the Roman Republic?', $html);
        $this->assertStringContainsString('Senate', $html);
        $this->assertStringContainsString('Legion', $html);
        $this->assertStringContainsString(__('Reveal hint words'), $html);
        // Seed terms start hidden (Alpine reveal flow).
        $this->assertStringContainsString('x-cloak', $html);
    }

    public function test_slide_falls_back_to_a_default_question_and_omits_reveal_without_seed_terms(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::PriorKnowledge,
            'order' => 0,
            'config' => PriorKnowledgeModule::defaultConfig(),
        ]);

        $html = ModuleRegistry::for(ModuleType::PriorKnowledge)
            ->renderSlide($module, Audience::Student)
            ->render();

        $this->assertStringContainsString(__('What do you already know about this topic?'), $html);
        $this->assertStringNotContainsString(__('Reveal hint words'), $html);
    }

    public function test_teacher_can_set_the_prompt_question(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::PriorKnowledge,
            'order' => 0,
            'config' => PriorKnowledgeModule::defaultConfig(),
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('updateModuleText', $module->id, 'question', '  Who ruled Rome?  ')
            ->assertSuccessful();

        $this->assertSame('Who ruled Rome?', $module->fresh()->config['question']);
    }

    public function test_seed_terms_drop_blank_lines_and_cap_at_five(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::PriorKnowledge,
            'order' => 0,
            'config' => PriorKnowledgeModule::defaultConfig(),
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('updateModuleLines', $module->id, 'seed_terms', "Rome\n\n  Senate  \nLegion\nForum\nCaesar\nGaul")
            ->assertSuccessful();

        $this->assertSame(
            ['Rome', 'Senate', 'Legion', 'Forum', 'Caesar'],
            $module->fresh()->config['seed_terms']
        );
    }

    public function test_non_allowlisted_config_keys_are_rejected(): void
    {
        $module = $this->lesson->modules()->create([
            'type' => ModuleType::PriorKnowledge,
            'order' => 0,
            'config' => PriorKnowledgeModule::defaultConfig(),
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonComposer::class, ['lesson' => $this->lesson])
            ->call('updateModuleText', $module->id, 'is_admin', 'yes')
            ->call('updateModuleLines', $module->id, 'question', 'not-a-list-field')
            ->assertSuccessful();

        $this->assertSame(PriorKnowledgeModule::defaultConfig(), $module->fresh()->config);
    }
}
