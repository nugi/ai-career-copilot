<?php

namespace Database\Factories;

use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobMatch>
 */
class JobMatchFactory extends Factory
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
            'job_posting_id' => JobPosting::factory(),
            'resume_id' => null,
            'score' => fake()->numberBetween(0, 100),
            'reasons' => fake()->randomElements([
                'Matches 4 of 5 required skills',
                'Missing a required skill: Kubernetes',
                'Salary meets your minimum',
                'Seniority matches your experience',
                'Remote role in your time zone',
            ], 3),
            'scored_at' => now(),
        ];
    }
}
