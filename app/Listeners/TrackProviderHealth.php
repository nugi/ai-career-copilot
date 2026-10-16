<?php

namespace App\Listeners;

use App\Ai\CircuitBreaker;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;

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
     * Close the circuit of the provider that answered the prompt.
     */
    public function handlePrompted(AgentPrompted $event): void
    {
        $this->breaker->recordSuccess($event->response->meta->provider);
    }
}
