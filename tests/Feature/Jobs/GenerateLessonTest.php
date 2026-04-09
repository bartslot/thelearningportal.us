<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\LessonStatus;
use App\Jobs\GenerateLesson;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AvatarService;
use App\Services\ImageSearchService;
use App\Services\LlmService;
use App\Services\TtsService;
use App\Services\WikipediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateLessonTest extends TestCase
{
    use RefreshDatabase;

    private function makeLesson(array $overrides = []): Lesson
    {
        return Lesson::factory()->create(array_merge([
            'teacher_id'  => User::factory()->teacher()->create()->id,
            'topic'       => 'Julius Caesar',
            'grade_level' => '8th grade',
            'tone'        => 'calm and authoritative',
            'details'     => null,
        ], $overrides));
    }

    /** Default LLM response — valid title, script, and 4 questions. */
    private function llmResult(string $script = 'Julius Caesar was a Roman general who shaped history.'): array
    {
        return [
            'title'  => 'The Rise and Fall of Julius Caesar',
            'script' => $script,
            'questions' => [
                ['question' => 'What was Caesar?',    'options' => ['A Roman general', 'A Greek philosopher', 'An Egyptian pharaoh', 'A Viking warrior'], 'correct_index' => 0, 'explanation' => 'Caesar was a Roman general.'],
                ['question' => 'What did he shape?',  'options' => ['History', 'Science', 'Art', 'Music'], 'correct_index' => 0, 'explanation' => 'He shaped history.'],
                ['question' => 'Where was he from?',  'options' => ['Rome', 'Athens', 'Cairo', 'Oslo'],    'correct_index' => 0, 'explanation' => 'He was Roman.'],
                ['question' => 'What was his role?',  'options' => ['General', 'Philosopher', 'Pharaoh', 'Poet'], 'correct_index' => 0, 'explanation' => 'He was a general.'],
            ],
        ];
    }

    private function bindMocks(
        ?string $facts = 'Julius Caesar was a Roman general.',
        ?array  $llmResult = null,
        ?string $audioPath = 'lessons/1/audio.mp3',
    ): void {
        $llmResult ??= $this->llmResult();

        $wikipedia = Mockery::mock(WikipediaService::class);
        $wikipedia->shouldReceive('fetchFacts')->once()->andReturn($facts);
        $this->app->instance(WikipediaService::class, $wikipedia);

        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('generate')->once()->andReturn($llmResult);
        $this->app->instance(LlmService::class, $llm);

        $tts = Mockery::mock(TtsService::class);
        $tts->shouldReceive('generateAudio')->andReturn($audioPath);
        $this->app->instance(TtsService::class, $tts);

        $avatar = Mockery::mock(AvatarService::class);
        $avatar->shouldReceive('generateVideo')->andReturn(null);
        $avatar->shouldReceive('downloadPortrait')->andReturn(null);
        $avatar->shouldReceive('useDefaultPortrait')->andReturn(null);
        $this->app->instance(AvatarService::class, $avatar);

        $imageSearch = Mockery::mock(ImageSearchService::class);
        $imageSearch->shouldReceive('fetchImages')->andReturn([]);
        $this->app->instance(ImageSearchService::class, $imageSearch);
    }

    private function runJob(Lesson $lesson): void
    {
        (new GenerateLesson($lesson->id))->handle(
            app(WikipediaService::class),
            app(LlmService::class),
            app(TtsService::class),
            app(AvatarService::class),
            app(ImageSearchService::class),
        );
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_full_pipeline_sets_lesson_to_ready(): void
    {
        $lesson = $this->makeLesson();
        $this->bindMocks(audioPath: "lessons/{$lesson->id}/audio.mp3");

        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals(LessonStatus::Ready, $lesson->status);
        $this->assertNotNull($lesson->script);
        $this->assertNotNull($lesson->title);
        $this->assertNotNull($lesson->audio_path);
        $this->assertNotNull($lesson->wikipedia_source);
    }

    public function test_llm_generated_title_is_saved(): void
    {
        $lesson = $this->makeLesson();
        $this->bindMocks();

        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals('The Rise and Fall of Julius Caesar', $lesson->title);
    }

    public function test_quiz_questions_are_created(): void
    {
        $lesson = $this->makeLesson();
        $this->bindMocks();

        $this->runJob($lesson);

        $this->assertCount(4, $lesson->quizQuestions()->get());
    }

    public function test_generation_attempt_counter_increments(): void
    {
        $lesson = $this->makeLesson();
        $this->assertEquals(0, $lesson->generation_attempts);

        $this->bindMocks();
        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals(1, $lesson->generation_attempts);
    }

    public function test_details_and_tone_are_passed_to_llm(): void
    {
        $lesson = $this->makeLesson([
            'tone'    => 'urgent and direct',
            'details' => 'Focus on the assassination. Avoid graphic content.',
        ]);

        $wikipedia = Mockery::mock(WikipediaService::class);
        $wikipedia->shouldReceive('fetchFacts')->andReturn('Some facts');
        $this->app->instance(WikipediaService::class, $wikipedia);

        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('generate')
            ->once()
            ->withArgs(fn ($facts, $topic, $gradLevel, $tone, $details) =>
                $topic    === 'Julius Caesar' &&
                $gradLevel === '8th grade' &&
                $tone     === 'urgent and direct' &&
                $details  === 'Focus on the assassination. Avoid graphic content.'
            )
            ->andReturn($this->llmResult());
        $this->app->instance(LlmService::class, $llm);

        $tts = Mockery::mock(TtsService::class);
        $tts->shouldReceive('generateAudio')->andReturn('lessons/1/audio.mp3');
        $this->app->instance(TtsService::class, $tts);

        $avatar = Mockery::mock(AvatarService::class);
        $avatar->shouldReceive('generateVideo')->andReturn(null);
        $avatar->shouldReceive('downloadPortrait')->andReturn(null);
        $avatar->shouldReceive('useDefaultPortrait')->andReturn(null);
        $this->app->instance(AvatarService::class, $avatar);

        $imageSearch = Mockery::mock(ImageSearchService::class);
        $imageSearch->shouldReceive('fetchImages')->andReturn([]);
        $this->app->instance(ImageSearchService::class, $imageSearch);

        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals(LessonStatus::Ready, $lesson->status);
    }

    // ── Fallback behaviour ────────────────────────────────────────────────────

    public function test_wikipedia_failure_uses_fallback_facts(): void
    {
        $lesson = $this->makeLesson();
        $this->bindMocks(facts: null); // Wikipedia returns nothing

        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals(LessonStatus::Ready, $lesson->status);
        $this->assertStringContainsString('Teacher-provided lesson context', $lesson->wikipedia_source);
    }

    public function test_missing_llm_questions_are_padded_to_four(): void
    {
        $lesson = $this->makeLesson();

        $partialResult = $this->llmResult();
        $partialResult['questions'] = array_slice($partialResult['questions'], 0, 2); // only 2 questions

        $this->bindMocks(llmResult: $partialResult);
        $this->runJob($lesson);

        $this->assertCount(4, $lesson->quizQuestions()->get());
    }

    public function test_fallback_title_is_topic_when_llm_omits_title(): void
    {
        $lesson = $this->makeLesson();

        $result = $this->llmResult();
        $result['title'] = null;

        $this->bindMocks(llmResult: $result);
        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals('Julius Caesar', $lesson->title);
    }

    // ── Failure cases ─────────────────────────────────────────────────────────

    public function test_lesson_is_marked_failed_when_llm_returns_null(): void
    {
        $lesson = $this->makeLesson();

        $wikipedia = Mockery::mock(WikipediaService::class);
        $wikipedia->shouldReceive('fetchFacts')->once()->andReturn('Some facts');
        $this->app->instance(WikipediaService::class, $wikipedia);

        $llm = Mockery::mock(LlmService::class);
        $llm->shouldReceive('generate')->once()->andReturn(null);
        $this->app->instance(LlmService::class, $llm);

        $this->app->instance(TtsService::class, Mockery::mock(TtsService::class));

        $avatar = Mockery::mock(AvatarService::class);
        $avatar->shouldReceive('generateVideo')->andReturn(null);
        $avatar->shouldReceive('downloadPortrait')->andReturn(null);
        $avatar->shouldReceive('useDefaultPortrait')->andReturn(null);
        $this->app->instance(AvatarService::class, $avatar);

        $imageSearch = Mockery::mock(ImageSearchService::class);
        $imageSearch->shouldReceive('fetchImages')->andReturn([]);
        $this->app->instance(ImageSearchService::class, $imageSearch);

        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals(LessonStatus::Failed, $lesson->status);
        $this->assertNotNull($lesson->error_message);
    }

    public function test_lesson_is_marked_failed_when_tts_returns_null(): void
    {
        $lesson = $this->makeLesson();
        $this->bindMocks(audioPath: null); // TTS fails

        $this->runJob($lesson);

        $lesson->refresh();
        $this->assertEquals(LessonStatus::Failed, $lesson->status);
    }
}
