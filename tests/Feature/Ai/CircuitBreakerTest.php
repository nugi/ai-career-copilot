<?php

use App\Ai\CircuitBreaker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the circuit opens after three failovers and the next question goes straight to Anthropic', function () {
    Log::spy();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    foreach (range(1, 3) as $attempt) {
        advisor()->prompt('What should I learn next?');
    }

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'ai.circuit.opened'
        && $context['provider'] === 'openai');

    $response = advisor()->prompt('What should I learn next?');

    expect($response->meta->provider)->toBe('anthropic');
    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.openai.com')))->toHaveCount(3);
});

test('the primary is tried again after the cooldown and a success closes the circuit', function () {
    Log::spy();
    $openAiIsDown = true;
    Http::fake([
        'api.openai.com/*' => function () use (&$openAiIsDown) {
            return $openAiIsDown
                ? Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503)
                : Http::response(openAiAnswer('Build one API project.'));
        },
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    foreach (range(1, 3) as $attempt) {
        advisor()->prompt('What should I learn next?');
    }

    $this->travel(CircuitBreaker::COOLDOWN_SECONDS + 1)->seconds();
    $openAiIsDown = false;

    $response = advisor()->prompt('What should I learn next?');

    expect($response->text)->toBe('Build one API project.')
        ->and($response->meta->provider)->toBe('openai');
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'ai.circuit.closed'
        && $context['provider'] === 'openai');
});

test('one failure after the cooldown opens the circuit again at once', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    foreach (range(1, 3) as $attempt) {
        advisor()->prompt('What should I learn next?');
    }

    $this->travel(CircuitBreaker::COOLDOWN_SECONDS + 1)->seconds();

    advisor()->prompt('What should I learn next?');

    expect(app(CircuitBreaker::class)->isOpen('openai'))->toBeTrue();
});

test('a success resets the failure count', function () {
    $breaker = app(CircuitBreaker::class);

    $breaker->recordFailure('openai');
    $breaker->recordFailure('openai');
    $breaker->recordSuccess('openai');
    $breaker->recordFailure('openai');
    $breaker->recordFailure('openai');

    expect($breaker->isOpen('openai'))->toBeFalse();
});

test('an open provider moves to the end and is never removed', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $failure) {
        $breaker->recordFailure('openai');
        $breaker->recordFailure('anthropic');
    }

    expect($breaker->order(['openai' => 'a', 'anthropic' => 'b']))->toBe(['openai' => 'a', 'anthropic' => 'b']);
});

test('a single open provider moves to the end of the order', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $failure) {
        $breaker->recordFailure('openai');
    }

    expect($breaker->order(['openai' => 'a', 'anthropic' => 'b']))->toBe(['anthropic' => 'b', 'openai' => 'a']);
});
