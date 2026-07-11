<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Livewire\Teacher\LessonChat;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Story;
use App\Models\User;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class LessonChatTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
    }

    private function mockLlm(array $response): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock) use ($response): void {
            $mock->shouldReceive('json')->once()->andReturn($response);
        });
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/teacher/lessons/chat')->assertRedirect('/login');
    }

    public function test_goal_submit_reaches_proposals_with_all_preset_chips_and_llm_suggestion(): void
    {
        $this->mockLlm([
            'topic' => 'Dutch Revolt', 'age' => 8, 'preset_key' => 'game_story',
            'story_query' => 'Willem', 'language' => 'nl',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Students understand why the Dutch revolted against Spain')
            ->call('submitGoal')
            ->assertSet('state', 'proposals')
            ->assertSet('suggestedPresetKey', 'game_story')
            ->assertSet('presetKey', 'game_story')
            ->assertSet('gradeLevel', 'Age 8')
            ->assertSet('topic', 'Dutch Revolt')
            // One chip per LessonPresets entry, suggestion highlighted
            ->assertSee('Story lesson')
            ->assertSee('Spel-verhaal')
            ->assertSee('Comprehension check')
            ->assertSee('Deep dive')
            ->assertSee('Suggested');
    }

    public function test_llm_failure_still_reaches_proposals_with_manual_chips_and_no_suggestion(): void
    {
        $this->mock(OpenAiLlmService::class, function ($mock): void {
            $mock->shouldReceive('json')->once()->andThrow(new RuntimeException('LLM down'));
        });

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Students understand the fall of Rome')
            ->call('submitGoal')
            ->assertSet('state', 'proposals')
            ->assertSet('suggestedPresetKey', null)
            ->assertSet('presetKey', null)
            ->assertSet('gradeLevel', 'Age 12')
            // Free-topic fallback label = the raw goal
            ->assertSet('topic', 'Students understand the fall of Rome')
            ->assertSee('Story lesson')
            ->assertSee('Deep dive')
            ->assertDontSee('Suggested');
    }

    public function test_nonsense_llm_output_is_whitelisted_server_side(): void
    {
        $this->mockLlm([
            'topic' => str_repeat('x', 500), 'age' => 99,
            'preset_key' => 'bogus_type', 'story_query' => 42, 'language' => 'de',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Something about pyramids')
            ->call('submitGoal')
            ->assertSet('state', 'proposals')
            ->assertSet('suggestedPresetKey', null)
            ->assertSet('gradeLevel', 'Age 12');
    }

    public function test_published_story_matches_appear_as_chips_but_draft_stories_never_do(): void
    {
        Story::create(['slug' => 'willem', 'title' => 'Willem van Oranje', 'status' => 'published']);
        Story::create(['slug' => 'willem-draft', 'title' => 'Willem de Draft', 'status' => 'draft']);

        $this->mockLlm([
            'topic' => 'Dutch Revolt', 'age' => 10, 'preset_key' => 'story',
            'story_query' => 'Willem', 'language' => 'nl',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Students understand the Dutch Revolt')
            ->call('submitGoal')
            ->assertSee('Willem van Oranje')
            ->assertDontSee('Willem de Draft');
    }

    /** Caught in browser smoke: full-phrase queries missed shorter titles — matching is per word. */
    public function test_story_matching_is_per_word_so_longer_queries_still_find_short_titles(): void
    {
        Story::create(['slug' => 'limes', 'title' => 'De Romeinse Limes', 'status' => 'published']);

        $this->mockLlm([
            'topic' => 'Romeinse limes en soldaten', 'age' => 12, 'preset_key' => 'story',
            'story_query' => 'Romeinse limes en soldaten', 'language' => 'nl',
        ]);

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Waarom was de limes de noordgrens en hoe leefden soldaten daar')
            ->call('submitGoal')
            ->assertSee('De Romeinse Limes');
    }

    public function test_confirm_and_create_builds_a_preset_configured_story_grounded_lesson(): void
    {
        $story = Story::create([
            'slug' => 'willem', 'title' => 'Willem van Oranje', 'status' => 'published',
            'protagonist_name' => 'Willem van Oranje',
        ]);

        $this->mockLlm([
            'topic' => 'Dutch Revolt', 'age' => 10, 'preset_key' => 'game_story',
            'story_query' => 'Willem', 'language' => 'nl',
        ]);
        Bus::fake();

        $component = Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Students understand the Dutch Revolt')
            ->call('submitGoal')
            ->call('selectGrade', 10)
            ->call('selectStory', $story->id)
            ->assertSet('state', 'confirm')
            ->call('create');

        $lesson = Lesson::where('teacher_id', $this->teacher->id)->sole();

        // Preset defaults applied (game_story = Spel-verhaal)
        $this->assertSame('branching', $lesson->narrative_framework?->value ?? $lesson->narrative_framework);
        $this->assertSame('story_game', $lesson->game_type);
        $this->assertSame(['print_pack' => false], $lesson->game_config);
        $this->assertSame(15 * 60, $lesson->duration_seconds);

        // Grounding + selections
        $this->assertSame($story->id, $lesson->story_id);
        $this->assertSame('Willem van Oranje', $lesson->topic);
        $this->assertSame('Willem van Oranje', $lesson->protagonist_name);
        $this->assertSame('Age 10', $lesson->grade_level);
        $this->assertNull($lesson->title); // pipeline titles it

        // Pipeline started exactly like the wizard: source attached, SourceReady, outline queued
        $this->assertSame('internet', LessonSource::where('lesson_id', $lesson->id)->sole()->kind);
        $this->assertSame(LessonStatus::SourceReady, $lesson->status);
        Bus::assertDispatched(BuildLessonOutline::class, fn ($job) => $job->lessonId === $lesson->id);

        $component->assertRedirect(route('teacher.lessons.wizard', ['lesson' => $lesson->id, 'step' => 3]));
    }

    public function test_free_topic_create_sets_topic_and_no_story(): void
    {
        $this->mockLlm([
            'topic' => 'The pyramids of Giza', 'age' => 12, 'preset_key' => 'story',
            'story_query' => null, 'language' => 'en',
        ]);
        Bus::fake();

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Students learn how the pyramids were built')
            ->call('submitGoal')
            ->call('selectFreeTopic')
            ->assertSet('state', 'confirm')
            ->call('create');

        $lesson = Lesson::where('teacher_id', $this->teacher->id)->sole();
        $this->assertNull($lesson->story_id);
        $this->assertSame('The pyramids of Giza', $lesson->topic);
        $this->assertSame('quiz', $lesson->game_type);
        $this->assertSame(4, $lesson->quiz_question_count);
        $this->assertSame('Students learn how the pyramids were built', $lesson->details);
        Bus::assertDispatched(BuildLessonOutline::class);
    }

    public function test_create_is_idempotent_against_double_clicks(): void
    {
        $this->mockLlm([
            'topic' => 'The pyramids of Giza', 'age' => 12, 'preset_key' => 'story',
            'story_query' => null, 'language' => 'en',
        ]);
        Bus::fake();

        Livewire::actingAs($this->teacher)
            ->test(LessonChat::class)
            ->set('goal', 'Students learn how the pyramids were built')
            ->call('submitGoal')
            ->call('selectFreeTopic')
            ->call('create')
            ->call('create');

        $this->assertSame(1, Lesson::where('teacher_id', $this->teacher->id)->count());
        Bus::assertDispatchedTimes(BuildLessonOutline::class, 1);
    }
}
