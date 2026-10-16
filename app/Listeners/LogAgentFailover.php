<?php

namespace App\Listeners;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;

class LogAgentFailover
{
    /**
     * Log a provider that failed and passed the prompt to the next provider. The exception message is never logged, because it can contain the provider's response.
     *
     * Exhausted credits are our own billing fault, so they are logged at error level for alerts. Other failovers are logged at warning level.
     */
    public function handle(AgentFailedOver $event): void
    {
        $reason = $this->isOutOfCredits($event->exception) ? 'out_of_credits' : 'unavailable';

        $context = [
            'agent' => $event->agent::class,
            'invocation_id' => $event->invocationId,
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'exception' => $event->exception::class,
            'reason' => $reason,
        ];

        if ($reason === 'out_of_credits') {
            Log::error('ai.agent.failed_over', $context);

            return;
        }

        Log::warning('ai.agent.failed_over', $context);
    }

    /**
     * Determine whether the provider failed because its credits or quota are used up.
     *
     * Anthropic reports this as an insufficient credits exception. OpenAI reports it as a 429 rate limit with the code insufficient_quota.
     */
    private function isOutOfCredits(FailoverableException $exception): bool
    {
        if ($exception instanceof InsufficientCreditsException) {
            return true;
        }

        if (! $exception instanceof RateLimitedException) {
            return false;
        }

        $previous = $exception->getPrevious();

        if (! $previous instanceof RequestException || $previous->response === null) {
            return false;
        }

        return in_array('insufficient_quota', [
            $previous->response->json('error.code'),
            $previous->response->json('error.type'),
        ], true);
    }
}
