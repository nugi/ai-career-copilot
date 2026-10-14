<?php

use App\Actions\Profile\SaveProfile;
use App\Models\User;

/**
 * Preferences as the onboarding request passes them to the action, with optional overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function profilePreferences(array $overrides = []): array
{
    return [
        'headline' => null,
        'target_roles' => ['Backend Engineer'],
        'preferred_locations' => [],
        'work_arrangements' => ['remote'],
        'min_salary' => 20_000_000,
        'salary_currency' => 'IDR',
        'salary_period' => 'monthly',
        ...$overrides,
    ];
}

$laravel = [['name' => 'Laravel', 'proficiency' => 'advanced']];

test('the first save creates a complete profile at version 1', function () use ($laravel) {
    $user = User::factory()->create();

    $profile = app(SaveProfile::class)->handle($user, profilePreferences(), $laravel);

    expect($profile->version)->toBe(1)
        ->and($profile->isComplete())->toBeTrue();
});

test('the version increases when the profile changes', function (array $preferences, array $skills) use ($laravel) {
    $user = User::factory()->create();
    $saveProfile = app(SaveProfile::class);
    $saveProfile->handle($user, profilePreferences(), $laravel);

    $profile = $saveProfile->handle($user->fresh(), profilePreferences($preferences), $skills);

    expect($profile->version)->toBe(2);
})->with([
    'a new target role' => [['target_roles' => ['Backend Engineer', 'Platform Engineer']], $laravel],
    'a different way of working' => [['work_arrangements' => ['hybrid']], $laravel],
    'a higher minimum salary' => [['min_salary' => 25_000_000], $laravel],
    'a new skill' => [[], [...$laravel, ['name' => 'AWS', 'proficiency' => 'beginner']]],
    'a removed skill' => [[], [['name' => 'AWS', 'proficiency' => 'beginner']]],
    'a different proficiency' => [[], [['name' => 'Laravel', 'proficiency' => 'expert']]],
]);

test('the version stays the same when nothing changes', function () use ($laravel) {
    $user = User::factory()->create();
    $saveProfile = app(SaveProfile::class);
    $saveProfile->handle($user, profilePreferences(), $laravel);

    $profile = $saveProfile->handle($user->fresh(), profilePreferences(), $laravel);

    expect($profile->version)->toBe(1);
});

test('later saves keep the original completion time', function () use ($laravel) {
    $user = User::factory()->create();
    $saveProfile = app(SaveProfile::class);
    $this->travelTo('2026-10-01 09:00:00');
    $saveProfile->handle($user, profilePreferences(), $laravel);
    $this->travelTo('2026-10-14 09:00:00');

    $profile = $saveProfile->handle($user->fresh(), profilePreferences(['min_salary' => 25_000_000]), $laravel);

    expect($profile->completed_at->toDateTimeString())->toBe('2026-10-01 09:00:00');
});
