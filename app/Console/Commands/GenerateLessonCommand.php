<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Jobs\GenerateLesson;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Usage:
 *   php artisan lesson:generate
 *   php artisan lesson:generate --topic="World War II" --figure="Winston Churchill"
 *   php artisan lesson:generate --queue
 */
class GenerateLessonCommand extends Command
{
    protected $signature = 'lesson:generate
        {--teacher=   : Teacher user ID (creates a test teacher if omitted)}
        {--topic=     : Lesson topic (default: Julius Caesar)}
        {--figure=    : Historical figure (default: Julius Caesar)}
        {--grade=     : Grade level (default: 8)}
        {--tone=      : Tone: dramatic|serious|playful|inspiring (default: dramatic)}
        {--queue      : Dispatch to queue instead of running synchronously}
        {--no-start   : Skip auto-starting local AI services}';

    protected $description = 'Generate a lesson end-to-end (Wikipedia → LLM → TTS → Avatar). Auto-starts Ollama & Kokoro if needed.';

    public function handle(): int
    {
        if (! $this->option('no-start')) {
            $this->ensureServicesRunning();
        }

        $topic  = $this->option('topic')  ?? 'Julius Caesar';
        $figure = $this->option('figure') ?? 'Julius Caesar';
        $grade  = $this->option('grade')  ?? '8';
        $tone   = $this->option('tone')   ?? 'dramatic';

        $teacherId = $this->option('teacher');
        if ($teacherId) {
            $teacher = User::findOrFail((int) $teacherId);
        } else {
            $teacher = User::firstOrCreate(
                ['email' => 'dev-teacher@thelearningportal.test'],
                [
                    'name'     => 'Dev Teacher',
                    'password' => bcrypt('password'),
                    'role'     => 'teacher',
                ]
            );
            $this->line("Using dev teacher: {$teacher->email} (ID #{$teacher->id})");
        }

        $lesson = Lesson::create([
            'teacher_id'        => $teacher->id,
            'title'             => "{$figure}: {$topic}",
            'topic'             => $topic,
            'subject'           => 'history',
            'grade_level'       => $grade,
            'tone'              => $tone,
            'historical_figure' => $figure,
            'status'            => LessonStatus::Pending,
        ]);

        $this->info("Created lesson #{$lesson->id}: \"{$lesson->title}\"");
        $this->line("  Topic:   {$topic}");
        $this->line("  Figure:  {$figure}");
        $this->line("  Grade:   {$grade} | Tone: {$tone}");

        if ($this->option('queue')) {
            GenerateLesson::dispatch($lesson->id);
            $this->info("Dispatched to queue. Run: php artisan queue:work");
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Running generation pipeline...");
        $startTime = microtime(true);

        (new GenerateLesson($lesson->id))->handle(
            app(\App\Services\WikipediaService::class),
            app(\App\Services\LlmService::class),
            app(\App\Services\TtsService::class),
            app(\App\Services\AvatarService::class),
        );

        $elapsed = round(microtime(true) - $startTime, 1);
        $lesson->refresh();
        $this->newLine();

        if ($lesson->status === LessonStatus::Ready) {
            $this->info("Lesson #{$lesson->id} generated in {$elapsed}s");
            $this->table(['Field', 'Value'], [
                ['Status',          $lesson->status->label()],
                ['Script',          strlen($lesson->script ?? '') . ' chars'],
                ['Quiz questions',  $lesson->quizQuestions()->count()],
                ['Portrait',        $lesson->portrait_path ?? '(none)'],
                ['Audio',           $lesson->audio_path    ?? '(none)'],
                ['Wikipedia',       strlen($lesson->wikipedia_source ?? '') . ' chars'],
            ]);
        } else {
            $this->error("Lesson #{$lesson->id} failed: {$lesson->error_message}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ── Service health checks & auto-start ───────────────────────────────────

    private function ensureServicesRunning(): void
    {
        $this->line('Checking local AI services...');

        $this->ensureOllama();
        $this->ensureKokoro();

        $this->newLine();
    }

    private function ensureOllama(): void
    {
        $url = rtrim(config('services.ollama.url', env('OLLAMA_URL', 'http://localhost:11434')), '/');

        if ($this->ping($url)) {
            $this->line("  Ollama    already running at {$url}");
            return;
        }

        $this->line('  Ollama    not running — starting...');

        if (! $this->commandExists('ollama')) {
            $this->warn('  Ollama not installed. Install: brew install ollama');
            return;
        }

        // Start ollama serve in the background
        exec('ollama serve > /tmp/ollama.log 2>&1 &');

        if ($this->waitForService($url, attempts: 15, intervalMs: 500)) {
            $this->line('  Ollama    started');

            // Pull the model if missing
            $model = config('services.ollama.model', env('OLLAMA_MODEL', 'llama3.1:8b'));
            $modelCheck = shell_exec('ollama list 2>/dev/null');
            if (! str_contains((string) $modelCheck, $model)) {
                $this->line("  Pulling model {$model} (first run — may take a while)...");
                passthru("ollama pull {$model}");
            }
        } else {
            $this->warn('  Ollama did not start in time. Generation will fall back to OpenAI.');
        }
    }

    private function ensureKokoro(): void
    {
        $url = rtrim(config('services.kokoro.url', env('KOKORO_TTS_URL', 'http://localhost:8880')), '/');
        $python = $this->pythonBinary();

        if ($this->ping($url)) {
            $this->line("  Kokoro TTS already running at {$url}");
            return;
        }

        $this->line('  Kokoro TTS not running — starting...');

        // Check if the Kokoro Python package is importable
        exec(
            escapeshellcmd($python) . ' -c "import importlib.util, sys; sys.exit(0 if importlib.util.find_spec(\'kokoro_onnx\') or importlib.util.find_spec(\'kokoro\') else 1)" 2>/dev/null',
            $out,
            $rc
        );
        if ($rc !== 0) {
            $this->warn('  Kokoro not installed. Install: pip install kokoro-onnx');
            $this->warn('  Generation will fall back to OpenAI TTS.');
            return;
        }

        exec(escapeshellcmd($python) . ' -m kokoro.server --port 8880 > /tmp/kokoro.log 2>&1 &');

        if ($this->waitForService($url, attempts: 20, intervalMs: 500)) {
            $this->line('  Kokoro TTS started');
        } else {
            $this->warn('  Kokoro TTS did not start in time. Generation will fall back to OpenAI TTS.');
        }
    }

    /**
     * HTTP GET to check if a service is up. Returns true on any non-error response.
     */
    private function ping(string $url): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $result = @file_get_contents($url, false, $ctx);
        return $result !== false;
    }

    /**
     * Poll a URL until it responds or we give up.
     */
    private function waitForService(string $url, int $attempts, int $intervalMs): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            usleep($intervalMs * 1000);
            if ($this->ping($url)) {
                return true;
            }
        }
        return false;
    }

    private function commandExists(string $command): bool
    {
        exec("command -v {$command} 2>/dev/null", $out, $rc);
        return $rc === 0;
    }

    private function pythonBinary(): string
    {
        $venvPython = base_path('.venv/bin/python3');

        return file_exists($venvPython) ? $venvPython : 'python3';
    }
}
