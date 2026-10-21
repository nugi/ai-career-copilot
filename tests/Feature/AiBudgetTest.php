<?php

use App\Ai\Agents\CareerAdvisor;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;

test('a user under the daily budget gets an answer', function () {
    config(['ai-routing.daily_budget_usd' => 0.50]);
    CareerAdvisor::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['cost_usd' => 0.20]);

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->assertJsonPath('answer', 'Start with one portfolio project.');

    CareerAdvisor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?'));
});

test('a user whose daily spend equals the budget gets 429 from advisor.ask without asking the agent', function () {
    config(['ai-routing.daily_budget_usd' => 0.50]);
    CareerAdvisor::fake();
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertTooManyRequests()
        ->assertJson([
            'message' => 'You have used today\'s AI budget. It resets at midnight UTC.',
            'code' => 'ai_budget_exceeded',
        ]);

    CareerAdvisor::assertNeverPrompted();
});

test('a user whose daily spend equals the budget gets 429 from advisor.stream without asking the agent', function () {
    config(['ai-routing.daily_budget_usd' => 0.50]);
    CareerAdvisor::fake();
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?'])
        ->assertTooManyRequests()
        ->assertJson([
            'message' => 'You have used today\'s AI budget. It resets at midnight UTC.',
            'code' => 'ai_budget_exceeded',
        ]);

    CareerAdvisor::assertNeverPrompted();
});

test('spend from before today does not count', function () {
    config(['ai-routing.daily_budget_usd' => 0.50]);
    $this->travelTo('2026-03-10 12:00:00');
    CareerAdvisor::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create([
        'cost_usd' => 0.50,
        'created_at' => now()->startOfDay()->subSecond(),
    ]);

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->assertJsonPath('answer', 'Start with one portfolio project.');
});

test('another user\'s spend does not count', function () {
    config(['ai-routing.daily_budget_usd' => 0.50]);
    CareerAdvisor::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for(User::factory()->onboarded())->create(['cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->assertJsonPath('answer', 'Start with one portfolio project.');
});

test('a null daily budget turns the check off', function () {
    config(['ai-routing.daily_budget_usd' => null]);
    CareerAdvisor::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['cost_usd' => 9_999.00]);

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->assertJsonPath('answer', 'Start with one portfolio project.');
});

test('an exceeded budget is logged with the user id', function () {
    config(['ai-routing.daily_budget_usd' => 0.50]);
    CareerAdvisor::fake();
    Log::spy();
    $user = User::factory()->onboarded()->create();
    AiUsage::factory()->for($user)->create(['cost_usd' => 0.50]);

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertTooManyRequests();

    Log::shouldHaveReceived('info')->with('ai.budget.exceeded', ['user_id' => $user->id])->once();
});
