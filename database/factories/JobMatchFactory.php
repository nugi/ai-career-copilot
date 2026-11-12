<?php

namespace Database\Factories;

use App\Actions\Fit\FitInput;
use App\Actions\Fit\FitScorer;
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
        $input = new FitInput(['Laravel', 'PHP'], ['Docker'], ['Laravel', 'PHP'], 3.0, 5, 'senior', 60_000, 70_000, true, 'open');
        $score = app(FitScorer::class)->fromConfig($input);

        return [
            'user_id' => User::factory(),
            'job_posting_id' => JobPosting::factory(),
            'resume_id' => null,
            'score' => $score->score,
            'scoring_version' => $score->version,
            'breakdown' => $score->breakdown,
            'inputs' => $input->toArray(),
            'matched_skills' => $score->matchedSkills,
            'missing_skills' => $score->missingSkills,
            'profile_version' => 1,
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
