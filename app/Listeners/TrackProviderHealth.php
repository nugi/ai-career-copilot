<?php

namespace App\Listeners;

use App\Ai\CircuitBreaker;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Exceptions\FailoverableException;

class TrackProviderHealth
{
    /**
     * Create the event listener.
     */
    public function __construct(private CircuitBreaker $breaker) {}

    /**
     * Count a failure of the provider that the prompt failed over from.
     */
    public function handleFailedOver(AgentFailedOver $event): void
    {
        $this->breaker->recordFailure($event->provider->name());
    }

    /**
     * Count the failure of the last provider tried, because the SDK does not dispatch AgentFailedOver for it.
     *
     * Only outages count: the exception must be a FailoverableException.
     */
    public function handleFailed(AgentFailed $event): void
    {
        if (! $event->exception instanceof FailoverableException) {
            return;
        }

        $this->breaker->recordFailure($event->prompt->provider()->name());
    }

    /**
     * Close the circuit of the provider that answered the prompt.
     */
    public function handlePrompted(AgentPrompted $event): void
    {
        $this->breaker->recordSuccess($event->response->meta->provider);
    }
}
