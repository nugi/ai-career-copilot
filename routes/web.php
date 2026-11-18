<?php

use App\Http\Controllers\CopilotController;
use App\Http\Controllers\FitController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ResumeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'edit'])->name('onboarding.edit');
    Route::put('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
});

Route::middleware(['auth', 'verified', 'onboarded'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('matches', [MatchController::class, 'index'])->name('matches.index');
    Route::get('matches/{posting}/fit', [FitController::class, 'show'])->whereNumber('posting')->middleware('throttle:copilot')->name('matches.fit');

    Route::get('advisor', fn () => redirect()->route('copilot.show', [], 301))->name('advisor.redirect');
    Route::get('copilot/{conversation?}', [CopilotController::class, 'show'])->name('copilot.show');
    Route::post('copilot/stream', [CopilotController::class, 'stream'])->middleware(['throttle:copilot', 'ai.budget:copilot'])->name('copilot.stream');

    Route::get('resumes', [ResumeController::class, 'index'])->name('resumes.index');
    Route::post('resumes', [ResumeController::class, 'store'])->middleware('throttle:resume-uploads')->name('resumes.store');
    Route::get('resumes/{resume}/content', [ResumeController::class, 'content'])->whereNumber('resume')->name('resumes.content');
});

require __DIR__.'/settings.php';
