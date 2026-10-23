<?php

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Enums\WorkArrangement;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A valid onboarding submission, with optional overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function onboardingInput(array $overrides = []): array
{
    return [
        'headline' => 'Backend engineer focused on Laravel',
        'target_roles' => ['Backend Engineer', 'Platform Engineer'],
        'preferred_locations' => ['Jakarta'],
        'work_arrangements' => ['remote', 'hybrid'],
        'min_salary' => '20000000',
        'salary_currency' => 'IDR',
        'salary_period' => 'monthly',
        'skills' => [
            ['name' => 'Laravel', 'proficiency' => 'expert'],
            ['name' => 'PostgreSQL', 'proficiency' => 'advanced'],
        ],
        ...$overrides,
    ];
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('onboarding.edit'));

    $response->assertRedirect(route('login'));
});

test('users who have not finished onboarding are sent to it from the dashboard', function (User $user) {
    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('onboarding.edit'));
})->with([
    'no profile' => fn () => User::factory()->create(),
    'incomplete profile' => fn () => User::factory()->has(Profile::factory()->incomplete())->create(),
]);

test('the onboarding page shows the saved preferences and skills', function () {
    $user = User::factory()->has(Profile::factory()->incomplete()->state(['target_roles' => ['Backend Engineer']]))->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => SkillProficiency::Expert, 'source' => SkillSource::Profile]);

    $response = $this->actingAs($user)->get(route('onboarding.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('onboarding')
        ->where('profile.target_roles', ['Backend Engineer'])
        ->where('skills', [['name' => 'Laravel', 'proficiency' => 'expert']])
        ->where('isComplete', false)
    );
});

test('the onboarding page shows a resume skill without a proficiency', function () {
    $user = User::factory()->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'Docker']), ['proficiency' => null, 'source' => SkillSource::Resume]);

    $response = $this->actingAs($user)->get(route('onboarding.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('onboarding')
        ->where('skills', [['name' => 'Docker', 'proficiency' => null]])
    );
});

test('a new user sees default salary units', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('onboarding.edit'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('profile.salary_currency', 'IDR')
        ->where('profile.salary_period', 'monthly')
        ->where('profile.target_roles', [])
    );
});

test('saving preferences completes onboarding and opens the dashboard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->put(route('onboarding.update'), onboardingInput());

    $response->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    $profile = $user->refresh()->profile;
    expect($profile->only('target_roles', 'preferred_locations', 'min_salary', 'salary_currency', 'salary_period'))->toBe([
        'target_roles' => ['Backend Engineer', 'Platform Engineer'],
        'preferred_locations' => ['Jakarta'],
        'min_salary' => 20_000_000,
        'salary_currency' => Currency::Idr,
        'salary_period' => SalaryPeriod::Monthly,
    ])
        ->and($profile->work_arrangements->all())->toBe([WorkArrangement::Remote, WorkArrangement::Hybrid])
        ->and($profile->isComplete())->toBeTrue();
    expect($user->skills->mapWithKeys(fn (Skill $skill) => [$skill->name => $skill->pivot->proficiency])->all())->toBe([
        'Laravel' => SkillProficiency::Expert,
        'PostgreSQL' => SkillProficiency::Advanced,
    ]);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('entries are trimmed and blank entries are ignored', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('onboarding.update'), onboardingInput([
        'target_roles' => ['  Backend   Engineer ', ''],
        'preferred_locations' => [' ', 'Bandung '],
    ]));

    expect($user->refresh()->profile->only('target_roles', 'preferred_locations'))->toBe([
        'target_roles' => ['Backend Engineer'],
        'preferred_locations' => ['Bandung'],
    ]);
});

test('a salary unit without an amount is not stored', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('onboarding.update'), onboardingInput(['min_salary' => '']));

    expect($user->refresh()->profile->only('min_salary', 'salary_currency', 'salary_period'))->toBe([
        'min_salary' => null,
        'salary_currency' => null,
        'salary_period' => null,
    ]);
});

test('skills the user types are matched to canonical skills by name or alias', function () {
    $kubernetes = Skill::factory()->create(['name' => 'Kubernetes']);
    $kubernetes->aliases()->create(['alias' => 'k8s']);
    $laravel = Skill::factory()->create(['name' => 'Laravel']);
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('onboarding.update'), onboardingInput([
        'skills' => [
            ['name' => ' LARAVEL ', 'proficiency' => 'expert'],
            ['name' => 'K8s', 'proficiency' => 'beginner'],
        ],
    ]));

    expect($user->skills->pluck('id')->sort()->values()->all())->toBe([$kubernetes->id, $laravel->id])
        ->and(Skill::query()->count())->toBe(2);
});

test('an unknown skill is saved as unverified', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('onboarding.update'), onboardingInput([
        'skills' => [['name' => 'Svelte', 'proficiency' => 'intermediate']],
    ]));

    expect($user->skills->sole()->only('name', 'normalized_name', 'is_verified'))->toBe([
        'name' => 'Svelte',
        'normalized_name' => 'svelte',
        'is_verified' => false,
    ]);
});

test('skill suggestions include only verified skills', function () {
    Skill::factory()->create(['name' => 'Laravel']);
    Skill::factory()->unverified()->create(['name' => 'Laravell']);

    $response = $this->actingAs(User::factory()->create())->get(route('onboarding.edit'));

    $response->assertInertia(fn (Assert $page) => $page->where('skillSuggestions', ['Laravel']));
});

test('invalid preferences are rejected with a message and nothing is saved', function (array $overrides, string $field, string $message) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('onboarding.edit'))
        ->put(route('onboarding.update'), onboardingInput($overrides));

    $response->assertRedirect(route('onboarding.edit'))->assertSessionHasErrors([$field => $message]);
    expect($user->profile()->exists())->toBeFalse()
        ->and($user->skills()->exists())->toBeFalse();
})->with([
    'no target role' => [['target_roles' => []], 'target_roles', 'Add at least one target role.'],
    'only blank target roles' => [['target_roles' => ['  ']], 'target_roles', 'Add at least one target role.'],
    'the same role twice' => [['target_roles' => ['Backend Engineer', 'backend engineer']], 'target_roles.1', 'This role is already in your list.'],
    'no way of working' => [['work_arrangements' => []], 'work_arrangements', 'Choose at least one way of working.'],
    'unknown way of working' => [['work_arrangements' => ['office']], 'work_arrangements.0', 'The selected work_arrangements.0 is invalid.'],
    'salary without currency' => [['salary_currency' => ''], 'salary_currency', 'Choose the currency of your minimum salary.'],
    'salary without period' => [['salary_period' => ''], 'salary_period', 'Choose whether your minimum salary is per month or per year.'],
    'hourly salary floor' => [['salary_period' => 'hourly'], 'salary_period', 'The selected salary period is invalid.'],
    'unsupported currency' => [['salary_currency' => 'EUR'], 'salary_currency', 'The selected salary currency is invalid.'],
    'salary that is not a number' => [['min_salary' => '20 juta'], 'min_salary', 'The min salary field must be an integer.'],
    'no skills' => [['skills' => []], 'skills', 'Add at least one skill.'],
    'the same skill twice' => [['skills' => [['name' => 'Laravel', 'proficiency' => 'expert'], ['name' => 'laravel', 'proficiency' => 'beginner']]], 'skills.1.name', 'This skill is already in your list.'],
    'unknown proficiency' => [['skills' => [['name' => 'Laravel', 'proficiency' => 'guru']]], 'skills.0.proficiency', 'The selected skills.0.proficiency is invalid.'],
]);
