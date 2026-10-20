<?php

namespace App\Http\Controllers;

use App\Actions\Profile\SaveProfile;
use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\SkillProficiency;
use App\Enums\WorkArrangement;
use App\Http\Requests\OnboardingRequest;
use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Show the form where the user states the jobs they want and the skills they have.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $profile = $user->profile;

        return Inertia::render('onboarding', [
            'profile' => [
                'headline' => $profile?->headline,
                'target_roles' => $profile->target_roles ?? [],
                'preferred_locations' => $profile->preferred_locations ?? [],
                'work_arrangements' => $profile?->work_arrangements->map(fn (WorkArrangement $arrangement) => $arrangement->value)->all() ?? [],
                'min_salary' => $profile?->min_salary,
                'salary_currency' => $profile?->salary_currency->value ?? Currency::Idr->value,
                'salary_period' => $profile?->salary_period->value ?? SalaryPeriod::Monthly->value,
            ],
            'skills' => $user->skills->sortBy('name')->values()->map(fn ($skill) => [
                'name' => $skill->name,
                'proficiency' => $skill->pivot->proficiency?->value,
            ]),
            'isComplete' => $profile?->isComplete() ?? false,
            'skillSuggestions' => Skill::query()->where('is_verified', true)->orderBy('name')->pluck('name'),
            'options' => [
                'workArrangements' => $this->options(WorkArrangement::cases()),
                'currencies' => $this->options(Currency::cases()),
                'salaryPeriods' => $this->options(SalaryPeriod::cases()),
                'proficiencies' => $this->options(SkillProficiency::cases()),
            ],
        ]);
    }

    /**
     * Save the user's preferences and skills.
     */
    public function update(OnboardingRequest $request, SaveProfile $saveProfile): RedirectResponse
    {
        $saveProfile->handle($request->user(), $request->preferences(), $request->skills());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferences saved.')]);

        return to_route('dashboard');
    }

    /**
     * @param  list<WorkArrangement|Currency|SalaryPeriod|SkillProficiency>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn (WorkArrangement|Currency|SalaryPeriod|SkillProficiency $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }
}
