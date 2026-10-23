<?php

namespace Database\Factories;

use App\Enums\AiFeature;
use App\Enums\AiUsageStatus;
use App\Enums\ModelTier;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inputTokens = fake()->numberBetween(500, 8_000);
        $outputTokens = fake()->numberBetween(100, 1_500);

        return [
            'user_id' => User::factory(),
            'feature' => fake()->randomElement([AiFeature::FitScoring, AiFeature::SkillGapPlan, AiFeature::ResumeSuggestion, AiFeature::Chat]),
            'invocation_id' => fake()->uuid(),
            'agent' => 'CareerAdvisor',
            'tier' => ModelTier::Strong,
            'prompt_version' => 'v2',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'status' => AiUsageStatus::Succeeded,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'tokens_estimated' => false,
            'cost_usd' => round(($inputTokens * 3 + $outputTokens * 15) / 1_000_000, 6),
            'latency_ms' => fake()->numberBetween(400, 6_000),
        ];
    }
}
