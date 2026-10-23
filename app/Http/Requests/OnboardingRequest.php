<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\SkillProficiency;
use App\Enums\WorkArrangement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OnboardingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:120'],
            'target_roles' => ['required', 'array', 'max:5'],
            'target_roles.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'preferred_locations' => ['nullable', 'array', 'max:10'],
            'preferred_locations.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'work_arrangements' => ['required', 'array'],
            'work_arrangements.*' => ['required', Rule::enum(WorkArrangement::class), 'distinct'],
            'min_salary' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'salary_currency' => ['nullable', 'required_with:min_salary', Rule::enum(Currency::class)],
            'salary_period' => ['nullable', 'required_with:min_salary', Rule::enum(SalaryPeriod::class)->only(SalaryPeriod::profileCases())],
            'skills' => ['required', 'array', 'max:50'],
            'skills.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'skills.*.proficiency' => ['required', Rule::enum(SkillProficiency::class)],
        ];
    }

    /**
     * Get the custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_roles.required' => 'Add at least one target role.',
            'work_arrangements.required' => 'Choose at least one way of working.',
            'salary_currency.required_with' => 'Choose the currency of your minimum salary.',
            'salary_period.required_with' => 'Choose whether your minimum salary is per month or per year.',
            'skills.required' => 'Add at least one skill.',
            'target_roles.*.distinct' => 'This role is already in your list.',
            'preferred_locations.*.distinct' => 'This location is already in your list.',
            'skills.*.name.distinct' => 'This skill is already in your list.',
            'skills.*.proficiency.required' => 'Choose a level for each skill.',
        ];
    }

    /**
     * The profile attributes to save.
     *
     * @return array{headline: string|null, target_roles: list<string>, preferred_locations: list<string>, work_arrangements: list<string>, min_salary: int|null, salary_currency: string|null, salary_period: string|null}
     */
    public function preferences(): array
    {
        $hasSalary = $this->filled('min_salary');

        return [
            'headline' => $this->validated('headline'),
            'target_roles' => $this->validated('target_roles'),
            'preferred_locations' => $this->validated('preferred_locations') ?? [],
            'work_arrangements' => $this->validated('work_arrangements'),
            'min_salary' => $hasSalary ? $this->integer('min_salary') : null,
            'salary_currency' => $hasSalary ? $this->validated('salary_currency') : null,
            'salary_period' => $hasSalary ? $this->validated('salary_period') : null,
        ];
    }

    /**
     * The skills the user listed, as typed, with their proficiency.
     *
     * @return list<array{name: string, proficiency: string}>
     */
    public function skills(): array
    {
        return $this->validated('skills');
    }

    /**
     * Trim the free-text entries and drop empty ones before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'headline' => $this->filled('headline') ? Str::squish($this->string('headline')) : null,
            'target_roles' => $this->cleanList($this->input('target_roles')),
            'preferred_locations' => $this->cleanList($this->input('preferred_locations')),
            'skills' => $this->cleanSkills($this->input('skills')),
        ]);
    }

    /**
     * @return mixed The list without blank entries, or the original input when it is not a list of strings.
     */
    private function cleanList(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }

        return collect($values)
            ->map(fn (mixed $value) => is_string($value) ? Str::squish($value) : $value)
            ->reject(fn (mixed $value) => $value === '' || $value === null)
            ->values()
            ->all();
    }

    /**
     * @return mixed The skills with trimmed names, or the original input when it is not a list.
     */
    private function cleanSkills(mixed $skills): mixed
    {
        if (! is_array($skills)) {
            return $skills;
        }

        return collect($skills)
            ->map(fn (mixed $skill) => is_array($skill) && is_string($skill['name'] ?? null)
                ? [...$skill, 'name' => Str::squish($skill['name'])]
                : $skill)
            ->values()
            ->all();
    }
}
