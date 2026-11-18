<?php

namespace App\Ai\Middleware;

use App\Ai\ModelRouter;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;

/**
 * Stop the copilot's tool calls when one turn costs too much or runs too long.
 *
 * The cost and the time are checked before each step after the first. A step past a limit runs without tools, and it
 * gets a message that asks the model to answer now. The model then writes its answer from the results it already has.
 * One instance serves one turn, because it keeps the time when the turn started.
 */
class LimitTurn
{
    private ?int $startedAt = null;

    /**
     * Create a limit for one turn.
     */
    public function __construct(
        private ModelRouter $models,
        private float $maxCostUsd,
        private int $maxSeconds,
    ) {}

    /**
     * Handle the pending generation step.
     *
     * @param  Closure(PendingStep): StepResult  $next
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        if ($step->isFirstStep()) {
            $this->startedAt = hrtime(true);

            return $next($step);
        }

        $cost = (float) $this->models->cost($step->model, $step->usage->inputTokens, $step->usage->outputTokens);
        $seconds = (hrtime(true) - ($this->startedAt ?? hrtime(true))) / 1_000_000_000;

        $limit = match (true) {
            $cost >= $this->maxCostUsd => 'cost',
            $seconds >= $this->maxSeconds => 'time',
            default => null,
        };

        if ($limit === null) {
            return $next($step);
        }

        Log::info('copilot.turn_limit', ['limit' => $limit, 'step' => $step->number]);

        return $next($step->withTools([])->withMessages([
            ...$step->messages,
            new UserMessage('A turn limit is reached. Answer now with the results you have, and say that you stopped early.'),
        ]));
    }
}
