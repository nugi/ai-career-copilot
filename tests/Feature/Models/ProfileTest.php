<?php

use App\Enums\WorkArrangement;
use App\Models\Profile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a profile reads its work arrangements as enums', function () {
    $profile = Profile::factory()->create(['work_arrangements' => ['remote', 'hybrid']]);

    expect($profile->fresh()->work_arrangements->all())->toBe([WorkArrangement::Remote, WorkArrangement::Hybrid]);
});

test('a profile may leave its minimum salary empty', function () {
    $profile = Profile::factory()->withoutSalary()->create();

    $this->assertModelExists($profile);
});

test('the database rejects an invalid minimum salary', function (array $salary, string $constraint) {
    expect(fn () => Profile::factory()->create($salary))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'negative amount' => [['min_salary' => -1], 'profiles_min_salary_check'],
    'amount without currency' => [['salary_currency' => null], 'profiles_salary_unit_check'],
    'amount without period' => [['salary_period' => null], 'profiles_salary_unit_check'],
]);

test('the database rejects an unsupported currency written without the model', function () {
    $profile = Profile::factory()->create();

    expect(fn () => DB::table('profiles')->where('id', $profile->id)->update(['salary_currency' => 'EUR']))
        ->toThrow(QueryException::class, 'profiles_salary_currency_check');
});
