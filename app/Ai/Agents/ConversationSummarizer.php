<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Ai\ModelRouter;
use App\Ai\Prompts\Instructions;
use App\Enums\AiFeature;
use App\Models\User;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Fold the older messages of a copilot conversation into one plain-text summary, so the copilot reads a short history.
 *
 * The output is plain text and is not trusted: the copilot reads it as context, not as instructions.
 */
#[Timeout(60)]
class ConversationSummarizer implements Agent, HasPromptVersion, HasProviderOptions, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('conversation-summarizer', self::PROMPT_VERSION);
    }

    /**
     * Get the version of the prompt file that the instructions come from.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Get the task that the agent does, which decides its model tier and its usage feature.
     */
    public function task(): AiFeature
    {
        return AiFeature::ConversationSummary;
    }

    /**
     * Get the user whose conversation is summarized, so the call counts in that user's daily budget.
     */
    public function usageUser(): ?User
    {
        return $this->user;
    }

    /**
     * Get the providers in failover order, mapped to their models. A provider whose circuit is open moves to the end of the list.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return app(CircuitBreaker::class)->order(app(ModelRouter::class)->models($this->task()));
    }

    /**
     * Get the provider-specific generation options for the model that the task's tier uses.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return app(ModelRouter::class)->providerOptions($this->task(), $provider);
    }
}
