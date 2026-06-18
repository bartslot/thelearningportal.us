<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Enums\ModuleType;
use App\Lessons\Modules\LessonModuleType;
use App\Lessons\Modules\ModuleRegistry;
use App\Lessons\Modules\Types\IntroModule;
use App\Lessons\Modules\Types\QuizMcqModule;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonModuleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_persists_an_ordered_list_of_typed_modules(): void
    {
        $lesson = Lesson::factory()->create();

        foreach ([ModuleType::Intro, ModuleType::StoryBlock, ModuleType::QuizMcq] as $index => $type) {
            $lesson->modules()->create([
                'type' => $type,
                'order' => $index,
                'config' => [],
                'status' => 'ready',
            ]);
        }

        $types = $lesson->modules()->get()
            ->map(fn ($module): string => $module->type->value)
            ->all();

        $this->assertSame(['intro', 'story_block', 'quiz_mcq'], $types);
        // `type` is cast to the enum, and config round-trips as an array.
        $this->assertInstanceOf(ModuleType::class, $lesson->modules()->first()->type);
        $this->assertIsArray($lesson->modules()->first()->config);
    }

    public function test_reordering_modules_persists(): void
    {
        $lesson = Lesson::factory()->create();

        $first = $lesson->modules()->create(['type' => ModuleType::Intro, 'order' => 0, 'config' => []]);
        $second = $lesson->modules()->create(['type' => ModuleType::QuizMcq, 'order' => 1, 'config' => []]);

        $lesson->reorderModules([$second->id, $first->id]);

        $orderedIds = $lesson->fresh()->modules()->pluck('id')->all();

        $this->assertSame([$second->id, $first->id], $orderedIds);
    }

    public function test_registry_resolves_implemented_types_to_the_contract(): void
    {
        $intro = ModuleRegistry::for(ModuleType::Intro);
        $quiz = ModuleRegistry::for(ModuleType::QuizMcq);

        $this->assertInstanceOf(LessonModuleType::class, $intro);
        $this->assertInstanceOf(LessonModuleType::class, $quiz);
        $this->assertInstanceOf(IntroModule::class, $intro);
        $this->assertInstanceOf(QuizMcqModule::class, $quiz);

        $this->assertTrue(ModuleRegistry::has(ModuleType::Intro));
        $this->assertTrue(ModuleRegistry::has(ModuleType::QuizMcq));
        // Not yet implemented — should report unavailable rather than resolve.
        $this->assertFalse(ModuleRegistry::has(ModuleType::StoryBlock));

        $available = ModuleRegistry::available();
        $this->assertContains(ModuleType::Intro, $available);
        $this->assertContains(ModuleType::QuizMcq, $available);
    }

    public function test_module_resolves_its_own_implementation(): void
    {
        $lesson = Lesson::factory()->create();
        $module = $lesson->modules()->create(['type' => ModuleType::Intro, 'order' => 0, 'config' => []]);

        $this->assertInstanceOf(IntroModule::class, $module->implementation());
        $this->assertSame(ModuleType::Intro, $module->implementation()::type());
    }

    public function test_quiz_default_config_has_four_labelled_answers(): void
    {
        $config = QuizMcqModule::defaultConfig();

        $this->assertCount(4, $config['answers']);
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($config['answers'], 'label'));
    }
}
