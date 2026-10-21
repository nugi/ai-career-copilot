<?php

use App\Ai\ModelRouter;
use App\Enums\AiFeature;
use App\Enums\ModelTier;
use Laravel\Ai\Enums\Lab;

test('each task uses the models of its tier, in failover order', function () {
    $router = app(ModelRouter::class);

    expect($router->tier(AiFeature::Chat))->toBe(ModelTier::Strong)
        ->and($router->models(AiFeature::Chat))->toBe([
            'openai' => 'gpt-6.1-sol',
            'anthropic' => 'claude-sonnet-5-5',
        ])
        ->and($router->tier(AiFeature::ResumeParsing))->toBe(ModelTier::Cheap)
        ->and($router->models(AiFeature::ResumeParsing))->toBe([
            'openai' => 'gpt-6-luna',
            'anthropic' => 'claude-haiku-5-5',
        ]);
});

test('each model gets the generation options that it accepts', function () {
    $router = app(ModelRouter::class);

    expect($router->providerOptions(AiFeature::Chat, Lab::OpenAI))->toBe(['reasoning' => ['effort' => 'low']])
        ->and($router->providerOptions(AiFeature::Chat, Lab::Anthropic))->toBe(['thinking' => ['type' => 'between_tools']])
        ->and($router->providerOptions(AiFeature::ResumeParsing, Lab::OpenAI))->toBe(['reasoning' => ['effort' => 'none']])
        ->and($router->providerOptions(AiFeature::ResumeParsing, Lab::Anthropic))->toBe(['thinking' => ['type' => 'disabled']]);
});

test('a provider outside the tier has no options', function () {
    expect(app(ModelRouter::class)->providerOptions(AiFeature::Chat, 'gemini'))->toBe([])
        ->and(app(ModelRouter::class)->providerOptions(AiFeature::Chat, Lab::Gemini))->toBe([]);
});

test('the cost of a model is the price of its tokens in USD', function () {
    $router = app(ModelRouter::class);

    expect($router->cost('gpt-6.1-sol', 1_000_000, 1_000_000))->toBe('12.000000')
        ->and($router->cost('claude-haiku-5-5', 10, 5))->toBe('0.000004');
});

test('a model without a price has no cost', function () {
    expect(app(ModelRouter::class)->cost('unknown-model', 1_000, 1_000))->toBeNull();
});

test('a task without a tier throws and names the task', function () {
    expect(fn () => app(ModelRouter::class)->tier(AiFeature::Embedding))
        ->toThrow(InvalidArgumentException::class, 'embedding');
});
