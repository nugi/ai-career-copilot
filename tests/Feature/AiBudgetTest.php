<?php

use App\Ai\Agents\CareerCopilot;
use App\Ai\DailyBudget;
use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;

test('a user under the copilot budget gets an answer', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => null]);
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.20]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('a user whose copilot spend equals the copilot budget gets 429 without asking the agent', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => null]);
    CareerCopilot::fake();
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertTooManyRequests()
        ->assertJson([
            'message' => 'You have used today\'s AI budget. It resets at midnight UTC.',
            'code' => 'ai_budget_exceeded',
        ]);

    CareerCopilot::assertNeverPrompted();
});

test('the copilot budget counts only copilot spend', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => null]);
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::FitScoring, 'cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('other features do not count copilot spend', function () {
    config(['ai-routing.daily_budget_usd' => 0.50, 'ai-routing.copilot.daily_budget_usd' => 0.50]);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.50]);

    expect(app(DailyBudget::class)->isExceeded($user))->toBeFalse()
        ->and(app(DailyBudget::class)->isExceeded($user, AiFeature::Copilot))->toBeTrue();
});

test('a new account is refused past its share of the global budget', function () {
    config(['ai-routing.global_daily_budget_usd' => 1.00, 'ai-routing.new_account_share' => 0.3, 'ai-routing.copilot.daily_budget_usd' => null]);
    CareerCopilot::fake();
    $user = User::factory()->onboarded()->create(['created_at' => now()]);
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.30]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertTooManyRequests()
        ->assertJson([
            'message' => 'AI is busy for new accounts today. It resets at midnight UTC.',
            'code' => 'ai_new_account_reserve_exceeded',
        ]);

    CareerCopilot::assertNeverPrompted();
});

test('an established account passes at the same spend', function () {
    config(['ai-routing.global_daily_budget_usd' => 1.00, 'ai-routing.new_account_share' => 0.3, 'ai-routing.copilot.daily_budget_usd' => null]);
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create(['created_at' => now()->subDays(10)]);
    Resume::factory()->for($user)->primary()->parsed()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.30]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('spend from before today does not count', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => null]);
    $this->travelTo('2026-03-10 12:00:00');
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create([
        'feature' => AiFeature::Copilot,
        'cost_usd' => 0.50,
        'created_at' => now()->startOfDay()->subSecond(),
    ]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('another user\'s spend does not count', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => null]);
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for(User::factory()->onboarded())->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('null daily budgets turn the checks off', function () {
    config(['ai-routing.copilot.daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 9_999.00]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('an exceeded budget is logged with the user id', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => null]);
    CareerCopilot::fake();
    Log::spy();
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Copilot, 'cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertTooManyRequests();

    Log::shouldHaveReceived('info')->with('ai.budget.exceeded', ['user_id' => $user->id])->once();
});

test('the copilot is paused for every user when the spend of all users reaches the global budget', function () {
    config(['ai-routing.copilot.daily_budget_usd' => 0.50, 'ai-routing.global_daily_budget_usd' => 1.00]);
    CareerCopilot::fake();
    AiUsage::factory()->for(User::factory()->onboarded())->create(['feature' => AiFeature::FitScoring, 'cost_usd' => 1.00]);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertTooManyRequests()
        ->assertJson([
            'message' => 'AI is paused for today. It resets at midnight UTC.',
            'code' => 'ai_global_budget_exceeded',
        ]);

    CareerCopilot::assertNeverPrompted();
});
