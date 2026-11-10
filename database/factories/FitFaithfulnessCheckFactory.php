<?php

namespace Database\Factories;

use App\Ai\Agents\FaithfulnessJudge;
use App\Ai\Agents\FitExplainer;
use App\Models\FitFaithfulnessCheck;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FitFaithfulnessCheck>
 */
class FitFaithfulnessCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'prompt_version' => FitExplainer::PROMPT_VERSION,
            'judge_prompt_version' => FaithfulnessJudge::PROMPT_VERSION,
            'claims' => 3,
            'supported' => 3,
            'unsupported' => 0,
            'unjudged' => 0,
            'score' => '1.000',
        ];
    }
}
