<?php

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a posting lists its skills and whether each one is required', function () {
    $posting = JobPosting::factory()->create();
    $laravel = Skill::factory()->create(['name' => 'Laravel']);
    $aws = Skill::factory()->create(['name' => 'AWS']);
    $posting->skills()->attach([$laravel->id => ['is_required' => true], $aws->id => ['is_required' => false]]);

    $requirements = $posting->skills()->get()->mapWithKeys(fn (Skill $skill) => [$skill->name => $skill->pivot->is_required]);

    expect($requirements->all())->toBe(['Laravel' => true, 'AWS' => false]);
});

test('a posting stores its salary as whole amounts with a currency and period', function () {
    $posting = JobPosting::factory()->create([
        'salary_min' => 20_000_000,
        'salary_max' => 30_000_000,
        'salary_currency' => Currency::Idr,
        'salary_period' => SalaryPeriod::Monthly,
    ]);

    expect($posting->fresh()->only('salary_min', 'salary_max', 'salary_currency', 'salary_period'))->toBe([
        'salary_min' => 20_000_000,
        'salary_max' => 30_000_000,
        'salary_currency' => Currency::Idr,
        'salary_period' => SalaryPeriod::Monthly,
    ]);
});

test('a posting may leave its salary undisclosed', function () {
    $posting = JobPosting::factory()->withoutSalary()->create();

    $this->assertModelExists($posting);
});

test('the database rejects an invalid salary', function (array $salary, string $constraint) {
    expect(fn () => JobPosting::factory()->create($salary))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'minimum above maximum' => [['salary_min' => 30_000_000, 'salary_max' => 20_000_000], 'job_postings_salary_range_check'],
    'negative maximum' => [['salary_min' => null, 'salary_max' => -1], 'job_postings_salary_range_check'],
    'amount without currency' => [['salary_currency' => null], 'job_postings_salary_unit_check'],
    'amount without period' => [['salary_period' => null], 'job_postings_salary_unit_check'],
]);

test('the database rejects an unsupported currency written without the model', function () {
    $posting = JobPosting::factory()->create();

    expect(fn () => DB::table('job_postings')->where('id', $posting->id)->update(['salary_currency' => 'EUR']))
        ->toThrow(QueryException::class, 'job_postings_salary_currency_check');
});

test('deleting a posting removes its skill links and matches', function () {
    $posting = JobPosting::factory()->withSkills()->create();
    $match = JobMatch::factory()->for($posting)->create();

    $posting->delete();

    $this->assertDatabaseMissing('job_posting_skill', ['job_posting_id' => $posting->id]);
    $this->assertModelMissing($match);
});
