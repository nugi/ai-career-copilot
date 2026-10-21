<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('a verified admin whose email is listed in upper case may view Horizon', function () {
    config(['horizon.admins' => ['ADMIN@EXAMPLE.COM']]);
    $user = User::factory()->create(['email' => 'admin@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});

test('a verified user whose email is not listed may not view Horizon', function () {
    config(['horizon.admins' => ['admin@example.com']]);
    $user = User::factory()->create(['email' => 'someone@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

test('a listed user with an unverified email may not view Horizon', function () {
    config(['horizon.admins' => ['admin@example.com']]);
    $user = User::factory()->unverified()->create(['email' => 'admin@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});
