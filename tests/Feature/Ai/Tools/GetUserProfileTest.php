<?php

use App\Ai\Tools\GetUserProfile;
use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Enums\WorkArrangement;
use App\Models\Profile;
use App\Models\Skill;
use Laravel\Ai\Tools\Request;

test('it returns the profile of its own user', function () {
    $profile = Profile::factory()->create([
        'target_roles' => ['Laravel Developer'], 'country_code' => 'ID',
        'work_arrangements' => [WorkArrangement::Remote],
        'min_salary' => 4000, 'salary_currency' => Currency::Usd, 'salary_period' => SalaryPeriod::Monthly,
    ]);
    $profile->user->skills()->attach(Skill::factory()->create(['name' => 'PHP']), [
        'proficiency' => SkillProficiency::Expert, 'source' => SkillSource::Profile,
    ]);
    $other = Profile::factory()->withoutSalary()->create(['target_roles' => ['Python Developer'], 'country_code' => 'US']);
    $this->actingAs($other->user);

    $result = json_decode(app()->make(GetUserProfile::class, ['user' => $profile->user])->handle(new Request(['user_id' => $other->user_id])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toBe([
        'target_roles' => ['Laravel Developer'], 'skills' => ['PHP'], 'country' => 'ID',
        'work_arrangements' => ['remote'],
        'salary_floor' => ['amount' => 4000, 'currency' => 'USD', 'period' => 'monthly'],
    ]);
});
