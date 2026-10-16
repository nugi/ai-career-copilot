<?php

namespace App\Listeners;

use App\Ai\Contracts\HasPromptVersion;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;

class LogAgentPrompted
{
    /**
     * Log the metadata of a prompt. The prompt and the answer are never logged, because questions can contain personal data.
     */
    public function handle(AgentPrompted $event): void
    {
        $agent = $event->prompt->agent;

        Log::info('ai.agent.prompted', [
            'agent' => $agent::class,
            'invocation_id' => $event->invocationId,
            'provider' => $event->response->meta->provider,
            'model' => $event->response->meta->model,
            'prompt_version' => $agent instanceof HasPromptVersion ? $agent->promptVersion() : null,
            'input_tokens' => $event->response->usage->inputTokens,
            'output_tokens' => $event->response->usage->outputTokens,
        ]);
    }
}
