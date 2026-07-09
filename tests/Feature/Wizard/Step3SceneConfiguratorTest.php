<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\GenerateSceneAudio;
use App\Jobs\GenerateSceneImage;
use App\Livewire\Wizard\Step3SceneConfigurator;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\StrategyGame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class Step3SceneConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Lesson $lesson;
    private Scene $s1;
    private Scene $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
        $this->lesson  = Lesson::create([
            'teacher_id' => $this->teacher->id,
            'topic' => 'X', 'subject' => 'history', 'grade_level' => '9th',
            'image_style' => 'cinematic', 'status' => LessonStatus::ScenesReady,
        ]);
        $this->s1 = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration',
            'year' => '1810', 'location' => 'Paris', 'script_segment' => 'Old script.',
            'image_path' => 'a.png', 'audio_path' => 'a.mp3', 'audio_script_hash' => sha1('Old script.'),
            'status' => 'ready',
        ]);
        $this->s2 = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'game',
            'game_segment_index' => 1, 'duration_seconds' => 480,
            'script_segment' => 'Game intro.', 'image_path' => 'g.png', 'audio_path' => 'g.mp3',
            'status' => 'ready',
        ]);
    }

    public function test_selects_the_first_scene_on_mount(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->assertSet('selectedSceneId', $this->s1->id);
    }

    public function test_updates_scene_fields_via_inspector(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->set('selectedSceneId', $this->s1->id)
            ->set('selectedScene.year', '1811')
            ->set('selectedScene.location', 'Versailles')
            ->call('saveSelected');

        $this->s1->refresh();
        $this->assertSame('1811', $this->s1->year);
        $this->assertSame('Versailles', $this->s1->location);
    }

    public function test_reorders_scenes(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('reorder', [$this->s2->id, $this->s1->id]);

        $this->assertSame(2, $this->s1->fresh()->order);
        $this->assertSame(1, $this->s2->fresh()->order);
    }

    public function test_regenerates_a_single_asset_via_dispatch(): void
    {
        Bus::fake();
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('regenerate', $this->s1->id, 'image');
        Bus::assertDispatched(\App\Jobs\GenerateSceneShots::class, fn ($j) => $j->sceneId === $this->s1->id);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('regenerate', $this->s1->id, 'audio');
        Bus::assertDispatched(GenerateSceneAudio::class, fn ($j) => $j->sceneId === $this->s1->id);
    }

    public function test_adds_a_blank_narration_scene_at_the_end(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addScene');

        $this->assertSame(3, $this->lesson->scenes()->count());
        $this->assertSame('narration', Scene::orderBy('order', 'desc')->first()->kind);
    }

    public function test_adds_game_scene_with_selected_game_type_defaults(): void
    {
        $game = StrategyGame::create([
            'slug' => 'strategy',
            'title' => 'Strategy',
            'description' => 'x',
            'subject' => 'History',
            'topic_keywords' => [],
            'instructions' => 'i',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addScene', 'game', 'strategy');

        $scene = Scene::orderBy('order', 'desc')->first();
        $this->assertSame('game', $scene->kind);
        $this->assertSame('strategy', $scene->game_type);
        $this->assertSame($game->id, $scene->strategy_game_id);
        $this->assertSame(600, $scene->duration_seconds);
        $this->assertTrue($this->lesson->fresh()->include_game);
    }

    public function test_changes_game_scene_type_and_resets_type_specific_settings(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('setSceneGameType', $this->s2->id, 'quiz');

        $scene = $this->s2->fresh();
        $this->assertSame('quiz', $scene->game_type);
        $this->assertSame(4, $scene->quiz_question_count);
        $this->assertSame('after', $scene->quiz_timing);
        $this->assertNull($scene->strategy_game_id);
    }

    public function test_ai_generates_a_question_with_linked_correct_answer_and_distractors(): void
    {
        $this->mock(\App\Services\OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('json')->once()->andReturn([
                'question' => 'Why did Napoleon invade Russia?',
                'correct_answer' => 'To force the Tsar back into the Continental System.',
                'distractors' => ['To conquer Siberia.', 'To free the serfs.', 'To find a warm-water port.'],
                'explanation' => 'The invasion aimed to enforce the blockade of Britain.',
            ]));

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addQuizQuestion')
            ->call('generateQuizQuestion', 0);

        $draft = $component->get('quizDraft')[0];
        $this->assertSame('Why did Napoleon invade Russia?', $draft['question']);
        $this->assertCount(4, array_filter($draft['options']));
        // The correct answer sits at correct_index — linked and pinned.
        $this->assertSame(
            'To force the Tsar back into the Continental System.',
            $draft['options'][$draft['correct_index']],
        );
    }

    public function test_correct_answer_cannot_be_redrawn_but_distractors_can(): void
    {
        $this->mock(\App\Services\OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('json')->once()->andReturn(['distractor' => 'A fresh plausible wrong answer.']));

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addQuizQuestion')
            ->set('quizDraft.0.question', 'Q?')
            ->set('quizDraft.0.options', ['Correct', 'Wrong A', 'Wrong B', 'Wrong C'])
            ->set('quizDraft.0.correct_index', 0)
            // Slot 0 is the correct answer: redraw must be a no-op (no LLM call).
            ->call('regenerateQuizOption', 0, 0)
            // Slot 2 is a distractor: redraw replaces it.
            ->call('regenerateQuizOption', 0, 2);

        $draft = $component->get('quizDraft')[0];
        $this->assertSame('Correct', $draft['options'][0]);
        $this->assertSame('A fresh plausible wrong answer.', $draft['options'][2]);
    }

    public function test_reordering_answers_moves_the_correct_answer_but_keeps_it_pinned(): void
    {
        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('addQuizQuestion')
            ->set('quizDraft.0.options', ['Correct', 'Wrong A', 'Wrong B', 'Wrong C'])
            ->set('quizDraft.0.correct_index', 0)
            // Drag the correct answer from slot 0 to slot 3 (A → D).
            ->call('moveQuizOption', 0, 0, 3);

        $draft = $component->get('quizDraft')[0];
        $this->assertSame(['Wrong A', 'Wrong B', 'Wrong C', 'Correct'], $draft['options']);
        $this->assertSame(3, $draft['correct_index']);

        // Drag a distractor over the correct answer: correctness shifts with its option.
        $component->call('moveQuizOption', 0, 0, 3);
        $draft = $component->get('quizDraft')[0];
        $this->assertSame(['Wrong B', 'Wrong C', 'Correct', 'Wrong A'], $draft['options']);
        $this->assertSame(2, $draft['correct_index']);
    }

    public function test_quiz_edits_autosave_complete_questions_and_keep_incomplete_drafts(): void
    {
        $this->s2->update(['game_type' => 'quiz']);

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('addQuizQuestion')
            ->call('addQuizQuestion')
            // Question 0 complete; question 1 only half-typed.
            ->set('quizDraft.0.question', 'Why did the empire fall?')
            ->set('quizDraft.0.options', ['Overexpansion', 'A lost bet', 'Bad weather', 'Alien invasion'])
            ->set('quizDraft.1.question', 'Unfinished question…');

        // The complete question persisted without any Save click; the half one stayed draft-only.
        $saved = $this->lesson->quizQuestions()->get();
        $this->assertCount(1, $saved);
        $this->assertSame('Why did the empire fall?', $saved->first()->question);
        $this->assertTrue($component->get('quizSaved'));
        $this->assertNotEmpty($component->get('quizErrors')[1] ?? null, 'Half-typed question shows inline errors');
        $this->assertCount(2, $component->get('quizDraft'), 'Draft keeps the incomplete question');
    }

    public function test_deleting_the_scene_image_falls_back_to_brand_navy(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('lessons/x/scene.png', 'png');
        \Illuminate\Support\Facades\Storage::disk('public')->put('lessons/x/shot_0.png', 'png');
        $this->s1->update([
            'image_path' => 'lessons/x/scene.png',
            'shots' => [['order' => 1, 'image_path' => 'lessons/x/shot_0.png', 'anchor_sentence' => 'x']],
        ]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('deleteSceneImage', $this->s1->id);

        $scene = $this->s1->fresh();
        $this->assertNull($scene->image_path);
        $this->assertNull($scene->shots);
        $this->assertSame(Step3SceneConfigurator::BRAND_BACKGROUND, $scene->background_color);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('lessons/x/scene.png');
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('lessons/x/shot_0.png');
    }

    public function test_quiz_scope_override_persists_on_the_quiz_scene_config(): void
    {
        $this->s2->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizScope', 'full');

        $this->assertSame('full', $this->s2->fresh()->config['quiz_scope']);

        // Garbage scopes are ignored.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizScope', 'everything');

        $this->assertSame('full', $this->s2->fresh()->config['quiz_scope']);
    }

    public function test_difficulty_stars_persist_on_the_quiz_scene_config(): void
    {
        $this->s2->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizDifficulty', 3);

        $this->assertSame(3, $this->s2->fresh()->config['quiz_difficulty']);

        // Out-of-range levels are ignored.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizDifficulty', 7);

        $this->assertSame(3, $this->s2->fresh()->config['quiz_difficulty']);
    }

    public function test_regenerate_all_replaces_the_question_pool_and_reloads_the_draft(): void
    {
        $this->lesson->quizQuestions()->create([
            'order' => 1, 'question' => 'Old question?',
            'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0,
        ]);

        $this->mock(\App\Services\OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('json')->once()->andReturn(['questions' => [
                ['order' => 1, 'question' => 'Why did the war start?', 'options' => ['Trade dispute', 'Royal marriage', 'Border raid', 'Famine'], 'correct_index' => 0],
                ['order' => 2, 'question' => 'What ended the siege?', 'options' => ['Winter', 'Treaty', 'Betrayal', 'Plague'], 'correct_index' => 1],
            ]]));

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('regenerateAllQuizQuestions');

        $draft = $component->get('quizDraft');
        $this->assertCount(2, $draft);
        $this->assertSame('Why did the war start?', $draft[0]['question']);
        $this->assertSame(1, $draft[1]['correct_index']);
    }

    public function test_background_color_sets_and_persists_in_one_call(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s1->id)
            ->call('setSceneBackgroundColor', '#3f0d12');

        $this->assertSame('#3f0d12', $this->s1->fresh()->background_color);

        // Garbage input is rejected, color unchanged.
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s1->id)
            ->call('setSceneBackgroundColor', 'javascript:alert(1)');

        $this->assertSame('#3f0d12', $this->s1->fresh()->background_color);
    }

    public function test_scene_texts_with_null_scene_id_fall_back_to_the_selected_scene(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s1->id)
            ->dispatch('sceneTextsChanged', sceneId: null, texts: [
                ['id' => 'txt_a', 'text' => 'Orphan text box', 'x' => 40, 'y' => 40],
            ]);

        $this->assertSame('Orphan text box', $this->s1->fresh()->config['texts'][0]['text']);
    }

    public function test_scene_texts_save_sanitized_and_clamped(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->dispatch('sceneTextsChanged', sceneId: $this->s1->id, texts: [
                ['id' => 'txt_a', 'text' => '  Napoleon crosses the Alps  ', 'x' => 120, 'y' => -5],
                ['id' => 'txt_b', 'text' => 'https://en.wikipedia.org/wiki/Napoleon', 'x' => 10, 'y' => 20],
                ['id' => 'txt_c', 'text' => '   ', 'x' => 50, 'y' => 50],   // empty → dropped
            ]);

        $texts = $this->s1->fresh()->config['texts'];
        $this->assertCount(2, $texts);
        $this->assertSame('Napoleon crosses the Alps', $texts[0]['text']);
        $this->assertEquals(100, $texts[0]['x'], 'x clamps to 0-100');
        $this->assertEquals(0, $texts[0]['y'], 'y clamps to 0-100');
        $this->assertSame('https://en.wikipedia.org/wiki/Napoleon', $texts[1]['text']);
    }

    public function test_deletes_a_scene_and_reindexes_game_segments(): void
    {
        $g2 = Scene::create([
            'lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'game',
            'game_segment_index' => 2, 'status' => 'ready',
        ]);
        $this->lesson->update(['game_split_count' => 2]);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('deleteScene', $this->s2->id);

        $this->assertNull(Scene::find($this->s2->id));
        $this->assertSame(1, $g2->fresh()->game_segment_index);
        $this->assertSame(1, $this->lesson->fresh()->game_split_count);
    }

    public function test_generating_a_question_preserves_the_asks_ahead_flag(): void
    {
        $this->s2->update(['game_type' => 'quiz']);
        // A prior-knowledge question flagged by the pipeline: excluded from difficulty stats.
        $this->lesson->quizQuestions()->create([
            'scene_id' => $this->s2->id, 'order' => 0, 'question' => 'What comes later?',
            'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0, 'asks_ahead' => true,
        ]);

        $this->mock(\App\Services\OpenAiLlmService::class, fn ($mock) => $mock
            ->shouldReceive('json')->once()->andReturn([
                'question' => 'What will Napoleon do at Waterloo?',
                'correct_answer' => 'Lose.',
                'distractors' => ['Win.', 'Flee to Egypt.', 'Abdicate twice.'],
                'explanation' => 'x',
            ]));

        $component = Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('generateQuizQuestion', 0);

        // Redrawing the text must not silently reset the flag — a prior-knowledge question
        // that loses it would wrongly count toward the class's "difficult questions".
        $this->assertTrue((bool) $component->get('quizDraft')[0]['asks_ahead']);
        $this->assertTrue((bool) $this->lesson->quizQuestions()->first()->asks_ahead);
    }

    public function test_quiz_shuffle_setting_persists_and_rejects_garbage(): void
    {
        $this->s2->update(['game_type' => 'quiz']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizShuffle', 'once');
        $this->assertSame('once', $this->s2->fresh()->config['quiz_shuffle']);

        Livewire::actingAs($this->teacher)
            ->test(Step3SceneConfigurator::class, ['lesson' => $this->lesson])
            ->call('selectScene', $this->s2->id)
            ->call('setQuizShuffle', 'chaos');
        $this->assertSame('once', $this->s2->fresh()->config['quiz_shuffle']);
    }
}
