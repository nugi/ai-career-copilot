<?php

use App\Models\JobPosting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('seeding creates a demo user with a profile, a primary resume and skills', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->sole();

    expect($user->email)->toBe('test@example.com')
        ->and($user->profile)->not->toBeNull()
        ->and($user->primaryResume)->not->toBeNull()
        ->and($user->skills)->toHaveCount(6);
});

test('seeding creates 50 job postings that each ask for skills', function () {
    $this->seed(DatabaseSeeder::class);

    expect(JobPosting::query()->count())->toBe(50)
        ->and(JobPosting::query()->doesntHave('skills')->count())->toBe(0);
});
