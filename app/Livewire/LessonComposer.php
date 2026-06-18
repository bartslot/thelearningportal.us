<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModuleType;
use App\Lessons\Modules\ModuleRegistry;
use App\Models\Lesson;
use App\Models\LessonModule;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Lesson module composer (K-2). Teachers assemble a lesson by adding, reordering, configuring,
 * and deleting typed modules. Replaces Step3SceneConfigurator in the wizard.
 */
class LessonComposer extends Component
{
    public Lesson $lesson;

    public bool $addModuleOpen = false;

    public function mount(Lesson $lesson): void
    {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        $this->lesson = $lesson;
    }

    public function reloadModules(): void
    {
        $this->lesson = $this->lesson->fresh();
    }

    /**
     * Add a new module to the end of the lesson's module list.
     */
    public function addModule(string $type): void
    {
        try {
            $moduleType = ModuleType::from($type);
        } catch (\ValueError) {
            return;
        }

        // Only add types that are registered/buildable.
        if (! ModuleRegistry::has($moduleType)) {
            return;
        }

        $nextOrder = (int) ($this->lesson->modules()->max('order') ?? -1) + 1;

        $implementation = ModuleRegistry::for($moduleType);

        $this->lesson->modules()->create([
            'type' => $moduleType,
            'title' => null,
            'order' => $nextOrder,
            'config' => $implementation::defaultConfig(),
            'estimated_duration_seconds' => $implementation->estimatedDuration(
                new LessonModule(['estimated_duration_seconds' => 0])
            ),
            'status' => 'ready',
        ]);

        $this->reloadModules();
        $this->addModuleOpen = false;
    }

    /**
     * Delete a module by id (scoped to this lesson).
     */
    public function deleteModule(int $moduleId): void
    {
        $module = $this->lesson->modules()->findOrFail($moduleId);
        $module->delete();

        // Renumber remaining modules to keep order contiguous.
        $remaining = $this->lesson->modules()->ordered()->get();
        foreach ($remaining as $idx => $m) {
            $m->update(['order' => $idx]);
        }

        $this->reloadModules();
    }

    /**
     * Duplicate a module immediately after the original (copy title, type, config, duration).
     */
    public function duplicateModule(int $moduleId): void
    {
        $original = $this->lesson->modules()->findOrFail($moduleId);

        // Get all modules after the original, shift their orders up by 1.
        $after = $this->lesson->modules()
            ->where('order', '>', $original->order)
            ->orderBy('order')
            ->get();

        foreach ($after as $m) {
            $m->update(['order' => $m->order + 1]);
        }

        // Create the duplicate right after the original.
        $this->lesson->modules()->create([
            'type' => $original->type,
            'title' => $original->title,
            'order' => $original->order + 1,
            'config' => $original->config ?? [],
            'estimated_duration_seconds' => $original->estimated_duration_seconds,
            'status' => 'ready',
        ]);

        $this->reloadModules();
    }

    /**
     * Move a module up one position (swap with the previous module).
     */
    public function moveUp(int $moduleId): void
    {
        $module = $this->lesson->modules()->findOrFail($moduleId);

        if ($module->order === 0) {
            return; // Already at the top.
        }

        $prev = $this->lesson->modules()
            ->where('order', $module->order - 1)
            ->first();

        if (! $prev) {
            return;
        }

        // Swap orders.
        $orderedIds = $this->lesson->modules()
            ->orderBy('order')
            ->pluck('id')
            ->all();

        // Reverse the two adjacent ids.
        $idx = array_search($moduleId, $orderedIds, true);
        if ($idx !== false && $idx > 0) {
            [$orderedIds[$idx - 1], $orderedIds[$idx]] = [$orderedIds[$idx], $orderedIds[$idx - 1]];
            $this->reorder($orderedIds);
        }

        $this->reloadModules();
    }

    /**
     * Move a module down one position (swap with the next module).
     */
    public function moveDown(int $moduleId): void
    {
        $orderedIds = $this->lesson->modules()
            ->orderBy('order')
            ->pluck('id')
            ->all();

        $idx = array_search($moduleId, $orderedIds, true);
        if ($idx === false || $idx >= count($orderedIds) - 1) {
            return; // Not found or already at the bottom.
        }

        // Swap with the next module.
        [$orderedIds[$idx], $orderedIds[$idx + 1]] = [$orderedIds[$idx + 1], $orderedIds[$idx]];
        $this->reorder($orderedIds);
        $this->reloadModules();
    }

    /**
     * Persist a new module order from SortableJS or button actions.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $this->lesson->reorderModules($orderedIds);
        $this->reloadModules();
    }

    public function render(): View
    {
        return view('livewire.lesson-composer', [
            'modules' => $this->lesson->modules()->ordered()->get(),
            'availableTypes' => ModuleRegistry::available(),
        ]);
    }
}
