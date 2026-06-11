<?php

use App\Enums\LessonStatus as LessonStatusEnum;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LessonPlayerController;
use App\Jobs\GenerateLesson;
use App\Livewire\Admin\AvatarLab;
use App\Livewire\Admin\AvatarStudio;
use App\Livewire\LessonWizard;
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
            ->with(['source', 'firstScene'])
            ->latest()
            ->paginate(24);

        return view('teacher.dashboard', compact('lessons'));
    })->name('dashboard');

    Route::get('/timemap', \App\Livewire\TimeMap::class)->name('timemap');

    Route::get('/timemap/polity/{osmId}', function (\Illuminate\Http\Request $request, string $osmId) {
        $corpus = \Illuminate\Support\Facades\DB::connection('pgsql_corpus');
        $p = $corpus->table('public.polities')->where('osm_id', $osmId)->first();

        // Lazy-enrich on a miss. Supplemental markers pass an explicit `qid` (precise); OHM
        // features pass a `name` (Wikidata search). Prefer the QID when present.
        if (! $p && ($request->filled('qid') || $request->filled('name'))) {
            $resolver = app(\App\Services\WikidataPolityResolver::class);
            $data = $request->filled('qid')
                ? $resolver->resolveByQid($request->string('qid')->toString())
                : $resolver->resolve($request->string('name')->toString());
            $corpus->table('public.polities')->updateOrInsert(['osm_id' => $osmId], [
                // Prefer the dataset's clean era-name (Cliopatria Name) over the Wikidata label so the
                // panel title matches the map label; fall back to the Wikidata label.
                'polity_id' => $osmId, 'label' => $request->filled('name') ? $request->string('name')->toString() : $data['label'],
                'wikidata_id' => $data['wikidata_id'], 'summary' => $data['summary'],
                'wikipedia_url' => $data['wikipedia_url'], 'inception' => $data['inception'],
                'dissolution' => $data['dissolution'], 'predecessor' => $data['predecessor'],
                'successor' => $data['successor'], 'sitelinks' => $data['sitelinks'],
                'significant' => true, 'updated_at' => now(),
            ]);
            $p = $corpus->table('public.polities')->where('osm_id', $osmId)->first();
        }

        return response()->json([
            'osm_id' => $osmId, 'label' => $p?->label, 'summary' => $p?->summary,
            'flag_path' => $p?->flag_path, 'wikipedia_url' => $p?->wikipedia_url,
            'inception' => $p?->inception !== null ? (int) $p->inception : null,
            'dissolution' => $p?->dissolution !== null ? (int) $p->dissolution : null,
            'predecessor' => $p?->predecessor, 'successor' => $p?->successor,
        ])->header('Cache-Control', 'public, max-age=86400');
    })->name('timemap.polity');

    Route::get('/lessons/create', LessonWizard::class)->name('lessons.create');

    Route::get('/lessons/{lesson}/wizard', LessonWizard::class)->name('lessons.wizard');

    // Alias so legacy dashboard / nav links keep working — resumes wizard at lesson's last step.
    Route::get('/lessons/{lesson}', LessonWizard::class)->name('lessons.show');

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

        return back()->with('success', $avatar->name.' is now '.($avatar->is_active ? 'active' : 'inactive').'.');
    })->name('avatars.toggle');

});
