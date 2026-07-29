<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LessonStatus;
use App\Enums\OnboardingStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Support\HelpMedia;
use App\Support\Locales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Captures the help-centre screenshots from the running app, one set per language.
 *
 *   php artisan help:screenshots                  every language
 *   php artisan help:screenshots --locale=de      just German
 *   php artisan help:screenshots --url=http://…   against another host
 *
 * How it works: for each language it switches the demo teacher's UI language, resolves every named
 * route to a URL, and hands the list to tests/playwright/help-screenshots.spec.ts, which drives a
 * real browser. The teacher's original language and tour state are restored afterwards.
 *
 * Local only. It needs a signed-in session, which locally comes from AutoLoginDev.
 */
class CaptureHelpScreenshots extends Command
{
    protected $signature = 'help:screenshots
        {--locale=* : Languages to capture (default: all shipped languages)}
        {--url= : Base URL of the running app (default: APP_URL)}
        {--lesson= : Lesson id to use for the editor screenshots}';

    protected $description = 'Capture the help-centre screenshots per language with Playwright';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Screenshots are captured locally, against the dev server.');

            return self::FAILURE;
        }

        $teacher = $this->demoTeacher();

        if (! $teacher) {
            $this->error('No teacher account to capture with. Seed one first.');

            return self::FAILURE;
        }

        $lesson = $this->demoLesson($teacher);

        if (! $lesson) {
            $this->warn('No finished lesson found — the editor screenshots will be skipped.');
        }

        $locales = $this->option('locale') ?: Locales::codes();
        $baseUrl = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        // Remember what to put back: capturing must not leave the account in another language, or
        // with its welcome tour re-armed.
        $original = [
            'locale' => $teacher->locale,
            'onboarding_status' => $teacher->onboarding_status,
            'onboarding_step' => $teacher->onboarding_step,
        ];

        $failed = [];

        try {
            // The tour overlay would sit on top of the dashboard in every shot.
            $teacher->forceFill(['onboarding_status' => OnboardingStatus::Completed])->save();

            foreach ($locales as $locale) {
                if (! Locales::isSupported($locale)) {
                    $this->error("Unknown language '{$locale}' — skipped.");
                    $failed[] = $locale;

                    continue;
                }

                $captured = false;

                $this->components->task(
                    Locales::name($locale).' ('.$locale.')',
                    function () use ($teacher, $locale, $baseUrl, $lesson, &$captured): bool {
                        $teacher->forceFill(['locale' => $locale])->save();

                        return $captured = $this->capture($locale, $baseUrl, $lesson);
                    },
                );

                if (! $captured) {
                    $failed[] = $locale;
                }
            }
        } finally {
            $teacher->forceFill($original)->save();
        }

        if ($failed !== []) {
            $this->newLine();
            $this->error('Failed: '.implode(', ', $failed));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Captured '.count($locales).' language(s) into public/'.'help-media/.');

        return self::SUCCESS;
    }

    private function capture(string $locale, string $baseUrl, ?Lesson $lesson): bool
    {
        $directory = HelpMedia::directoryFor($locale);
        File::ensureDirectoryExists($directory);

        $process = new Process(
            ['npx', 'playwright', 'test', 'tests/playwright/help-screenshots.spec.ts', '--project=chromium'],
            base_path(),
            [
                'HELP_LOCALE' => $locale,
                'HELP_OUTPUT_DIR' => $directory,
                'HELP_SHOTS' => json_encode($this->shots($baseUrl, $lesson), JSON_THROW_ON_ERROR),
                'PW_BASE_URL' => $baseUrl,
                'PATH' => getenv('PATH'),
            ],
            null,
            600,
        );

        $process->run(fn (string $type, string $buffer) => $this->output->isVerbose() ? $this->output->write($buffer) : null);

        if (! $process->isSuccessful()) {
            $this->newLine();
            $this->line(trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->isSuccessful();
    }

    /**
     * One entry per screenshot the help centre can show. Routes are resolved here, in PHP, so the
     * browser script never has to know the app's URL structure.
     *
     * @return list<array<string,mixed>>
     */
    private function shots(string $baseUrl, ?Lesson $lesson): array
    {
        $url = fn (string $route, array $params = []) => $baseUrl.parse_url(route($route, $params), PHP_URL_PATH)
            .(($query = parse_url(route($route, $params), PHP_URL_QUERY)) ? '?'.$query : '');

        $shots = [
            ['key' => 'dashboard', 'url' => $url('teacher.dashboard'), 'waitFor' => '[data-tour="new-lesson"]'],
            ['key' => 'new-lesson', 'url' => $url('teacher.lessons.chat'), 'settleMs' => 800],
            ['key' => 'wizard-steps', 'url' => $url('teacher.lessons.create'), 'settleMs' => 800],
            ['key' => 'lessons', 'url' => $url('teacher.lessons.index')],
            ['key' => 'classes', 'url' => $url('teacher.classes.index')],
            ['key' => 'results', 'url' => $url('teacher.results.hub')],
            // The map draws through WebGL; headless Chromium's software renderer complains no
            // matter what, so its console is reported rather than treated as a failed capture.
            ['key' => 'timemap', 'url' => $url('teacher.timemap'), 'settleMs' => 3500, 'allowConsoleErrors' => true],
            ['key' => 'settings', 'url' => $url('settings.index')],
        ];

        if ($lesson) {
            $shots[] = [
                'key' => 'wizard-configure',
                'url' => $url('teacher.lessons.wizard', ['lesson' => $lesson->id]).'?step=4',
                'settleMs' => 2500,
            ];
            $shots[] = [
                'key' => 'wizard-play',
                'url' => $url('teacher.lessons.wizard', ['lesson' => $lesson->id]).'?step=5',
                'settleMs' => 2500,
            ];
        }

        return $shots;
    }

    /**
     * The account the browser will actually be signed in as. It has to be exactly the user
     * AutoLoginDev logs in, or the language is switched on one account while the screenshots are
     * taken as another — which silently produces five identical English sets.
     */
    private function demoTeacher(): ?User
    {
        $role = config('app.dev_user_role', 'admin');

        $user = match ($role) {
            'teacher' => User::where('email', 'teacher@example.com')->first(),
            'student' => User::where('email', 'student@example.com')->first(),
            default => User::where('role', 'admin')->first(),
        };

        if ($user && ! $user->isTeacher() && ! $user->isAdmin()) {
            $this->warn("APP_USER_ROLE={$role} signs in a student, who cannot see the teacher pages.");

            return null;
        }

        return $user;
    }

    private function demoLesson(User $teacher): ?Lesson
    {
        return Lesson::where('teacher_id', $teacher->id)
            ->whereIn('status', [LessonStatus::Published->value, LessonStatus::Previewable->value])
            ->when($this->option('lesson'), fn ($q, $id) => $q->whereKey($id))
            ->latest()
            ->first();
    }
}
