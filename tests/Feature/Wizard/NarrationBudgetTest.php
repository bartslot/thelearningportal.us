<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\NarrationBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editing a narration script is the one keystroke in this app that becomes an ElevenLabs invoice.
 *
 * A teacher rewriting the same scene twenty times, or pasting in a novel, costs real money — and on
 * a free account there is nothing else stopping them. So a lesson gets an allowance, and only the
 * CHANGE is charged: fixing a typo in a 400-character paragraph must not cost 400 characters, or
 * teachers become afraid to touch their own lesson.
 */
class NarrationBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Lesson, 2: Scene} */
    private function lessonWithScript(string $script): array
    {
        $teacher = User::factory()->create();
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id, 'title' => 'T', 'topic' => 'T', 'subject' => 'history',
            'language' => 'en', 'grade_level' => '7',
        ]);
        $scene = Scene::create([
            'lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready',
            'script_segment' => $script,
        ]);

        return [$teacher, $lesson, $scene];
    }

    public function test_a_typo_fix_costs_about_one_character_not_the_whole_paragraph(): void
    {
        $before = str_repeat('The Roman Empire reached Britannia. ', 12);   // ~430 chars
        $after = str_replace('Britannia.', 'Britannia!', $before);

        $this->assertLessThan(20, NarrationBudget::costOfEdit($before, $after));
    }

    public function test_pasting_in_a_fresh_paragraph_costs_its_length(): void
    {
        $cost = NarrationBudget::costOfEdit('', str_repeat('x', 250));

        $this->assertSame(250, $cost);
    }

    public function test_an_unchanged_script_costs_nothing(): void
    {
        $this->assertSame(0, NarrationBudget::costOfEdit('Same words.', 'Same words.'));
        $this->assertSame(0, NarrationBudget::costOfEdit('  Same words.  ', 'Same words.'));
    }

    public function test_shortening_a_script_still_costs_something(): void
    {
        // Removing words is an edit and still re-narrates the whole scene.
        $this->assertGreaterThan(0, NarrationBudget::costOfEdit(str_repeat('long ', 40), 'short'));
    }

    public function test_a_lesson_starts_with_the_free_allowance_and_no_spend(): void
    {
        [, $lesson] = $this->lessonWithScript('Anything.');

        $this->assertSame(NarrationBudget::FREE_CHARACTERS_PER_LESSON, NarrationBudget::remainingFor($lesson));
        $this->assertSame(0, NarrationBudget::spentOn($lesson));
    }

    public function test_an_edit_within_the_allowance_saves_and_is_charged(): void
    {
        [$teacher, $lesson, $scene] = $this->lessonWithScript('Original narration text.');

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('updateSceneScript', 'Original narration text, now with an added clause.', $scene->id);

        $this->assertStringContainsString('added clause', (string) $scene->refresh()->script_segment);
        $this->assertGreaterThan(0, NarrationBudget::spentOn($lesson->refresh()));
    }

    public function test_an_edit_past_the_allowance_is_refused_and_the_script_is_untouched(): void
    {
        [$teacher, $lesson, $scene] = $this->lessonWithScript('Original narration text.');
        // Spend the lesson's allowance.
        $lesson->update(['narration_edit_characters' => NarrationBudget::FREE_CHARACTERS_PER_LESSON]);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('updateSceneScript', str_repeat('An entirely different script. ', 20), $scene->id);

        $this->assertSame(
            'Original narration text.',
            (string) $scene->refresh()->script_segment,
            'a refused edit still changed the script',
        );
    }

    public function test_a_refused_edit_is_not_charged(): void
    {
        // Otherwise a teacher who hits the wall keeps being billed for edits that never happened.
        [$teacher, $lesson, $scene] = $this->lessonWithScript('Original narration text.');
        $lesson->update(['narration_edit_characters' => NarrationBudget::FREE_CHARACTERS_PER_LESSON]);

        Livewire::actingAs($teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $lesson])
            ->call('selectScene', $scene->id)
            ->call('updateSceneScript', str_repeat('Different. ', 30), $scene->id);

        $this->assertSame(
            NarrationBudget::FREE_CHARACTERS_PER_LESSON,
            NarrationBudget::spentOn($lesson->refresh()),
        );
    }

    public function test_credits_raise_the_allowance_through_one_seam(): void
    {
        // creditCharacters() is the ONLY thing the Stripe ledger has to implement. This pins that
        // contract so the paid tier cannot land somewhere else by accident.
        [, $lesson] = $this->lessonWithScript('Anything.');

        $this->assertSame(
            NarrationBudget::FREE_CHARACTERS_PER_LESSON + NarrationBudget::creditCharacters($lesson->teacher),
            NarrationBudget::allowanceFor($lesson),
        );
    }
}
