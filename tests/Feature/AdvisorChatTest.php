<?php

use App\Ai\Agents\CareerAdvisor;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * The payloads of a server-sent event body, in order, without the "data: " prefix.
 *
 * @return Collection<int, string>
 */
function advisorStreamPayloads(string $body): Collection
{
    return collect(explode("\n\n", $body))
        ->filter(fn (string $block): bool => str_starts_with($block, 'data: '))
        ->map(fn (string $block): string => substr($block, strlen('data: ')))
        ->values();
}

/**
 * Join the text deltas of the payloads back into the answer that the user sees.
 *
 * @param  Collection<int, string>  $payloads
 */
function advisorStreamAnswer(Collection $payloads): string
{
    return $payloads
        ->map(fn (string $payload): mixed => json_decode($payload, true))
        ->filter(fn (mixed $event): bool => is_array($event) && ($event['type'] ?? null) === 'text_delta')
        ->map(fn (array $event): string => $event['delta'])
        ->implode('');
}

test('guests are redirected to the login page', function () {
    $this->get(route('advisor.chat'))->assertRedirect(route('login'));
});

test('an onboarded user sees the advisor chat page', function () {
    $this->actingAs(User::factory()->onboarded()->create())
        ->get(route('advisor.chat'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('advisor'));
});

test('an answer is streamed as server-sent events', function () {
    CareerAdvisor::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $payloads = advisorStreamPayloads($response->streamedContent());

    expect(advisorStreamAnswer($payloads))->toBe('Start with one portfolio project.')
        ->and($payloads->contains(fn (string $payload): bool => str_contains($payload, '"type":"text_delta"')))->toBeTrue()
        ->and($payloads->last())->toBe('[DONE]');

    CareerAdvisor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?')
        && $prompt->agent instanceof CareerAdvisor
        && $prompt->agent->user->is($user));
});

test('a missing question is rejected with 422 without streaming an answer', function () {
    CareerAdvisor::fake();

    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('advisor.stream'), ['question' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question']);

    CareerAdvisor::assertNeverPrompted();
});

test('users who have not finished onboarding get 409 without streaming an answer', function (User $user) {
    CareerAdvisor::fake();

    $this->actingAs($user)
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?'])
        ->assertConflict()
        ->assertJson(['message' => 'Finish onboarding first.']);

    CareerAdvisor::assertNeverPrompted();
})->with([
    'no profile' => fn () => User::factory()->create(),
    'incomplete profile' => fn () => User::factory()->has(Profile::factory()->incomplete())->create(),
]);

test('an outage on every provider is streamed as an error event', function () {
    Exceptions::fake();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded.']], 529),
    ]);

    $response = $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?']);

    $response->assertOk();

    $payloads = advisorStreamPayloads($response->streamedContent());

    expect($payloads->first())->toBe(json_encode([
        'type' => 'error',
        'message' => 'The advisor is unavailable. Try again in a minute.',
    ]))
        ->and($payloads->last())->toBe('[DONE]');

    Exceptions::assertReported(ProviderOverloadedException::class);
});

test('the advisor returns 429 to the 11th stream request in a minute without asking the agent', function () {
    CareerAdvisor::fake();
    $user = User::factory()->onboarded()->create();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)
            ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?'])
            ->assertOk()
            ->streamedContent();
    }

    $this->actingAs($user)
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?'])
        ->assertTooManyRequests();

    CareerAdvisor::assertPromptedTimes(10);
});
