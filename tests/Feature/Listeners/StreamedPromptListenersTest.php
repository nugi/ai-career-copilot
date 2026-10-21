<?php

use App\Ai\CircuitBreaker;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A streamed Responses API body from OpenAI that answers with the given text.
 */
function openAiStreamedAnswer(string $text): string
{
    $events = [
        ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'gpt-6-luna']],
        ['type' => 'response.output_text.delta', 'delta' => $text],
        ['type' => 'response.output_text.done'],
        ['type' => 'response.completed', 'response' => [
            'id' => 'resp_123',
            'model' => 'gpt-6-luna',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]],
    ];

    return implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events));
}

test('a streamed answer is logged with the provider, the model and the token counts', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(openAiStreamedAnswer('Start with one portfolio project.'))]);

    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'ai.agent.prompted'
        && $context['provider'] === 'openai'
        && $context['model'] === 'gpt-6-luna'
        && $context['input_tokens'] === 10
        && $context['output_tokens'] === 5);
});

test('a streamed answer closes the circuit of the provider', function () {
    Log::spy();
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $attempt) {
        $breaker->recordFailure('openai');
    }

    $this->travel(CircuitBreaker::COOLDOWN_SECONDS + 1)->seconds();

    Http::fake(['api.openai.com/*' => Http::response(openAiStreamedAnswer('Build one API project.'))]);

    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('advisor.stream'), ['question' => 'What should I learn next?'])
        ->assertOk()
        ->streamedContent();

    expect($breaker->isOpen('openai'))->toBeFalse();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'ai.circuit.closed'
        && $context['provider'] === 'openai');
});
