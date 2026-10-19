<?php

use App\Ai\CircuitBreaker;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

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

test('a failure of the last provider is counted', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
    ]);

    foreach (range(1, 3) as $attempt) {
        expect(fn () => advisor()->prompt('What should I learn next?'))->toThrow(ProviderOverloadedException::class);
    }

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com')))->toHaveCount(3)
        ->and(app(CircuitBreaker::class)->isOpen('anthropic'))->toBeTrue();
});

test('a rejected key on the last provider is not counted', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
    ]);

    foreach (range(1, 3) as $attempt) {
        expect(fn () => advisor()->prompt('What should I learn next?'))->toThrow(RequestException::class);
    }

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com')))->toHaveCount(3)
        ->and(app(CircuitBreaker::class)->isOpen('anthropic'))->toBeFalse();
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
