<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailedOver;

class LogAgentFailover
{
    /**
     * Log a provider that failed and passed the prompt to the next provider. The exception message is never logged, because it can contain the provider's response.
     */
    public function handle(AgentFailedOver $event): void
    {
        Log::warning('ai.agent.failed_over', [
            'agent' => $event->agent::class,
            'invocation_id' => $event->invocationId,
            'provider' => $event->provider->name(),
            'model' => $event->model,
            'exception' => $event->exception::class,
        ]);
    }
}
