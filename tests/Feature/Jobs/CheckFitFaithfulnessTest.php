<?php

use App\Ai\Agents\FaithfulnessJudge;
use App\Ai\DailyBudget;
use App\Jobs\CheckFitFaithfulness;
use App\Models\AiUsage;
use App\Models\JobPosting;
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

    $posting = JobPosting::factory()->create(['id' => 7]);

    (new CheckFitFaithfulness($posting->id, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    $this->assertDatabaseCount('fit_faithfulness_checks', 1);
    $this->assertDatabaseHas('fit_faithfulness_checks', [
        'job_posting_id' => $posting->id,
        'claims' => 2, 'supported' => 1, 'unsupported' => 1, 'unjudged' => 0, 'score' => 0.5,
        'prompt_version' => 'v1', 'judge_prompt_version' => FaithfulnessJudge::PROMPT_VERSION,
    ]);

    FaithfulnessJudge::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("<claim id=\"2\">\n<text>You ran Kubernetes in production.</text>")
        && $prompt->contains('Skills: Docker'));
    Log::shouldHaveReceived('info')->with('fit.faithfulness', Mockery::on(fn (mixed $context): bool => is_array($context) && ($context['job_posting_id'] ?? null) === 7 && isset($context['score'])
        && $context['claims'] === 2
        && $context['supported'] === 1
        && $context['unjudged'] === 0
        && $context['score'] === 0.5
        && $context['unsupported'] === ['You ran Kubernetes in production.']));
});

test('a claim that the judge leaves out counts as unjudged', function (): void {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    Log::spy();
    FaithfulnessJudge::fake([['claims' => [['claim_id' => 1, 'supported' => true]]]]);

    $posting = JobPosting::factory()->create();

    (new CheckFitFaithfulness($posting->id, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    $this->assertDatabaseHas('fit_faithfulness_checks', [
        'job_posting_id' => $posting->id,
        'claims' => 2, 'supported' => 1, 'unsupported' => 0, 'unjudged' => 1, 'score' => 1.0,
    ]);
    Log::shouldHaveReceived('info')->with('fit.faithfulness', Mockery::on(fn (mixed $context): bool => is_array($context)
        && ($context['score'] ?? null) === 1.0 && $context['unjudged'] === 1 && $context['unsupported'] === []));
});

test('no judged claim stores a null score', function (): void {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    FaithfulnessJudge::fake([['claims' => []]]);
    $posting = JobPosting::factory()->create();

    (new CheckFitFaithfulness($posting->id, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    $this->assertDatabaseHas('fit_faithfulness_checks', [
        'job_posting_id' => $posting->id,
        'claims' => 2, 'supported' => 0, 'unsupported' => 0, 'unjudged' => 2, 'score' => null,
    ]);
});

test('a spent batch budget skips the check', function () {
    config(['ai-routing.batch_daily_budget_usd' => 0.01, 'ai-routing.global_daily_budget_usd' => null]);
    AiUsage::factory()->create(['feature' => 'embedding', 'cost_usd' => 0.02, 'created_at' => now()]);
    FaithfulnessJudge::fake()->preventStrayPrompts();

    (new CheckFitFaithfulness(7, 'v1', judgedReasons()))->handle(app(DailyBudget::class));

    FaithfulnessJudge::assertNeverPrompted();
    $this->assertDatabaseCount('fit_faithfulness_checks', 0);
});
