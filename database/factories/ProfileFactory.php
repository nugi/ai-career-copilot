<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'headline' => fake()->randomElement([
                'Backend engineer focused on Laravel and PostgreSQL',
                'Full stack developer building with React and Node.js',
                'Mobile developer shipping Flutter apps',
            ]),
            'target_roles' => fake()->randomElements(['Backend Engineer', 'Full Stack Developer', 'Software Engineer', 'Platform Engineer'], 2),
            'preferred_locations' => fake()->randomElements(['Jakarta', 'Bandung', 'Yogyakarta', 'Surabaya'], 2),
            'work_arrangements' => [WorkArrangement::Remote, WorkArrangement::Hybrid],
            'min_salary' => fake()->numberBetween(15, 30) * 1_000_000,
            'salary_currency' => Currency::Idr,
            'salary_period' => SalaryPeriod::Monthly,
            'completed_at' => now(),
        ];
    }

    /**
     * A user who started onboarding but has not finished it.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed_at' => null,
        ]);
    }

    /**
     * A user who only wants remote roles paid yearly in USD.
     */
    public function remoteOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'preferred_locations' => [],
            'work_arrangements' => [WorkArrangement::Remote],
            'min_salary' => fake()->numberBetween(8, 14) * 5_000,
            'salary_currency' => Currency::Usd,
            'salary_period' => SalaryPeriod::Yearly,
        ]);
    }

    /**
     * A user who has not stated a minimum salary.
     */
    public function withoutSalary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'min_salary' => null,
            'salary_currency' => null,
            'salary_period' => null,
        ]);
    }
}
