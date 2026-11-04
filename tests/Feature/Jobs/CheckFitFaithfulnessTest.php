<?php

use App\Ai\Agents\FaithfulnessJudge;
use App\Ai\DailyBudget;
use App\Jobs\CheckFitFaithfulness;
use App\Models\AiUsage;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Two reasons as ExplainFit saves them.
 *
 * @return list<array{claim: string, excerpts: list<array{id: int, section: string, content: string}>}>
 */
function judgedReasons(): array
{
    return [
        ['claim' => 'You built Laravel APIs.', 'excerpts' => [['id' => 1, 'section' => 'experience', 'content' => 'Built Laravel APIs for checkout.']]],
        ['claim' => 'You ran Kubernetes in production.', 'excerpts' => [['id' => 2, 'section' => 'skills', 'content' => 'Skills: Docker']]],
    ];
}

test('the check sends each claim with its excerpts and logs the share of supported claims', function () {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    Log::spy();
    FaithfulnessJudge::fake([['claims' => [
        ['claim_id' => 1, 'supported' => true],
        ['claim_id' => 2, 'supported' => false],
    ]]]);

    (new CheckFitFaithfulness(7, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    FaithfulnessJudge::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("<claim id=\"2\">\n<text>You ran Kubernetes in production.</text>")
        && $prompt->contains('Skills: Docker'));
    Log::shouldHaveReceived('info')->with('fit.faithfulness', Mockery::on(fn (mixed $context): bool => is_array($context) && ($context['job_posting_id'] ?? null) === 7 && isset($context['score'])
        && $context['claims'] === 2
        && $context['supported'] === 1
        && $context['score'] === 0.5
        && $context['unsupported'] === ['You ran Kubernetes in production.']));
});

test('a claim that the judge leaves out counts as unsupported', function () {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    Log::spy();
    FaithfulnessJudge::fake([['claims' => [['claim_id' => 1, 'supported' => true]]]]);

    (new CheckFitFaithfulness(7, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    Log::shouldHaveReceived('info')->with('fit.faithfulness', Mockery::on(fn (mixed $context): bool => is_array($context) && ($context['score'] ?? null) === 0.5));
});

test('a spent batch budget skips the check', function () {
    config(['ai-routing.batch_daily_budget_usd' => 0.01, 'ai-routing.global_daily_budget_usd' => null]);
    AiUsage::factory()->create(['feature' => 'embedding', 'cost_usd' => 0.02, 'created_at' => now()]);
    FaithfulnessJudge::fake()->preventStrayPrompts();

    (new CheckFitFaithfulness(7, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    FaithfulnessJudge::assertNeverPrompted();
});
