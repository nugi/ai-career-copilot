<?php

use App\JobSources\TargetRoleQueries;
use App\Models\Profile;

test('roles come from profiles, most common first, cleaned', function () {
    Profile::factory()->create(['target_roles' => ['Backend Engineer', 'backend   engineer!']]);
    Profile::factory()->create(['target_roles' => ['Data Engineer']]);
    Profile::factory()->create(['target_roles' => ['backend engineer']]);

    expect(app(TargetRoleQueries::class)->all())->toBe(['backend engineer', 'data engineer']);
});

test('the list is capped', function () {
    config(['job-sources.max_queries' => 1]);
    Profile::factory()->create(['target_roles' => ['backend engineer', 'data engineer']]);

    expect(app(TargetRoleQueries::class)->all())->toBe(['backend engineer']);
});

test('the default list is used without profile roles', function () {
    config(['job-sources.default_queries' => ['php developer', 'go developer']]);

    expect(app(TargetRoleQueries::class)->all())->toBe(['php developer', 'go developer']);
});

test('a role longer than 60 characters is not sent', function () {
    Profile::factory()->create(['target_roles' => [str_repeat('a', 61), 'qa engineer']]);

    expect(app(TargetRoleQueries::class)->all())->toBe(['qa engineer']);
});
