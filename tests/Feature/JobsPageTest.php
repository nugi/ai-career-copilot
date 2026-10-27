<?php

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Models\ExchangeRate;
use App\Models\JobPosting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('jobs.index'))->assertRedirect(route('login'));
});

test('the jobs page lists open openings with converted pay', function () {
    $this->freezeTime();
    $user = User::factory()->onboarded()->create();
    $user->profile()->update([
        'min_salary' => 20_000_000, 'salary_currency' => Currency::Idr, 'salary_period' => SalaryPeriod::Monthly,
    ]);
    ExchangeRate::factory()->create(['currency' => 'USD', 'rate' => 1.1188]);
    ExchangeRate::factory()->create(['currency' => 'IDR', 'rate' => 20320.32]);
    $posting = JobPosting::factory()->remote()->create([
        'title' => 'Backend Engineer', 'salary_min' => 40_000, 'salary_max' => 60_000,
        'annual_salary_min' => 40_000, 'annual_salary_max' => 60_000, 'expires_at' => null,
    ]);
    JobPosting::factory()->expired()->create();

    $this->actingAs($user)->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs')
            ->has('jobs.data', 1)
            ->where('jobs.data.0.opening_id', $posting->opening_id)
            ->where('jobs.data.0.title', 'Backend Engineer')
            ->where('jobs.data.0.pay.currency', 'USD')
            ->where('jobs.data.0.converted.currency', 'IDR')
            ->where('jobs.data.0.converted.period', 'monthly')
            ->where('jobs.data.0.converted.min', 55_884_932)
            ->where('jobs.data.0.converted.max', 83_827_397)
        );
});

test('an invalid filter is rejected', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->from(route('jobs.index'))
        ->get(route('jobs.index', ['arrangement' => ['office']]))
        ->assertRedirect(route('jobs.index'))
        ->assertSessionHasErrors('arrangement.0');
});
