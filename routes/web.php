<?php

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

    Route::get('resumes', [ResumeController::class, 'index'])->name('resumes.index');
    Route::post('resumes', [ResumeController::class, 'store'])->name('resumes.store');
});

Route::middleware(['auth', 'verified', 'onboarded', 'throttle:advisor'])->group(function () {
    Route::post('advisor', AdvisorController::class)->name('advisor.ask');
});

require __DIR__.'/settings.php';
