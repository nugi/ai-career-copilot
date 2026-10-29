<?php

use App\Actions\Jobs\JobSearch;
use App\Actions\Jobs\SearchOpenings;
use App\Enums\Currency;
use App\Enums\JobRegion;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Models\ExchangeRate;
use App\Models\JobPosting;
use App\Models\Opening;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

test("an opening shows its preferred live posting and falls back to another posting's pay", function () {
    $this->freezeTime();
    $opening = Opening::factory()->create();
    $representative = JobPosting::factory()->for($opening)->withoutSalary()->create([
        'source' => 'himalayas', 'expires_at' => null, 'posted_at' => now()->subDays(2),
    ]);
    $pay = JobPosting::factory()->for($opening)->remote()->create([
        'source' => 'jobicy', 'expires_at' => null, 'posted_at' => now()->subDay(),
    ]);
    JobPosting::factory()->for($opening)->expired()->create(['source' => 'himalayas']);
    JobPosting::factory()->expired()->create();
    $profile = Profile::factory()->withoutSalary()->make();

    $results = app(SearchOpenings::class)->handle(new JobSearch([], null, null, false), $profile);

    expect($results->items())->toHaveCount(1);
    $result = $results->items()[0];
    expect($result->id)->toBe($representative->id);
    foreach (['salary_min', 'salary_max', 'salary_currency', 'salary_period', 'annual_salary_min', 'annual_salary_max'] as $column) {
        expect($result->getAttribute('pay_'.$column))->toEqual($pay->getRawOriginal($column));
    }
});

test('the arrangement filter keeps only the chosen arrangements', function () {
    $this->freezeTime();
    $postings = JobPosting::factory()->count(3)->sequence(
        ['work_arrangement' => WorkArrangement::Remote],
        ['work_arrangement' => WorkArrangement::Hybrid],
        ['work_arrangement' => WorkArrangement::Onsite],
    )->create(['expires_at' => null]);
    $profile = Profile::factory()->withoutSalary()->make();

    $results = app(SearchOpenings::class)->handle(new JobSearch([WorkArrangement::Remote, WorkArrangement::Hybrid], null, null, false), $profile);

    expect(collect($results->items())->pluck('id')->all())->toEqualCanonicalizing($postings->take(2)->pluck('id')->all());
});

test('the country filter keeps postings open to that country', function () {
    $this->freezeTime();
    $indonesia = JobPosting::factory()->create(['country_code' => 'ID', 'country_codes' => ['ID'], 'expires_at' => null]);
    JobPosting::factory()->create(['country_code' => 'DE', 'country_codes' => ['DE'], 'expires_at' => null]);
    $worldwide = JobPosting::factory()->remote()->create(['expires_at' => null]);
    $apac = JobPosting::factory()->remote()->create(['region' => JobRegion::Apac, 'expires_at' => null]);
    JobPosting::factory()->remote()->create(['region' => JobRegion::Europe, 'expires_at' => null]);
    $profile = Profile::factory()->withoutSalary()->make();

    $results = app(SearchOpenings::class)->handle(new JobSearch([], 'ID', null, false), $profile);

    expect(collect($results->items())->pluck('id')->all())->toEqualCanonicalizing([$indonesia->id, $worldwide->id, $apac->id]);
});

test('the skill filter keeps postings that ask for the skill', function () {
    $this->freezeTime();
    $skill = Skill::factory()->create();
    $required = JobPosting::factory()->create(['expires_at' => null]);
    $required->skills()->attach($skill, ['is_required' => true]);
    $preferred = JobPosting::factory()->create(['expires_at' => null]);
    $preferred->skills()->attach($skill, ['is_required' => false]);
    JobPosting::factory()->create(['expires_at' => null]);
    $profile = Profile::factory()->withoutSalary()->make();

    $results = app(SearchOpenings::class)->handle(new JobSearch([], null, $skill, false), $profile);

    expect(collect($results->items())->pluck('id')->all())->toEqualCanonicalizing([$required->id, $preferred->id]);
});

test('the floor filter removes only pay that is certainly below the floor', function () {
    $this->freezeTime();
    $profile = Profile::factory()->make([
        'min_salary' => 20_000_000, 'salary_currency' => Currency::Idr, 'salary_period' => SalaryPeriod::Monthly,
    ]);
    ExchangeRate::factory()->create(['currency' => 'USD', 'rate' => 1.1188]);
    ExchangeRate::factory()->create(['currency' => 'IDR', 'rate' => 20320.32]);

    /**
     * Rp20,000,000 × 13 (THR) = Rp260,000,000 annually.
     * 95% = Rp247,000,000; USD threshold = floor(247,000,000 × 1.1188 / 20320.32) = 13,599.
     */
    $above = JobPosting::factory()->remote()->create([
        'salary_min' => null, 'annual_salary_min' => null, 'salary_max' => 60_000, 'annual_salary_max' => 60_000, 'expires_at' => null,
    ]);
    JobPosting::factory()->remote()->create([
        'salary_min' => null, 'annual_salary_min' => null, 'salary_max' => 10_000, 'annual_salary_max' => 10_000, 'expires_at' => null,
    ]);
    JobPosting::factory()->create([
        'salary_min' => null, 'annual_salary_min' => null, 'salary_max' => 15_000_000, 'annual_salary_max' => 195_000_000, 'expires_at' => null,
    ]);
    $undisclosed = JobPosting::factory()->withoutSalary()->create(['expires_at' => null]);
    $unknownCurrency = JobPosting::factory()->remote()->create([
        'salary_currency' => 'PLN', 'salary_min' => null, 'annual_salary_min' => null,
        'salary_max' => 1_000, 'annual_salary_max' => 1_000, 'expires_at' => null,
    ]);
    $unbounded = JobPosting::factory()->remote()->create([
        'salary_min' => 5_000, 'annual_salary_min' => 5_000, 'salary_max' => null, 'annual_salary_max' => null, 'expires_at' => null,
    ]);

    $results = app(SearchOpenings::class)->handle(new JobSearch([], null, null, true), $profile);

    expect(collect($results->items())->pluck('id')->all())->toEqualCanonicalizing([$above->id, $undisclosed->id, $unknownCurrency->id, $unbounded->id]);
});

test('openings are listed by when the app first saw them, across cursor pages', function () {
    $this->freezeTime();
    $openings = collect(range(1, 21))->map(fn (int $daysAgo): Opening => Opening::factory()->create(['created_at' => now()->subDays($daysAgo)]));
    $openings->each(fn (Opening $opening, int $index) => JobPosting::factory()->for($opening)->create([
        'expires_at' => null, 'posted_at' => now()->subDays(21 - $index),
    ]));
    $profile = Profile::factory()->withoutSalary()->make();
    $search = new JobSearch([], null, null, false);

    $firstPage = app(SearchOpenings::class)->handle($search, $profile);
    request()->query->set('cursor', $firstPage->nextCursor()->encode());
    $secondPage = app(SearchOpenings::class)->handle($search, $profile);

    $listed = collect([...$firstPage->items(), ...$secondPage->items()])->pluck('opening_id')->all();
    expect($listed)->toBe($openings->pluck('id')->all());
});

test('the list stays at a fixed number of queries', function () {
    $this->freezeTime();
    $skill = Skill::factory()->create();
    JobPosting::factory()->count(2)->hasAttached($skill, ['is_required' => true])->create(['expires_at' => null]);
    $profile = Profile::factory()->withoutSalary()->make();
    $search = new JobSearch([], null, null, false);
    $action = app(SearchOpenings::class);
    $queryCount = function () use ($action, $search, $profile): int {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $results = $action->handle($search, $profile);
            foreach ($results as $posting) {
                $posting->skills->all();
            }

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    };

    $smallCount = $queryCount();
    JobPosting::factory()->count(5)->hasAttached($skill, ['is_required' => true])->create(['expires_at' => null]);
    $largeCount = $queryCount();

    expect($largeCount)->toBe($smallCount);
});
