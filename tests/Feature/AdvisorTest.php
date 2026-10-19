<?php

use App\Ai\Agents\CareerAdvisor;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $this->post(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertRedirect(route('login'));
});

test('users who have not finished onboarding get 409 without asking the agent', function (User $user) {
    CareerAdvisor::fake();

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertConflict()
        ->assertJson(['message' => 'Finish onboarding first.']);

    CareerAdvisor::assertNeverPrompted();
})->with([
    'no profile' => fn () => User::factory()->create(),
    'incomplete profile' => fn () => User::factory()->has(Profile::factory()->incomplete())->create(),
]);

test('a missing or over-long question is rejected with 422', function (string $question, string $message) {
    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('advisor.ask'), ['question' => $question])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question' => $message]);
})->with([
    'missing' => ['', 'The question field is required.'],
    'too long' => [str_repeat('a', 2001), 'The question field must not be greater than 2000 characters.'],
]);

test('an answer is returned as JSON with the prompt version', function () {
    CareerAdvisor::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->assertJsonStructure(['answer', 'provider', 'model', 'prompt_version'])
        ->assertJsonPath('answer', 'Start with one portfolio project.')
        ->assertJsonPath('prompt_version', CareerAdvisor::PROMPT_VERSION);

    CareerAdvisor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?')
        && $prompt->agent instanceof CareerAdvisor
        && $prompt->agent->user->is($user));
});

test('the advisor answers with 503 and reports the outage when every provider is unavailable', function () {
    Exceptions::fake();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded.']], 529),
    ]);

    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertServiceUnavailable()
        ->assertJson(['message' => 'The advisor is unavailable. Try again in a minute.']);

    Exceptions::assertReported(ProviderOverloadedException::class);
});

test('the advisor returns 429 to the 11th question in a minute without asking the agent', function () {
    CareerAdvisor::fake();
    $user = User::factory()->onboarded()->create();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)
            ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
            ->assertOk();
    }

    $this->actingAs($user)
        ->postJson(route('advisor.ask'), ['question' => 'What should I learn next?'])
        ->assertTooManyRequests();

    CareerAdvisor::assertPromptedTimes(10);
});
