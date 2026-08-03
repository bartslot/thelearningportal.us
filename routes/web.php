<?php

use App\Enums\LessonStatus as LessonStatusEnum;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HistoricalCitiesController;
use App\Http\Controllers\LessonPlayerController;
use App\Http\Controllers\Teacher\DashboardController;
use App\Livewire\Admin\AvatarStudio;
use App\Livewire\LessonWizard;
use App\Models\Avatar;
use App\Models\Lesson;
use Illuminate\Support\Facades\Route;

// {lesson} is a bigint primary key. Constrain it to digits app-wide so a stray non-numeric
// segment (e.g. a JS-built "/teacher/lessons/null/...") 404s instead of reaching route-model
// binding, where Postgres throws a 500 trying to cast "null"/"abc" to bigint.
Route::pattern('lesson', '[0-9]+');

// ── Public ───────────────────────────────────────────────────────────────────

Route::get('/', function () {
    // Publicly playable lessons (Published + Previewable) surfaced on the landing page so
    // visitors can open one straight into the player (no auth required — see lesson.play).
    //
    // Cached (local file store) for 10 min. This whereHas EXISTS subquery + eager loads against
    // the remote Supabase DB costs ~2s cold, and the landing page is the most-hit route — caching
    // takes it from ~2.2s to ~40ms. The key is busted on any Lesson save (see Lesson::booted),
    // so a newly published lesson appears immediately.
    $playableLessons = \Illuminate\Support\Facades\Cache::remember(
        'home.playable_lessons',
        now()->addMinutes(10),
        fn () => Lesson::whereIn('status', [
            LessonStatusEnum::Published->value,
            LessonStatusEnum::Previewable->value,
        ])
            ->whereNotNull('lesson_code')
            // Only lessons that actually play — at least one narrated scene. Excludes empty
            // stubs that would open the player to a perpetual "audio generating" screen.
            ->whereHas('scenes', fn ($q) => $q->whereNotNull('audio_path'))
            ->with(['firstScene', 'source'])
            ->latest()
            ->take(12)
            ->get()
    );

    // The five curated "Trending history topics" cards, keyed by title so the carousel can link
    // each poster to its real lesson. Separate from $playableLessons because these are pinned
    // subjects, not simply the most recent twelve. A missing title just renders an unlinked card.
    $trendingLessons = \Illuminate\Support\Facades\Cache::remember(
        'home.trending_lessons',
        now()->addMinutes(10),
        fn () => Lesson::whereIn('title', \App\Support\TrendingTopics::lessonTitles())
            ->whereIn('status', [
                LessonStatusEnum::Published->value,
                LessonStatusEnum::Previewable->value,
            ])
            ->whereNotNull('lesson_code')
            ->whereHas('scenes', fn ($q) => $q->whereNotNull('audio_path'))
            // Ascending on purpose: keyBy keeps the LAST row for a duplicate key, so ordering
            // oldest-first means a rebuilt lesson (same title, higher id) wins.
            ->orderBy('id')
            ->get()
            ->keyBy('title')
    );

    return view('historyportal', compact('playableLessons', 'trendingLessons'));
})->name('home');

Route::get('/about', function () {
    return view('about');
})->name('about');

// ── Public, indexable marketing pages ────────────────────────────────────────
//
// Registered twice on purpose: English at the root (/history-lessons) and every other language
// under its code (/de/history-lessons). Search engines need one URL per language, which is what
// makes the
// hreflang and sitemap alternates in App\Support\Seo meaningful. The locale segment is constrained
// to the shipped languages, so it cannot swallow /about, /login or /teacher/*.
Route::get('/sitemap.xml', \App\Http\Controllers\Public\SitemapController::class)->name('sitemap');

// English, unprefixed. `/` and `/about` above already serve it, so only the new pages are added
// here; App\Support\Seo maps the English "home"/"about" pages onto those existing route names.
// /history-lessons, not /lessons: a public/lessons directory (lesson media) shadows that path —
// the web server serves the directory before Laravel ever sees the request. It is also the better
// keyword for what the page is.
Route::get('/history-lessons', [\App\Http\Controllers\Public\LessonCatalogueController::class, 'index'])
    ->name('public.lessons');
Route::get('/history-lessons/{slug}', [\App\Http\Controllers\Public\LessonCatalogueController::class, 'show'])
    ->name('public.lesson');
Route::get('/articles', [\App\Http\Controllers\Public\ArticleController::class, 'index'])
    ->name('public.articles');
Route::get('/articles/{slug}', [\App\Http\Controllers\Public\ArticleController::class, 'show'])
    ->name('public.article');

// The other languages, prefixed and locale-aware.
Route::prefix('{locale}')
    ->whereIn('locale', ['nl', 'de', 'fr', 'it'])
    ->middleware(\App\Http\Middleware\SetLocaleFromUrl::class)
    ->group(function () {
        Route::get('/', fn () => view('historyportal', [
            'playableLessons' => \App\Support\Seo::publicLessons()->with(['firstScene', 'source'])->latest()->take(12)->get(),
        ]))->name('public.home.localized');
        Route::get('/about', fn () => view('about'))->name('public.about.localized');
        Route::get('/history-lessons', [\App\Http\Controllers\Public\LessonCatalogueController::class, 'index'])
            ->name('public.lessons.localized');
        Route::get('/history-lessons/{slug}', [\App\Http\Controllers\Public\LessonCatalogueController::class, 'show'])
            ->name('public.lesson.localized');
        Route::get('/articles', [\App\Http\Controllers\Public\ArticleController::class, 'index'])
            ->name('public.articles.localized');
        Route::get('/articles/{slug}', [\App\Http\Controllers\Public\ArticleController::class, 'show'])
            ->name('public.article.localized');
    });

// Student lesson player — public, no auth required
Route::get('/lesson/{lessonCode}', LessonPlayerController::class)->name('lesson.play');

// TEMPORARY: preview of the reworked hero animation for review on production. Unlinked and
// noindex; delete with resources/views/hero-preview.blade.php once a variant is chosen.
Route::view('/hero-preview', 'hero-preview')->name('hero.preview');

// "Configure" on the landing page: hands a visitor with no account a throwaway teacher account
// and a private copy of the demo lesson, then opens the real wizard on it. Throttled because each
// hit writes a user + a lesson + its scenes.
Route::get('/try', \App\Http\Controllers\GuestDemoController::class)
    ->middleware('throttle:10,1')
    ->name('demo.configure');

// Quiz leaderboard (Kahoot-style, anonymous nicknames) — public like the player, throttled.
Route::get('/lesson/{lessonCode}/leaderboard', [\App\Http\Controllers\QuizLeaderboardController::class, 'index'])
    ->middleware('throttle:60,1')->name('lesson.leaderboard');
Route::post('/lesson/{lessonCode}/quiz-score', [\App\Http\Controllers\QuizLeaderboardController::class, 'store'])
    ->middleware('throttle:10,1')->name('lesson.quiz-score');

// Curated historical cities as GeoJSON — public reference data for the lesson-atlas overlay
// (labelled "Constantinople (Istanbul)" markers). No auth: shared by the composer preview and
// the student player, both of which load the map unauthenticated.
Route::get('/map/historical-cities.geojson', HistoricalCitiesController::class)->name('map.historical-cities');

// ── Auth ─────────────────────────────────────────────────────────────────────

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ── Teacher dashboard (auth required) ────────────────────────────────────────

// Self-service account settings (UI-language switcher) — available to any signed-in user.
// Named `settings.index` so the nav's existing "Account Settings" link activates automatically.
Route::middleware(['auth', \App\Http\Middleware\RestrictGuestDemo::class])->get('/settings', \App\Livewire\Settings::class)->name('settings.index');

// Help centre — how the app works, for any signed-in user. Shares its copy with the welcome tour
// (App\Support\HelpGuide), and holds the button that replays that tour.
Route::middleware(['auth', \App\Http\Middleware\RestrictGuestDemo::class])->get('/help', \App\Livewire\Help::class)->name('help.index');

// RestrictGuestDemo fences landing-page demo guests (App\Services\GuestDemoSession) into the
// wizard for their own copy — they hold a real teacher account, so without it the whole
// workspace would be open to anyone who pressed Configure.
Route::middleware(['auth', \App\Http\Middleware\RestrictGuestDemo::class])
    ->prefix('teacher')->name('teacher.')->group(function () {

    // The dashboard IS the workspace root: /teacher. The old /teacher/dashboard URL
    // redirects so bookmarks keep working. Lessons live on their own page below.
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::redirect('/dashboard', '/teacher');

    Route::get('/lessons', \App\Http\Controllers\Teacher\LessonIndexController::class)->name('lessons.index');

    Route::get('/timemap', \App\Livewire\TimeMap::class)->name('timemap');

    // Bulk article cache is served as a static snapshot (public/timemap/articles.json) generated by
    // timemap:sync-cliopatria-polities — the corpus pooler is too slow to query live on page load.

    // Read a territory's summary aloud via ElevenLabs. The mp3 is cached per id so each polity is
    // synthesised at most once (cost + latency). Returns { url } or { url: null } on failure.
    Route::post('/timemap/speak', function (\Illuminate\Http\Request $request, \App\Services\ElevenLabsService $tts) {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('id'));
        $text = trim((string) $request->input('text'));
        if ($id === '' || $text === '') {
            return response()->json(['url' => null]);
        }
        $rel = "tts/{$id}.mp3";
        $file = public_path($rel);
        if (! is_file($file)) {
            $voice = (string) config('services.elevenlabs.voice_id');
            $res = $tts->generateWithTimestamps(\Illuminate\Support\Str::limit($text, 700, ''), $voice);
            if (! $res || empty($res['audio'])) {
                return response()->json(['url' => null]);
            }
            \Illuminate\Support\Facades\File::ensureDirectoryExists(public_path('tts'));
            \Illuminate\Support\Facades\File::put($file, $res['audio']);
        }

        return response()->json(['url' => "/{$rel}"]);
    })->name('timemap.speak');

    Route::get('/timemap/polity/{osmId}', function (\Illuminate\Http\Request $request, string $osmId) {
        $corpus = \Illuminate\Support\Facades\DB::connection('pgsql_corpus');
        $p = $corpus->table('public.polities')->where('osm_id', $osmId)->first();

        // Lazy-enrich on a miss. Supplemental markers pass an explicit `qid` (precise); OHM
        // features pass a `name` (Wikidata search). Prefer the QID when present.
        if (! $p && ($request->filled('qid') || $request->filled('name'))) {
            $resolver = app(\App\Services\WikidataPolityResolver::class);
            // Cliopatria mistags some polygons (wrong-era or shared QIDs) — resolve through the
            // committed override table so lazy enrichment matches what the sync would write.
            $data = $request->filled('qid')
                ? $resolver->resolveByQid(app(\App\Services\CliopatriaQidOverrides::class)->resolve(
                    $request->string('qid')->toString(),
                    $request->filled('name') ? $request->string('name')->toString() : null,
                ))
                : $resolver->resolve($request->string('name')->toString());
            // Era = the Cliopatria span the tiles render by (see CliopatriaSpans), so the panel
            // matches the map; Wikidata P571/P576 is only a fallback for non-list polities.
            $span = app(\App\Services\CliopatriaSpans::class)->for($request->filled('qid') ? $request->string('qid')->toString() : $osmId);
            $corpus->table('public.polities')->updateOrInsert(['osm_id' => $osmId], [
                // Prefer the dataset's clean era-name (Cliopatria Name) over the Wikidata label so the
                // panel title matches the map label; fall back to the Wikidata label.
                'polity_id' => $osmId, 'label' => $request->filled('name') ? $request->string('name')->toString() : $data['label'],
                'wikidata_id' => $data['wikidata_id'], 'summary' => $data['summary'],
                'wikipedia_url' => $data['wikipedia_url'], 'inception' => $span['from'] ?? $data['inception'],
                'dissolution' => $span['to'] ?? $data['dissolution'], 'predecessor' => $data['predecessor'],
                'successor' => $data['successor'], 'sitelinks' => $data['sitelinks'],
                'significant' => true, 'updated_at' => now(),
            ]);
            $p = $corpus->table('public.polities')->where('osm_id', $osmId)->first();
        }

        // Rulers + notable people of this polity (corpus), for the People tab cards. Rulers first,
        // then by fame (sitelinks). The QID comes from the click payload or the stored polity.
        $qid = $request->filled('qid') ? $request->string('qid')->toString() : $p?->wikidata_id;
        $figures = [];
        if ($qid) {
            $figures = $corpus->table('public.figures')
                ->where('parent_qid', $qid)
                ->whereNotNull('name')
                ->orderByRaw("CASE WHEN figure_kind = 'ruler' THEN 0 ELSE 1 END")
                ->orderByDesc('sitelinks')
                ->limit(12)
                ->get(['qid', 'name', 'figure_kind', 'image_url', 'era_start', 'era_end'])
                ->map(fn ($f) => [
                    'qid' => $f->qid,
                    'name' => $f->name,
                    'kind' => $f->figure_kind,
                    'image_url' => $f->image_url,
                    'era' => ($f->era_start !== null || $f->era_end !== null)
                        ? trim(($f->era_start ?? '?').'–'.($f->era_end ?? '?'), '–')
                        : null,
                ])->all();
        }

        return response()->json([
            // Title = the clicked feature's name (matches the map label even if a QID is shared
            // across differently-named Cliopatria segments); QID still drives summary/flag/dates.
            'osm_id' => $osmId, 'label' => $request->filled('name') ? $request->string('name')->toString() : $p?->label,
            'summary' => $p?->summary,
            'flag_path' => $p?->flag_path, 'wikipedia_url' => $p?->wikipedia_url,
            'inception' => $p?->inception !== null ? (int) $p->inception : null,
            'dissolution' => $p?->dissolution !== null ? (int) $p->dissolution : null,
            'predecessor' => $p?->predecessor, 'successor' => $p?->successor,
            'figures' => $figures,
        ])->header('Cache-Control', 'public, max-age=86400');
    })->name('timemap.polity');

    Route::get('/lessons/create', LessonWizard::class)->name('lessons.create');

    // K-12 agentic creation chat — goal → chips → configured lesson in ~1 min.
    Route::get('/lessons/chat', \App\Livewire\Teacher\LessonChat::class)->name('lessons.chat');

    Route::get('/lessons/{lesson}/wizard', LessonWizard::class)->name('lessons.wizard');

    Route::get('/lessons/{lesson}/composer', \App\Livewire\LessonComposer::class)->name('lessons.composer');

    // Results & analytics (spec: docs/superpowers/specs/2026-07-08-teacher-results-analytics-design.md)
    Route::get('/lessons/{lesson}/results', \App\Livewire\Teacher\LessonReport::class)->name('lessons.results');
    Route::get('/results', \App\Livewire\Teacher\ResultsHub::class)->name('results.hub');

    // Class management — classes + account-less rosters + lesson assignment
    Route::get('/classes', \App\Livewire\Teacher\Classrooms::class)->name('classes.index');
    Route::get('/classes/{classroom}', \App\Livewire\Teacher\ClassroomManage::class)->name('classes.manage');

    Route::get('/lessons/{lesson}/results/answer-sheet', function (\App\Models\Lesson $lesson) {
        abort_unless(auth()->user()?->canManage($lesson), 403);
        $questions = $lesson->quizQuestions()->orderBy('scene_id')->orderBy('order')->get();

        return view('teacher.answer-sheet', compact('lesson', 'questions'));
    })->name('lessons.answer-sheet');

    Route::get('/lessons/{lesson}/print/handout', function (\App\Models\Lesson $lesson, \App\Services\LessonPdfService $pdf) {
        abort_unless(auth()->user()?->canManage($lesson), 403);

        return response($pdf->handout($lesson), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="handout-'.$lesson->lesson_code.'.pdf"',
        ]);
    })->name('lessons.print.handout');

    Route::get('/lessons/{lesson}/print/game-pack', function (\App\Models\Lesson $lesson) {
        abort_unless(auth()->user()?->canManage($lesson), 403);
        abort_unless(filled($lesson->game_pack_path)
            && \Illuminate\Support\Facades\Storage::disk('public')->exists($lesson->game_pack_path), 404);

        return response(\Illuminate\Support\Facades\Storage::disk('public')->get($lesson->game_pack_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="spelpakket-'.$lesson->lesson_code.'.pdf"',
        ]);
    })->name('lessons.print.game-pack');

    // Alias so legacy dashboard / nav links keep working — resumes wizard at lesson's last step.
    Route::get('/lessons/{lesson}', LessonWizard::class)->name('lessons.show');

    Route::post('/lessons/{lesson}/retry', function (Lesson $lesson) {
        abort_unless(app()->environment(['local', 'testing']), 403);
        abort_unless(auth()->user()?->canManage($lesson), 403);
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

        // Use the OpenAI scene pipeline (same as the wizard), not the legacy local-LLM GenerateLesson.
        \App\Jobs\BuildLessonOutline::dispatch($lesson->id);

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

    // Story catalog review queue (draft → reviewed → published)
    Route::get('/stories', \App\Livewire\Admin\StoryReview::class)->name('stories.review');

    // Toggle active status
    Route::patch('/avatars/{avatar}/toggle', function (Avatar $avatar) {
        $avatar->update(['is_active' => ! $avatar->is_active]);

        return back()->with('success', $avatar->name.' is now '.($avatar->is_active ? 'active' : 'inactive').'.');
    })->name('avatars.toggle');

});
