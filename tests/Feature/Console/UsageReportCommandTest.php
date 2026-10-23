<?php

use App\Enums\AiUsageStatus;
use App\Enums\ModelTier;
use App\Models\AiUsage;

test('the report groups the last days by tier, agent, provider and model and adds tier totals', function () {
    AiUsage::factory()->create([
        'tier' => ModelTier::Strong,
        'agent' => 'CareerAdvisor',
        'provider' => 'openai',
        'model' => 'gpt-6.1-sol',
        'status' => AiUsageStatus::Succeeded,
        'input_tokens' => 1_000,
        'output_tokens' => 500,
        'cost_usd' => 0.1,
        'latency_ms' => 1_000,
        'created_at' => now()->subDay(),
    ]);

    AiUsage::factory()->create([
        'tier' => ModelTier::Cheap,
        'agent' => 'ResumeParser',
        'provider' => 'openai',
        'model' => 'gpt-6-luna',
        'status' => AiUsageStatus::FailedOver,
        'input_tokens' => 200,
        'output_tokens' => 100,
        'tokens_estimated' => true,
        'cost_usd' => 0.01,
        'latency_ms' => 500,
        'created_at' => now()->subDay(),
    ]);

    AiUsage::factory()->create([
        'tier' => ModelTier::Strong,
        'agent' => 'CareerAdvisor',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5-5',
        'cost_usd' => 9.99,
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('usage:report')
        ->expectsTable(
            ['Tier', 'Agent', 'Provider', 'Model', 'Calls', 'Failed', 'Estimated', 'Input tokens', 'Output tokens', 'Cost (USD)', 'Avg latency (ms)'],
            [
                ['cheap', 'ResumeParser', 'openai', 'gpt-6-luna', 1, 1, 1, 200, 100, '0.0100', 500],
                ['strong', 'CareerAdvisor', 'openai', 'gpt-6.1-sol', 1, 0, 0, 1_000, 500, '0.1000', 1_000],
            ],
        )
        ->expectsTable(
            ['Tier', 'Calls', 'Cost (USD)'],
            [
                ['cheap', 1, '0.0100'],
                ['strong', 1, '0.1000'],
                ['Total', 2, '0.1100'],
            ],
        )
        ->assertExitCode(0);
});

test('the report says so when there are no rows', function () {
    $this->artisan('usage:report')
        ->expectsOutputToContain('No AI usage in the last 7 days.')
        ->assertExitCode(0);
});

test('a number of days below one is rejected', function () {
    $this->artisan('usage:report', ['--days' => 0])
        ->assertExitCode(1);
});
