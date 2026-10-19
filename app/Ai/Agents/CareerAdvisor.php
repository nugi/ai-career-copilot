<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Middleware\AttachUserProfile;
use App\Ai\Prompts\Instructions;
use App\Models\User;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(30)]
class CareerAdvisor implements Agent, HasMiddleware, HasPromptVersion, HasProviderOptions
{
    use Promptable;

    public const PROMPT_VERSION = 'v2';

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     *
     * They are the same for every user. The user's profile goes in their message instead.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('career-advisor', self::PROMPT_VERSION);
    }

    /**
     * Get the version of the prompt file that the instructions come from.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Get the providers in failover order, mapped to their models.
     *
     * The models are pinned, so an SDK update cannot change the model that a prompt version runs on.
     * A provider whose circuit is open moves to the end of the list.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return app(CircuitBreaker::class)->order([
            Lab::OpenAI->value => 'gpt-6-luna',
            Lab::Anthropic->value => 'claude-haiku-5-5',
        ]);
    }

    /**
     * Get the provider-specific generation options.
     *
     * Career advice does not need extended reasoning, and both models bill it as output tokens.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenAI => ['reasoning' => ['effort' => 'none']],
            Lab::Anthropic => ['thinking' => ['type' => 'disabled']],
            default => [],
        };
    }

    /**
     * Get the middleware wrapping each generation step of the agent.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new AttachUserProfile($this->user)];
    }
}
