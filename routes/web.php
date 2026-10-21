<?php

use App\Http\Controllers\AdvisorChatController;
use App\Http\Controllers\AdvisorController;
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

    Route::get('advisor', [AdvisorChatController::class, 'show'])->name('advisor.chat');

    Route::get('resumes', [ResumeController::class, 'index'])->name('resumes.index');
    Route::post('resumes', [ResumeController::class, 'store'])->name('resumes.store');
    Route::get('resumes/{resume}/content', [ResumeController::class, 'content'])->whereNumber('resume')->name('resumes.content');
});

Route::middleware(['auth', 'verified', 'onboarded', 'throttle:advisor', 'ai.budget'])->group(function () {
    Route::post('advisor', AdvisorController::class)->name('advisor.ask');
    Route::post('advisor/stream', [AdvisorChatController::class, 'stream'])->name('advisor.stream');
});

require __DIR__.'/settings.php';
