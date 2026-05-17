<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Enums\LessonStatus;
use App\Jobs\BuildLessonOutline;
use App\Livewire\Wizard\Step1Settings;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\StrategyGame;
use App\Models\User;
use App\Services\WikipediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class Step1SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private Avatar $avatar;
    private StrategyGame $game;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->teacher = User::factory()->create();
        $this->avatar  = Avatar::create([
            'name' => 'Test', 'slug' => 'test-1', 'gender' => 'male',
            'voice_provider' => 'elevenlabs', 'voice_id' => 'v',
            'voice_speed' => 1.0, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->game = StrategyGame::create([
            'slug' => 'g', 'title' => 'G', 'description' => 'x', 'subject' => 'History',
            'topic_keywords' => [], 'instructions' => 'i',
        ]);

        $this->mock(WikipediaService::class, fn ($m) => $m->shouldReceive('fetchFacts')->andReturn('wiki text'));
    }

    public function test_saves_a_wikipedia_only_lesson_as_draft(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(Step1Settings::class, ['lesson' => null])
            ->set('topic',            'Roman Empire')
            ->set('subject',          'history')
            ->set('grade_level',      '7th grade')
            ->set('source_mode',      'wikipedia')
            ->set('image_style',      'painted')
            ->set('avatar_id',        $this->avatar->id)
            ->set('strategy_game_id', $this->game->id)
            ->set('team_count',       2)
            ->set('game_split_count', 1)
            ->call('saveDraft')
            ->assertHasNoErrors();

        $lesson = Lesson::where('teacher_id', $this->teacher->id)->first();
        $this->assertNotNull($lesson);
        $this->assertSame(LessonStatus::Draft, $lesson->status);
        $this->assertSame('painted', $lesson->image_style);
        $this->assertSame(1, $lesson->game_split_count);

        $this->assertTrue(
            LessonSource::where('lesson_id', $lesson->id)->where('kind', 'wikipedia')->exists()
        );
    }

    public function test_saves_an_upload_mode_lesson_and_stores_extracted_text(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'source.pdf',
            file_get_contents(base_path('tests/Fixtures/documents/sample.pdf')),
        );

        Livewire::actingAs($this->teacher)
            ->test(Step1Settings::class, ['lesson' => null])
            ->set('topic',            'Napoleon')
            ->set('subject',          'history')
            ->set('grade_level',      '9th grade')
            ->set('source_mode',      'upload')
            ->set('sourceUpload',     $file)
            ->set('image_style',      'cinematic')
            ->set('avatar_id',        $this->avatar->id)
            ->set('game_split_count', 1)
            ->call('saveDraft')
            ->assertHasNoErrors();

        $source = LessonSource::first();
        $this->assertSame('pdf', $source->kind);
        $this->assertStringContainsString('Napoleon', $source->extracted_text);
    }

    public function test_dispatches_build_lesson_outline_on_generate(): void
    {
        Bus::fake();

        Livewire::actingAs($this->teacher)
            ->test(Step1Settings::class, ['lesson' => null])
            ->set('topic',            'Roman Empire')
            ->set('subject',          'history')
            ->set('grade_level',      '7th grade')
            ->set('source_mode',      'wikipedia')
            ->set('image_style',      'painted')
            ->set('avatar_id',        $this->avatar->id)
            ->set('game_split_count', 1)
            ->call('generate')
            ->assertHasNoErrors();

        Bus::assertDispatched(BuildLessonOutline::class);
    }

    public function test_rejects_upload_over_10mb_or_wrong_mime(): void
    {
        $tooBig = UploadedFile::fake()->create('big.pdf', 11 * 1024);

        Livewire::actingAs($this->teacher)
            ->test(Step1Settings::class, ['lesson' => null])
            ->set('source_mode', 'upload')
            ->set('sourceUpload', $tooBig)
            ->call('saveDraft')
            ->assertHasErrors(['sourceUpload']);

        $wrong = UploadedFile::fake()->create('thing.xls', 100);

        Livewire::actingAs($this->teacher)
            ->test(Step1Settings::class, ['lesson' => null])
            ->set('source_mode', 'upload')
            ->set('sourceUpload', $wrong)
            ->call('saveDraft')
            ->assertHasErrors(['sourceUpload']);
    }
}
