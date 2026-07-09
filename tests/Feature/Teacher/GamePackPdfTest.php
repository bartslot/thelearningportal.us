<?php

declare(strict_types=1);

namespace Tests\Feature\Teacher;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateGamePack;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;
use App\Services\GameCardPdfService;
use App\Services\OpenAiLlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GamePackPdfTest extends TestCase
{
    use RefreshDatabase;

    /** A full story_game lesson: 4 meters, 5 roles, 3 branch groups persisted as scene rows. */
    private function makeStoryGameLesson(User $teacher): Lesson
    {
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title' => 'Napoleon: veldtocht als spel',
            'topic' => 'Napoleon',
            'subject' => 'history',
            'grade_level' => '8',
            'status' => LessonStatus::Published,
            'game_type' => 'story_game',
            'game_config' => [
                'print_pack' => true,
                'fail_threshold' => 0,
                'meters' => [
                    ['key' => 'morale',   'label' => 'Moreel',      'icon' => '🔥', 'start' => 70],
                    ['key' => 'troops',   'label' => 'Manschappen', 'icon' => '👥', 'start' => 80],
                    ['key' => 'supplies', 'label' => 'Voorraden',   'icon' => '📦', 'start' => 60],
                    ['key' => 'support',  'label' => 'Steun',       'icon' => '❤️', 'start' => 50],
                ],
                'roles' => [
                    ['title' => 'Commandant',   'flavor' => 'Jullie voeren het bevel.',        'power' => 'Jullie team hakt de knoop door.'],
                    ['title' => 'Verkenner',    'flavor' => 'Jullie zien wat anderen missen.', 'power' => 'Jullie team ziet één effect vooraf.'],
                    ['title' => 'Dokter',       'flavor' => 'Jullie lappen de troepen op.',    'power' => 'Jullie herstelt één token.'],
                    ['title' => 'Spion',        'flavor' => 'Jullie werken in de schaduw.',    'power' => 'Jullie mag één optie omwisselen.'],
                    ['title' => 'Boodschapper', 'flavor' => 'Jullie brengen het nieuws.',      'power' => 'Jullie leest het gevolg voor.'],
                ],
            ],
        ]);

        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'script_segment' => 'Het kamp ontwaakt in de mist.']);
        foreach ([1, 2, 3] as $group) {
            Scene::create([
                'lesson_id' => $lesson->id, 'order' => $group * 3, 'kind' => 'narration',
                'branch_group' => $group, 'branch_role' => 'question',
                'script_segment' => "Keuzemoment {$group}: de verkenners melden dat de grond nog nat is. Wat doet het bevel?",
            ]);
            Scene::create([
                'lesson_id' => $lesson->id, 'order' => $group * 3 + 1, 'kind' => 'narration',
                'branch_group' => $group, 'branch_role' => 'option_a', 'branch_choice_label' => 'Stuur de cavalerie',
                'config' => ['branch_effects' => ['deltas' => ['morale' => 10], 'consequence_line' => 'Doorbraak.', 'historical_note' => '...']],
            ]);
            Scene::create([
                'lesson_id' => $lesson->id, 'order' => $group * 3 + 2, 'kind' => 'narration',
                'branch_group' => $group, 'branch_role' => 'option_b', 'branch_choice_label' => 'Wacht op droge grond',
                'config' => ['branch_effects' => ['deltas' => ['supplies' => -10], 'consequence_line' => 'Wachten.', 'historical_note' => '...']],
            ]);
        }

        return $lesson;
    }

    public function test_pack_returns_pdf_bytes_for_a_full_story_game_lesson(): void
    {
        $lesson = $this->makeStoryGameLesson(User::factory()->create());

        $bytes = app(GameCardPdfService::class)->pack($lesson);

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_pack_renders_with_partial_data_without_crashing(): void
    {
        // Missing roles + meters entirely; no branch scenes.
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id,
            'title' => 'Rome',
            'topic' => 'Rome',
            'subject' => 'history',
            'grade_level' => '7',
            'status' => LessonStatus::Published,
            'game_type' => 'story_game',
            'game_config' => ['print_pack' => true],
        ]);

        $bytes = app(GameCardPdfService::class)->pack($lesson);

        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_route_returns_pdf_for_owner_when_path_set(): void
    {
        Storage::fake('public');
        $teacher = User::factory()->create();
        $lesson = $this->makeStoryGameLesson($teacher);
        Storage::disk('public')->put("lessons/{$lesson->id}/game-pack.pdf", '%PDF-fake');
        $lesson->update(['game_pack_path' => "lessons/{$lesson->id}/game-pack.pdf"]);

        $this->actingAs($teacher)
            ->get("/teacher/lessons/{$lesson->id}/print/game-pack")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_route_404_when_no_path(): void
    {
        $teacher = User::factory()->create();
        $lesson = $this->makeStoryGameLesson($teacher);

        $this->actingAs($teacher)
            ->get("/teacher/lessons/{$lesson->id}/print/game-pack")
            ->assertNotFound();
    }

    public function test_route_403_for_non_owner(): void
    {
        Storage::fake('public');
        $teacher = User::factory()->create();
        $lesson = $this->makeStoryGameLesson($teacher);
        Storage::disk('public')->put("lessons/{$lesson->id}/game-pack.pdf", '%PDF-fake');
        $lesson->update(['game_pack_path' => "lessons/{$lesson->id}/game-pack.pdf"]);

        $this->actingAs(User::factory()->create())
            ->get("/teacher/lessons/{$lesson->id}/print/game-pack")
            ->assertForbidden();
    }

    public function test_generate_game_pack_stores_file_and_sets_path(): void
    {
        Storage::fake('public');
        $lesson = $this->makeStoryGameLesson(User::factory()->create());

        (new GenerateGamePack($lesson->id))->handle(app(GameCardPdfService::class));

        $path = "lessons/{$lesson->id}/game-pack.pdf";
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $lesson->refresh()->game_pack_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($path));
    }

    public function test_pipeline_dispatches_game_pack_when_print_pack_true(): void
    {
        $lesson = $this->makeOutlineLesson(['print_pack' => true]);

        $this->mockOutline();
        Bus::fake();

        (new BuildLessonOutline($lesson->id))->handle(app(OpenAiLlmService::class));

        Bus::assertDispatched(GenerateGamePack::class);
    }

    public function test_pipeline_does_not_dispatch_game_pack_when_print_pack_false(): void
    {
        $lesson = $this->makeOutlineLesson(['print_pack' => false]);

        $this->mockOutline();
        Bus::fake();

        (new BuildLessonOutline($lesson->id))->handle(app(OpenAiLlmService::class));

        Bus::assertNotDispatched(GenerateGamePack::class);
    }

    private function makeOutlineLesson(array $gameConfig): Lesson
    {
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id,
            'topic' => 'Napoleonic Campaigns',
            'subject' => 'history',
            'grade_level' => '9th grade',
            'image_style' => 'painted',
            'source_mode' => 'wikipedia',
            'status' => LessonStatus::SourceReady,
            'include_game' => true,
            'game_type' => 'story_game',
            'narrative_framework' => 'branching',
            'game_config' => $gameConfig,
        ]);
        LessonSource::create([
            'lesson_id' => $lesson->id,
            'kind' => 'wikipedia',
            'extracted_text' => 'Napoleon Bonaparte was a French statesman…',
        ]);

        return $lesson;
    }

    private function mockOutline(): void
    {
        $briefs = [['kind' => 'narration', 'beat' => 'setup', 'image_prompt_seed' => 'Camp']];
        foreach ([1, 2, 3] as $group) {
            $briefs[] = ['kind' => 'narration', 'branch' => ['group' => $group, 'role' => 'question']];
            $briefs[] = ['kind' => 'narration', 'branch' => ['group' => $group, 'role' => 'option_a', 'choice_label' => 'A'],
                'branch_effects' => ['deltas' => ['morale' => 10], 'consequence_line' => 'x', 'historical_note' => 'y']];
            $briefs[] = ['kind' => 'narration', 'branch' => ['group' => $group, 'role' => 'option_b', 'choice_label' => 'B'],
                'branch_effects' => ['deltas' => ['troops' => -10], 'consequence_line' => 'x', 'historical_note' => 'y']];
        }

        $this->mock(OpenAiLlmService::class, function ($mock) use ($briefs): void {
            $mock->shouldReceive('json')->once()->andReturn([
                'title' => 'Napoleon',
                'meters' => [
                    ['key' => 'morale',   'label' => 'Moreel',      'icon' => '🔥', 'start' => 70],
                    ['key' => 'troops',   'label' => 'Manschappen', 'icon' => '👥', 'start' => 80],
                    ['key' => 'supplies', 'label' => 'Voorraden',   'icon' => '📦', 'start' => 60],
                    ['key' => 'support',  'label' => 'Steun',       'icon' => '❤️', 'start' => 50],
                ],
                'roles' => [['title' => 'Commandant', 'flavor' => 'f', 'power' => 'p']],
                'scene_briefs' => $briefs,
            ]);
        });
    }
}
