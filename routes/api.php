<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LessonTeamController;
use App\Http\Controllers\Api\StudentLessonController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Authentication ────────────────────────────────────────────────────────────

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/user', fn(Request $request) => $request->user())->name('user');

        Route::get('/avatars/{avatar}/controller', \App\Http\Controllers\Api\AnimationControllerApi::class)
            ->name('avatars.controller');

        // ── Student endpoints (Flutter app) ───────────────────────────────
        Route::middleware('role:student')->prefix('student')->name('student.')->group(function () {
            Route::get('/lessons',                    [StudentLessonController::class, 'index'])->name('lessons.index');
            Route::get('/lessons/{lesson}',           [StudentLessonController::class, 'show'])->name('lessons.show');
            Route::post('/lessons/{lesson}/progress', [StudentLessonController::class, 'saveProgress'])->name('lessons.progress');
        });

    });

});

// ── Public lesson endpoints (no auth) ─────────────────────────────────────────
Route::prefix('lesson')->name('api.lesson.')->group(function () {
    Route::get('/{lessonCode}/teams',  [LessonTeamController::class, 'index'])->name('teams.index');
    Route::post('/{lessonCode}/teams', [LessonTeamController::class, 'store'])->name('teams.store');
});
