<?php

use App\Ai\Middleware\LimitTurn;
use App\Ai\ModelRouter;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * A generation step of the copilot, as the agent loop hands it to the middleware.
 */
function copilotStep(int $number, TextUsage $usage): PendingStep
{
    return new PendingStep(
        number: $number,
        isFinalStep: false,
        provider: 'openai',
        model: 'gpt-6-luna',
        instructions: 'Instructions.',
        messages: [new UserMessage('What should I learn next?')],
        tools: copilot()->tools(),
        schema: null,
        options: null,
        usage: $usage,
    );
}

/**
 * Run the middleware on the step, and return the step that it passes on to the model.
 */
function passedOn(LimitTurn $limit, PendingStep $step): PendingStep
{
    $passed = null;

    $limit->handle($step, function (PendingStep $next) use (&$passed): StepResult {
        $passed = $next;

        return StepResult::stop();
    });

    return $passed;
}

test('a step past the cost cap runs without tools', function () {
    $limit = new LimitTurn(app(ModelRouter::class), 0.005, 60);
    passedOn($limit, copilotStep(0, new TextUsage));

    $passed = passedOn($limit, copilotStep(1, new TextUsage(inputTokens: 100_000)));

    expect($passed->tools)->toBe([])
        ->and(collect($passed->messages)->last()->content)->toContain('A turn limit is reached.');
});

test('a step past the time limit runs without tools', function () {
    $limit = new LimitTurn(app(ModelRouter::class), 0.005, 0);
    passedOn($limit, copilotStep(0, new TextUsage));

    $passed = passedOn($limit, copilotStep(1, new TextUsage));

    expect($passed->tools)->toBe([])
        ->and(collect($passed->messages)->last()->content)->toContain('A turn limit is reached.');
});

test('a step under the limits is unchanged', function () {
    $limit = new LimitTurn(app(ModelRouter::class), 0.005, 60);
    $step = copilotStep(1, new TextUsage(inputTokens: 1_000, outputTokens: 100));
    passedOn($limit, copilotStep(0, new TextUsage));

    expect(passedOn($limit, $step))->toBe($step);
});
