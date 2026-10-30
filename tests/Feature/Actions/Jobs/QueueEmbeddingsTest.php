<?php

use App\Actions\Jobs\QueueEmbeddings;
use App\Ai\DailyBudget;
use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Queue;

test('embeddings are not queued when batch work has spent the batch budget', function () {
    Queue::fake();
    config(['ai-routing.global_daily_budget_usd' => 1.00, 'ai-routing.batch_daily_budget_usd' => 0.30]);
    AiUsage::factory()->create(['feature' => AiFeature::SkillExtraction, 'cost_usd' => 0.30, 'created_at' => now()]);
    JobPosting::factory()->create([
        'text_hash' => str_repeat('a', 64),
        'skills_text_hash' => str_repeat('a', 64),
        'expires_at' => now()->addDay(),
    ]);

    expect(app(QueueEmbeddings::class)->handle())->toBe(0)
        ->and(app(DailyBudget::class)->isGloballyExceeded())->toBeFalse();
    Queue::assertNothingPushed();
});
