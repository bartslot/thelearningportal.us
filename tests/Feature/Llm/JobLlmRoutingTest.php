<?php

declare(strict_types=1);

namespace Tests\Feature\Llm;

use App\Jobs\BuildLessonOutline;
use App\Jobs\GenerateLessonQuiz;
use App\Jobs\GenerateLessonScript;
use App\Jobs\GenerateSceneScript;
use App\Jobs\GenerateSceneShots;
use App\Jobs\GenerateSkyboxCandidates;
use App\Jobs\GenerateSkyboxImage;
use App\Services\OpenAiLlmService;
use Illuminate\Container\Util;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Every pipeline job gets its model from its #[Llm(...)] role.
 *
 * That split is the point of the whole layer: the narration a class actually hears
 * can sit on a strong model while the mechanical work — shot lists, quiz
 * distractors, skybox prompts — runs on something free. If a job loses its
 * annotation the routing silently collapses back to one provider, which is exactly
 * the regression these cover.
 */
class JobLlmRoutingTest extends TestCase
{
    /** Jobs whose output is prose a student hears, versus jobs doing plumbing. */
    private const EXPECTED_ROLES = [
        BuildLessonOutline::class => 'script',
        GenerateLessonScript::class => 'script',
        GenerateSceneScript::class => 'script',

        GenerateLessonQuiz::class => 'mechanical',
        GenerateSceneShots::class => 'mechanical',
        GenerateSkyboxImage::class => 'mechanical',
        GenerateSkyboxCandidates::class => 'mechanical',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('llm.providers', [
            'writer' => [
                'label' => 'Writer', 'base_url' => 'https://writer.test/v1',
                'api_key' => 'w', 'model' => 'good-model', 'json_format' => 'json_object', 'timeout' => 60,
            ],
            'cheap' => [
                'label' => 'Cheap', 'base_url' => 'https://cheap.test/v1',
                'api_key' => 'c', 'model' => 'free-model', 'json_format' => '', 'timeout' => 60,
            ],
        ]);
        config()->set('llm.roles', ['script' => 'writer', 'mechanical' => 'cheap']);
        config()->set('llm.default_role', 'mechanical');
    }

    public function test_every_pipeline_job_declares_the_role_it_needs(): void
    {
        foreach (self::EXPECTED_ROLES as $job => $role) {
            $attribute = $this->llmAttributeOf($job);

            $this->assertNotNull($attribute, "{$job}::handle() has no #[Llm] attribute, so it would fall back to the default provider");
            $this->assertSame($role, $attribute->newInstance()->role, "{$job} is routed to the wrong kind of model");
        }
    }

    public function test_script_jobs_call_the_writing_model(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->llmFor(GenerateLessonScript::class)->text('sys', 'user');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://writer.test/v1/')
            && $request['model'] === 'good-model');
    }

    public function test_mechanical_jobs_call_the_cheap_model(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->llmFor(GenerateLessonQuiz::class)->text('sys', 'user');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cheap.test/v1/')
            && $request['model'] === 'free-model');
    }

    public function test_a_job_that_takes_several_services_still_routes_the_right_parameter(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        // GenerateSceneShots::handle() takes the image service first — the attribute has
        // to be read per parameter, not per job.
        $this->llmFor(GenerateSceneShots::class)->text('sys', 'user');

        Http::assertSent(fn ($request) => $request['model'] === 'free-model');
    }

    public function test_an_untagged_dependency_falls_back_to_the_default_role(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $this->app->make(OpenAiLlmService::class)->text('sys', 'user');

        Http::assertSent(fn ($request) => $request['model'] === 'free-model');
    }

    /**
     * Resolve the job's llm parameter the way Illuminate\Container\BoundMethod does when
     * the queue calls handle().
     */
    private function llmFor(string $job): OpenAiLlmService
    {
        $attribute = $this->llmAttributeOf($job);
        $this->assertNotNull($attribute, "{$job}::handle() has no #[Llm] attribute");

        return $this->app->resolveFromAttribute($attribute);
    }

    private function llmAttributeOf(string $job): ?\ReflectionAttribute
    {
        foreach ((new ReflectionMethod($job, 'handle'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === OpenAiLlmService::class) {
                return Util::getContextualAttributeFromDependency($parameter);
            }
        }

        return null;
    }
}
