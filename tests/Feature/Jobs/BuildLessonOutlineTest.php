<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BuildLessonOutlineTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $teacher = User::factory()->create();
        $this->lesson = Lesson::create([
            'teacher_id'  => $teacher->id,
            'topic'       => 'Napoleonic Campaigns',
            'subject'     => 'history',
            'grade_level' => '9th grade',
            'image_style' => 'painted',
            'source_mode' => 'wikipedia',
            'status'      => LessonStatus::SourceReady,
        ]);
        LessonSource::create([
            'lesson_id'      => $this->lesson->id,
            'kind'           => 'wikipedia',
            'extracted_text' => 'Napoleon Bonaparte was a French statesman…',
        ]);
    }

    public function test_writes_scene_rows_from_llm_json_and_dispatches_script_job(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title'        => 'Napoleon: Rise and Fall',
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris',  'beat' => 'intro',    'image_prompt_seed' => 'Paris dusk 1810'],
                    ['order' => 2, 'kind' => 'narration', 'year' => '1812', 'location' => 'Moscow', 'beat' => 'invasion', 'image_prompt_seed' => 'Burning Moscow'],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->lesson->refresh();
        $this->assertSame('Napoleon: Rise and Fall', $this->lesson->title);
        $this->assertSame(LessonStatus::ScenesGenerating, $this->lesson->status);
        $this->assertSame(2, Scene::count());

        // The whole-lesson script job owns the per-scene asset fan-out now.
        Bus::assertDispatched(\App\Jobs\GenerateLessonScript::class);
        Bus::assertNothingBatched();
    }

    public function test_drops_game_scenes_when_games_are_disabled(): void
    {
        $this->lesson->update(['include_game' => false]);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title'        => 'Napoleon: Rise and Fall',
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris',    'beat' => 'intro',  'image_prompt_seed' => 'Paris dusk'],
                    ['order' => 2, 'kind' => 'game',      'year' => '1812', 'location' => 'Moscow',   'beat' => 'quiz',   'image_prompt_seed' => 'Quiz'],
                    ['order' => 3, 'kind' => 'narration', 'year' => '1815', 'location' => 'Waterloo', 'beat' => 'climax', 'image_prompt_seed' => 'Waterloo'],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(0, Scene::where('kind', 'game')->count(), 'Game scenes must be stripped when include_game is off');
        $this->assertSame(2, Scene::where('kind', 'narration')->count());
        // The mid-list game brief was removed and the remaining scenes re-indexed to contiguous orders.
        $this->assertSame([1, 2], Scene::where('kind', 'narration')->orderBy('order')->pluck('order')->all());
    }

    public function test_keeps_game_scenes_when_games_are_enabled(): void
    {
        $this->lesson->update(['include_game' => true, 'game_type' => 'quiz']);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title'        => 'Napoleon',
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris',  'beat' => 'intro', 'image_prompt_seed' => 'Paris'],
                    ['order' => 2, 'kind' => 'game',      'year' => '1812', 'location' => 'Moscow', 'beat' => 'quiz',  'image_prompt_seed' => 'Quiz', 'game_segment_index' => 1],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(1, Scene::where('kind', 'game')->count(), 'Game scenes must be kept when include_game is on');
    }

    public function test_catalog_story_grounds_the_source_and_overrides_objectives(): void
    {
        $story = \App\Models\Story::create([
            'slug' => 'napoleon-russia',
            'title' => 'Napoleon in Russia',
            'status' => 'published',
            'learning_objectives' => [
                ['id' => 'LO1', 'text' => 'Explain why the invasion of Russia failed.', 'bloom' => 'understand'],
            ],
        ]);
        \App\Models\StorySource::create([
            'story_id' => $story->id,
            'origin' => 'gutenberg',
            'title' => 'Famous Men of Modern Times — Napoleon',
            'excerpt' => 'In 1812 Napoleon led the Grande Armée into Russia…',
        ]);
        $this->lesson->update(['story_id' => $story->id]);
        $this->lesson->source->update(['extracted_text' => '']);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title' => 'Napoleon in Russia',
                'learning_objectives' => [
                    ['id' => 'LOX', 'text' => 'Model-invented objective (must be overridden).'],
                ],
                'scene_briefs' => [
                    ['order' => 1, 'kind' => 'narration', 'beat' => 'march'],
                    ['order' => 2, 'kind' => 'narration', 'beat' => 'winter'],
                ],
            ]);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->lesson->refresh();
        // Source text came from the story excerpt, not Wikipedia.
        $this->assertStringContainsString('Grande Armée', (string) $this->lesson->source->extracted_text);
        // Curated objectives override whatever the model emitted.
        $this->assertSame('LO1', $this->lesson->outline['learning_objectives'][0]['id']);
    }

    public function test_story_game_outline_persists_branch_scenes_meters_and_roles(): void
    {
        $this->lesson->update([
            'include_game' => true,
            'game_type' => 'story_game',
            'narrative_framework' => 'branching',
            'game_config' => ['print_pack' => true],
        ]);

        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn($this->storyGameOutline());
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        // 3 branch groups → 3 question + 6 option scenes carry branch columns.
        $this->assertSame(3, Scene::where('branch_role', 'question')->count());
        $this->assertSame(6, Scene::whereIn('branch_role', ['option_a', 'option_b'])->count());
        $this->assertSame([1, 2, 3], Scene::where('branch_role', 'question')->orderBy('branch_group')->pluck('branch_group')->all());

        $question = Scene::where('branch_group', 1)->where('branch_role', 'question')->firstOrFail();
        $this->assertNull($question->branch_choice_label);
        $this->assertNull($question->config);

        $option = Scene::where('branch_group', 1)->where('branch_role', 'option_a')->firstOrFail();
        $this->assertSame('Stuur de cavalerie', $option->branch_choice_label);
        $this->assertSame(
            ['morale' => 10, 'troops' => -15, 'supplies' => 0, 'support' => 5],
            $option->config['branch_effects']['deltas'],
        );
        $this->assertSame('De cavalerie breekt door de linies.', $option->config['branch_effects']['consequence_line']);
        $this->assertSame('Napoleon stelde de aanval in werkelijkheid uit.', $option->config['branch_effects']['historical_note']);

        // Setup/end scenes stay branch-free.
        $this->assertNull(Scene::orderBy('order')->firstOrFail()->branch_group);

        $this->lesson->refresh();
        $config = $this->lesson->game_config;
        $this->assertTrue($config['print_pack'], 'Existing game_config keys must survive the merge');
        $this->assertSame(0, $config['fail_threshold']);
        $this->assertSame(['morale', 'troops', 'supplies', 'support'], array_column($config['meters'], 'key'));
        $this->assertSame('Moreel', $config['meters'][0]['label']);
        $this->assertCount(5, $config['roles']);
        $this->assertSame('Commandant', $config['roles'][0]['title']);
    }

    public function test_story_game_clamps_deltas_and_drops_unknown_meter_keys(): void
    {
        $this->lesson->update(['include_game' => true, 'game_type' => 'story_game', 'narrative_framework' => 'branching']);

        $outline = $this->storyGameOutline();
        // option_a of group 1: out-of-range + unknown + missing meter keys.
        $outline['scene_briefs'][2]['branch_effects']['deltas'] = [
            'morale' => 40,        // above +25 → clamp
            'troops' => -90,       // below −25 → clamp
            'gold' => 12,          // unknown meter → drop
            // supplies + support missing → default 0
        ];

        $this->mock(OpenAiLlmService::class, function ($mock) use ($outline): void {
            $mock->shouldReceive('json')->once()->andReturn($outline);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $option = Scene::where('branch_group', 1)->where('branch_role', 'option_a')->firstOrFail();
        $this->assertSame(
            ['morale' => 25, 'troops' => -25, 'supplies' => 0, 'support' => 0],
            $option->config['branch_effects']['deltas'],
        );
    }

    public function test_story_game_falls_back_to_default_meters_when_llm_returns_garbage(): void
    {
        $this->lesson->update(['include_game' => true, 'game_type' => 'story_game', 'narrative_framework' => 'branching']);

        $outline = $this->storyGameOutline();
        $outline['meters'] = [['key' => 'only_one', 'label' => 'Eén', 'start' => 'not-a-number'], 'garbage'];

        $this->mock(OpenAiLlmService::class, function ($mock) use ($outline): void {
            $mock->shouldReceive('json')->once()->andReturn($outline);
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->lesson->refresh();
        $this->assertSame(LessonStatus::ScenesGenerating, $this->lesson->status, 'Garbage meters must not fail the pipeline');
        $meters = $this->lesson->game_config['meters'];
        $this->assertSame(['morale', 'troops', 'supplies', 'support'], array_column($meters, 'key'));
        $this->assertSame(['Moreel', 'Manschappen', 'Voorraden', 'Steun'], array_column($meters, 'label'));
        $this->assertSame([60, 60, 60, 60], array_column($meters, 'start'));

        // Option deltas are sanitized against the FALLBACK meter keys.
        $option = Scene::where('branch_group', 1)->where('branch_role', 'option_a')->firstOrFail();
        $this->assertSame(['morale', 'troops', 'supplies', 'support'], array_keys($option->config['branch_effects']['deltas']));
    }

    public function test_non_story_game_lesson_ignores_branch_markers_and_game_config(): void
    {
        $this->lesson->update(['include_game' => true, 'game_type' => 'quiz']);

        // Even if the model hallucinates story-game fields, a quiz lesson must ignore them.
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andReturn($this->storyGameOutline());
        });

        Bus::fake();

        (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));

        $this->assertSame(0, Scene::whereNotNull('branch_group')->count());
        $this->assertNull(Scene::orderBy('order')->firstOrFail()->config);
        $this->assertNull($this->lesson->refresh()->game_config);
    }

    /**
     * A full spel-verhaal outline: 4 meters, 5 team roles, and 3 branch groups
     * (question + option_a + option_b each) between a setup and an end scene.
     *
     * @return array<string, mixed>
     */
    private function storyGameOutline(): array
    {
        $briefs = [
            ['kind' => 'narration', 'beat' => 'setup', 'image_prompt_seed' => 'Camp at dawn'],
        ];
        foreach ([1, 2, 3] as $group) {
            $briefs[] = [
                'kind' => 'narration', 'beat' => "choice {$group}", 'image_prompt_seed' => "Choice {$group}",
                'branch' => ['group' => $group, 'role' => 'question'],
            ];
            $briefs[] = [
                'kind' => 'narration', 'beat' => "option a {$group}", 'image_prompt_seed' => "Option A {$group}",
                'branch' => ['group' => $group, 'role' => 'option_a', 'choice_label' => 'Stuur de cavalerie'],
                'branch_effects' => [
                    'deltas' => ['morale' => 10, 'troops' => -15, 'supplies' => 0, 'support' => 5],
                    'consequence_line' => 'De cavalerie breekt door de linies.',
                    'historical_note' => 'Napoleon stelde de aanval in werkelijkheid uit.',
                ],
            ];
            $briefs[] = [
                'kind' => 'narration', 'beat' => "option b {$group}", 'image_prompt_seed' => "Option B {$group}",
                'branch' => ['group' => $group, 'role' => 'option_b', 'choice_label' => 'Wacht op droge grond'],
                'branch_effects' => [
                    'deltas' => ['morale' => -5, 'troops' => 0, 'supplies' => -10, 'support' => 10],
                    'consequence_line' => 'Het wachten vreet aan de voorraden.',
                    'historical_note' => 'Precies wat Napoleon werkelijk deed.',
                ],
            ];
        }
        $briefs[] = ['kind' => 'narration', 'beat' => 'end', 'image_prompt_seed' => 'Aftermath'];

        return [
            'title' => 'Napoleon: veldtocht als spel',
            'meters' => [
                ['key' => 'morale',   'label' => 'Moreel',      'icon' => '🔥', 'start' => 70],
                ['key' => 'troops',   'label' => 'Manschappen', 'icon' => '👥', 'start' => 80],
                ['key' => 'supplies', 'label' => 'Voorraden',   'icon' => '📦', 'start' => 60],
                ['key' => 'support',  'label' => 'Steun',       'icon' => '❤️', 'start' => 50],
            ],
            'roles' => [
                ['title' => 'Commandant',  'flavor' => 'Jullie voeren het bevel.',        'power' => 'Jullie team hakt deze ronde de knoop door.'],
                ['title' => 'Verkenner',   'flavor' => 'Jullie zien wat anderen missen.', 'power' => 'Jullie team mag één meter-effect vooraf inzien.'],
                ['title' => 'Dokter',      'flavor' => 'Jullie lappen de troepen op.',    'power' => 'Jullie team herstelt één verloren manschappen-token.'],
                ['title' => 'Spion',       'flavor' => 'Jullie werken in de schaduw.',    'power' => 'Jullie team mag één keer een optie omwisselen.'],
                ['title' => 'Boodschapper','flavor' => 'Jullie brengen het nieuws.',      'power' => 'Jullie team leest het gevolg hardop voor.'],
            ],
            'scene_briefs' => array_map(
                fn (array $brief, int $idx) => $brief + ['order' => $idx + 1],
                $briefs,
                array_keys($briefs),
            ),
        ];
    }

    public function test_marks_lesson_failed_and_writes_error_message_on_llm_exception(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->andThrow(new \RuntimeException('OpenAI down'));
        });

        try {
            (new BuildLessonOutline($this->lesson->id))->handle(app(OpenAiLlmService::class));
        } catch (\Throwable) {
            // expected
        }

        $this->lesson->refresh();
        $this->assertSame(LessonStatus::Failed, $this->lesson->status);
        $this->assertStringContainsString('OpenAI down', (string) $this->lesson->error_message);
    }
}
