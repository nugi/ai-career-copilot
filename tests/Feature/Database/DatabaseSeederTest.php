<?php

use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SkillSeeder;

test('seeding creates a demo user with a profile, a primary resume and skills', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->sole();

    expect($user->email)->toBe('test@example.com')
        ->and($user->profile)->not->toBeNull()
        ->and($user->primaryResume)->not->toBeNull()
        ->and($user->skills)->toHaveCount(6);
});

test('seeded catalogue skills are verified, also when seeding again', function () {
    $this->seed(SkillSeeder::class);
    Skill::query()->update(['is_verified' => false]);

    $this->seed(SkillSeeder::class);

    expect(Skill::query()->where('is_verified', true)->count())->toBe(count(SkillSeeder::SKILLS))
        ->and(Skill::query()->count())->toBe(count(SkillSeeder::SKILLS));
});

test('seeding creates 50 job postings that each ask for skills', function () {
    $this->seed(DatabaseSeeder::class);

    expect(JobPosting::query()->count())->toBe(50)
        ->and(JobPosting::query()->doesntHave('skills')->count())->toBe(0);
});
