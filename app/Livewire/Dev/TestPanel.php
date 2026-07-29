<?php

declare(strict_types=1);

namespace App\Livewire\Dev;

use App\Models\Lesson;
use App\Models\QuizScore;
use App\Models\User;
use App\Services\DevPaperSheet;
use App\Services\DevSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Local-only floating dev toolbar for exercising teacher-facing flows without
 * real student data. Mounted from the app layout behind an environment check;
 * mount() re-asserts the guard so it can never run outside local.
 */
class TestPanel extends Component
{
    /**
     * Roles the switcher hops between, with the account it falls back to when no
     * user of that role exists yet (same accounts DatabaseSeeder creates) and the
     * page that role should land on after switching.
     */
    private const ROLES = [
        'teacher' => ['label' => 'Teacher', 'email' => 'teacher@example.com', 'name' => 'Test Teacher', 'route' => 'teacher.dashboard'],
        'admin' => ['label' => 'Admin', 'email' => 'admin@example.com', 'name' => 'Test Admin', 'route' => 'admin.dashboard'],
        'student' => ['label' => 'Student', 'email' => 'student@example.com', 'name' => 'Test Student', 'route' => null],
    ];

    /** Session key holding the last user id used per role, so switching back returns to the same account. */
    private const ROLE_MEMORY_KEY = 'dev_role_users';

    public ?int $lessonId = null;

    public ?string $lessonLabel = null;

    public int $resultCount = 0;

    public string $returnUrl = '';

    public string $currentRole = '';

    public string $currentUserName = '';

    public function mount(): void
    {
        abort_unless(app()->environment('local'), 404);

        $this->returnUrl = request()->fullUrl();

        $user = Auth::user();
        $this->currentRole = (string) ($user?->role ?? '');
        $this->currentUserName = (string) ($user?->name ?? 'Guest');

        // The host page is route-model-bound, so the current lesson (if any) is on the route.
        $lesson = request()->route('lesson');
        if ($lesson instanceof Lesson) {
            $this->lessonId = $lesson->id;
            $this->lessonLabel = $lesson->lesson_code ?? ($lesson->title ?? 'Lesson '.$lesson->id);
            $this->resultCount = QuizScore::where('lesson_id', $lesson->id)->count();
        }
    }

    private function lesson(): ?Lesson
    {
        return $this->lessonId ? Lesson::find($this->lessonId) : null;
    }

    /**
     * Re-authenticate as an account of `$role` without a logout/login round trip.
     * The account you were last using for a role is remembered per session, so
     * teacher → admin → teacher lands you back on the same teacher.
     */
    public function switchRole(string $role): void
    {
        abort_unless(app()->environment('local'), 404);

        $config = self::ROLES[$role] ?? null;
        if (! $config || $role === $this->currentRole) {
            return;
        }

        $current = Auth::user();
        if ($current) {
            session()->put(self::ROLE_MEMORY_KEY.'.'.$current->role, $current->id);
        }

        $user = $this->accountFor($role, $config);

        Auth::login($user, remember: true);
        session()->regenerate();

        session()->flash('dev_status', "Now signed in as {$user->name} ({$role}).");

        $this->redirect($config['route'] ? route($config['route']) : url('/'));
    }

    /**
     * @param  array{email: string, name: string}  $config
     */
    private function accountFor(string $role, array $config): User
    {
        $rememberedId = session()->get(self::ROLE_MEMORY_KEY.'.'.$role);
        $remembered = $rememberedId ? User::where('id', $rememberedId)->where('role', $role)->first() : null;

        return $remembered
            ?? User::where('email', $config['email'])->where('role', $role)->first()
            ?? User::where('role', $role)->orderBy('id')->first()
            ?? User::create([
                'name' => $config['name'],
                'email' => $config['email'],
                'role' => $role,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]);
    }

    public function seedResults(bool $attachClass = false): void
    {
        abort_unless(app()->environment('local'), 404);

        $lesson = $this->lesson();
        if (! $lesson) {
            return;
        }

        $made = app(DevSeeder::class)->seedResults($lesson, 12, $attachClass);
        session()->flash('dev_status', $made > 0
            ? "Seeded {$made} results".($attachClass ? ' + test class' : '').'.'
            : 'No quiz questions to seed from.');

        $this->reload();
    }

    public function clearResults(): void
    {
        abort_unless(app()->environment('local'), 404);

        $lesson = $this->lesson();
        if (! $lesson) {
            return;
        }

        $removed = app(DevSeeder::class)->clearResults($lesson);
        session()->flash('dev_status', "Cleared {$removed} results.");

        $this->reload();
    }

    /**
     * Generate a fake "photo" of two filled answer sheets for this lesson's real
     * quiz questions, so the paper-import (vision) flow can be tested end to end.
     * One sheet answers everything correctly, the other is mixed — grading is
     * therefore predictable after import.
     */
    public function downloadPaperSheets()
    {
        abort_unless(app()->environment('local'), 404);

        $lesson = $this->lesson();
        if (! $lesson) {
            return null;
        }

        $questions = $lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();
        if ($questions->isEmpty()) {
            session()->flash('dev_status', 'This lesson has no quiz questions — paper import grades against real questions, so generate a quiz first.');
            $this->redirect($this->returnUrl);

            return null;
        }

        $correct = $questions->map(fn ($q) => chr(65 + (int) $q->correct_index))->all();
        $mixed = array_map(
            fn (string $c, int $i) => $i % 2 === 0 ? $c : ($c === 'A' ? 'B' : 'A'),
            $correct,
            array_keys($correct),
        );

        $png = app(DevPaperSheet::class)->png([
            ['name' => 'Emma V.', 'answers' => $correct],
            ['name' => 'Liam B.', 'answers' => $mixed],
        ], $questions->count());

        $filename = 'test-sheets-'.($lesson->lesson_code ?? $lesson->id).'.png';

        return response()->streamDownload(fn () => print ($png), $filename, ['Content-Type' => 'image/png']);
    }

    /** Full-page reload of the host page so sibling Livewire components pick up the new data. */
    private function reload(): void
    {
        $this->redirect($this->returnUrl);
    }

    public function render()
    {
        return view('livewire.dev.test-panel', [
            'roles' => array_map(fn (array $c) => $c['label'], self::ROLES),
        ]);
    }
}
