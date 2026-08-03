<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Configure" on the landing page, for somebody who has no account.
 *
 * Rather than build a second, weaker editor, the visitor is given a real (throwaway) teacher
 * account and their own private copy of the demo lesson. They then walk into the same wizard a
 * paying teacher uses, and every edit is real — it is just deleted a day later, and the account
 * can reach nothing except that one lesson (App\Http\Middleware\RestrictGuestDemo).
 *
 * The copy shares its media files with the original: scene deletion never unlinks media
 * (App\Models\Scene), so a guest cannot damage the lesson they were cloned from.
 */
final class GuestDemoSession
{
    private const SESSION_KEY = 'demo.guest_lesson_id';

    /** The sandbox lesson this browser session already owns, if it is still there. */
    public function current(): ?Lesson
    {
        $user = Auth::user();
        $lessonId = session(self::SESSION_KEY);

        if (! $user?->isGuestDemo() || ! $lessonId) {
            return null;
        }

        return Lesson::query()
            ->where('id', $lessonId)
            ->where('teacher_id', $user->id)
            ->first();
    }

    /**
     * Hand this visitor a sandbox for `$source`, reusing the one they already have.
     * Signs them in as the guest account that owns it.
     */
    public function start(Lesson $source): Lesson
    {
        $existing = $this->current();

        if ($existing) {
            return $existing;
        }

        $guest = $this->createGuestAccount();
        Auth::login($guest);
        session()->regenerate();

        $lesson = $this->cloneLesson($source, $guest);
        session([self::SESSION_KEY => $lesson->id]);

        return $lesson;
    }

    private function createGuestAccount(): User
    {
        return User::create([
            'name' => __('Guest'),
            // .invalid is reserved by RFC 2606, so a guest row can never collide with, or be
            // mistaken for, a real teacher's address — nothing will ever try to mail it.
            'email' => 'guest-'.Str::uuid()->toString().'@demo.invalid',
            'password' => Str::random(48),
            'role' => 'teacher',
            'is_guest_demo' => true,
            'locale' => app()->getLocale(),
        ]);
    }

    private function cloneLesson(Lesson $source, User $owner): Lesson
    {
        // The copy itself is LessonCopier's job (the test harness makes the same kind of copy).
        // What is specific to a sandbox is its state: never published, and opened at the editor.
        return app(LessonCopier::class)->copy($source, $owner, [
            'status' => LessonStatus::Configuring,
            'wizard_step' => 4,
        ]);
    }
}
