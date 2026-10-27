<?php

use App\Actions\Jobs\QueueSkillExtraction;
use App\Ai\Agents\JobSkillExtractor;
use App\Ai\DailyBudget;
use App\Enums\AiFeature;
use App\Jobs\ExtractJobSkills;
use App\JobSources\Normalization\NormalizedPosting;
use App\Models\AiUsage;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Queue;

/**
 * A live posting whose text hash is the hash of its own title and description, as an import stores it.
 *
 * @param  array<string, mixed>  $attributes
 */
function queuedForSkillsPosting(string $title, string $description, array $attributes = []): JobPosting
{
    return JobPosting::factory()->create([
        'title' => $title,
        'description' => $description,
        'text_hash' => NormalizedPosting::textHash($title, $description),
        'expires_at' => now()->addDays(20),
        ...$attributes,
    ]);
}

test('pending postings are queued once per text', function () {
    Queue::fake();

    $currentText = NormalizedPosting::textHash('Backend Engineer', 'You will work with Laravel.');

    $current = queuedForSkillsPosting('Backend Engineer', 'You will work with Laravel.', [
        'source' => 'board-a',
        'skills_text_hash' => $currentText,
        'skills_prompt_version' => JobSkillExtractor::PROMPT_VERSION,
        'skills_extracted_at' => now(),
    ]);
    $sameText = queuedForSkillsPosting('Backend Engineer', 'You will work with Laravel.', ['source' => 'board-b']);
    $otherText = queuedForSkillsPosting('Data Engineer', 'You will work with Python.', ['source' => 'board-b']);
    $expired = queuedForSkillsPosting('QA Engineer', 'You will work with Playwright.', [
        'source' => 'board-b',
        'expires_at' => now()->subDay(),
    ]);

    $queued = app(QueueSkillExtraction::class)->handle();

    expect($queued)->toBe(2);
    Queue::assertPushed(ExtractJobSkills::class, 2);
    Queue::assertPushed(ExtractJobSkills::class, fn (ExtractJobSkills $job): bool => $job->posting->is($sameText));
    Queue::assertPushed(ExtractJobSkills::class, fn (ExtractJobSkills $job): bool => $job->posting->is($otherText));
    Queue::assertNotPushed(ExtractJobSkills::class, fn (ExtractJobSkills $job): bool => $job->posting->is($current));
    Queue::assertNotPushed(ExtractJobSkills::class, fn (ExtractJobSkills $job): bool => $job->posting->is($expired));
});

test('nothing is queued while the global budget is spent', function () {
    Queue::fake();
    config(['ai-routing.global_daily_budget_usd' => 0.05]);
    AiUsage::factory()->create(['cost_usd' => 0.5, 'created_at' => now()]);
    queuedForSkillsPosting('Backend Engineer', 'You will work with Laravel.');

    $queued = app(QueueSkillExtraction::class)->handle();

    expect($queued)->toBe(0)
        ->and(app(DailyBudget::class)->isGloballyExceeded())->toBeTrue();
    Queue::assertNothingPushed();
});

test('nothing is queued when batch work has spent the batch budget, and the advisor stays open', function () {
    Queue::fake();
    config(['ai-routing.global_daily_budget_usd' => 1.00, 'ai-routing.batch_daily_budget_usd' => 0.30]);
    AiUsage::factory()->create(['feature' => AiFeature::SkillExtraction, 'cost_usd' => 0.30, 'created_at' => now()]);
    queuedForSkillsPosting('Backend Engineer', 'You will work with Laravel.');

    $queued = app(QueueSkillExtraction::class)->handle();

    expect($queued)->toBe(0)
        ->and(app(DailyBudget::class)->isGloballyExceeded())->toBeFalse();
    Queue::assertNothingPushed();
});

test('interactive spend does not count toward the batch budget', function () {
    Queue::fake();
    config(['ai-routing.global_daily_budget_usd' => 1.00, 'ai-routing.batch_daily_budget_usd' => 0.30]);
    AiUsage::factory()->create(['feature' => AiFeature::Chat, 'cost_usd' => 0.50, 'created_at' => now()]);
    queuedForSkillsPosting('Backend Engineer', 'You will work with Laravel.');

    $queued = app(QueueSkillExtraction::class)->handle();

    expect($queued)->toBe(1);
    Queue::assertPushed(ExtractJobSkills::class, 1);
});
