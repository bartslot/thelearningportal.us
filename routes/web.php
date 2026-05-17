<?php

use App\Enums\LessonStatus as LessonStatusEnum;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LessonPlayerController;
use App\Livewire\Admin\AvatarLab;
use App\Livewire\Admin\AvatarStudio;
use App\Livewire\LessonWizard;
use App\Jobs\GenerateLesson;
use App\Models\Avatar;
use App\Models\Lesson;
use Illuminate\Support\Facades\Route;

// ── Public ───────────────────────────────────────────────────────────────────

Route::get('/', function () {
    return view('historyportal');
})->name('home');

Route::get('/about', function () {
    return view('about');
})->name('about');

// Student lesson player — public, no auth required
Route::get('/lesson/{lessonCode}', LessonPlayerController::class)->name('lesson.play');

// ── Auth ─────────────────────────────────────────────────────────────────────

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ── Teacher dashboard (auth required) ────────────────────────────────────────

Route::middleware(['auth'])->prefix('teacher')->name('teacher.')->group(function () {

    Route::get('/dashboard', function () {
        $lessons = \App\Models\Lesson::where('teacher_id', auth()->id())
            ->latest()
            ->paginate(12);
        return view('teacher.dashboard', compact('lessons'));
    })->name('dashboard');

    Route::get('/lessons/create', LessonWizard::class)->name('lessons.create');

    Route::get('/lessons/{lesson}/wizard', LessonWizard::class)->name('lessons.wizard');

    // Alias so legacy dashboard / nav links keep working — resumes wizard at lesson's last step.
    Route::get('/lessons/{lesson}', LessonWizard::class)->name('lessons.show');

    Route::patch('/lessons/{lesson}/publish', function (Lesson $lesson) {
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        abort_unless($lesson->isReady(), 422, 'Lesson is not ready to publish');
        $lesson->update(['status' => LessonStatusEnum::Published]);
        return back()->with('success', 'Lesson published!');
    })->name('lessons.publish');

    Route::post('/lessons/{lesson}/retry', function (Lesson $lesson) {
        abort_unless(app()->environment(['local', 'testing']), 403);
        abort_unless($lesson->teacher_id === auth()->id(), 403);
        abort_unless($lesson->status === LessonStatusEnum::Failed, 422, 'Lesson is not failed');

        $lesson->quizQuestions()->delete();
        $lesson->update([
            'status' => LessonStatusEnum::Pending,
            'error_message' => null,
            'wikipedia_source' => null,
            'script' => null,
            'portrait_path' => null,
            'audio_path' => null,
            'duration_seconds' => null,
        ]);

        GenerateLesson::dispatch($lesson->id);

        return back()->with('success', 'Lesson generation has been re-queued.');
    })->name('lessons.retry');

});

// ── Admin panel (role:admin only) ─────────────────────────────────────────────

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('/dashboard', function () {
        return view('admin.dashboard', [
            'avatarCount' => Avatar::count(),
        ]);
    })->name('dashboard');

    // Avatar list
    Route::get('/avatars', function () {
        $avatars = Avatar::withCount('lessons')->orderBy('sort_order')->get();
        return view('admin.avatars.index', compact('avatars'));
    })->name('avatars.index');

    // Avatar Studio (Livewire component)
    Route::get('/avatars/{avatar}', AvatarStudio::class)->name('avatars.studio');

    // 3D Avatar Lab
    Route::get('/avatar-lab', AvatarLab::class)->name('avatar-lab');

    // Toggle active status
    Route::patch('/avatars/{avatar}/toggle', function (Avatar $avatar) {
        $avatar->update(['is_active' => ! $avatar->is_active]);
        return back()->with('success', $avatar->name . ' is now ' . ($avatar->is_active ? 'active' : 'inactive') . '.');
    })->name('avatars.toggle');

});
