<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Ai\Middleware\AttachUserProfile;
use App\Ai\ModelRouter;
use App\Ai\Prompts\Instructions;
use App\Enums\AiFeature;
use App\Models\User;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(30)]
class CareerAdvisor implements Agent, HasMiddleware, HasPromptVersion, HasProviderOptions, TracksUsage
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
     * Get the task that the agent does, which decides its model tier and its usage feature.
     */
    public function task(): AiFeature
    {
        return AiFeature::Chat;
    }

    /**
     * Get the user whose request caused the call.
     */
    public function usageUser(): ?User
    {
        return $this->user;
    }

    /**
     * Get the providers in failover order, mapped to their models.
     *
     * The models come from config/ai-routing.php for the agent's task, so a prompt version cannot change model on an SDK update.
     * A provider whose circuit is open moves to the end of the list.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return app(CircuitBreaker::class)->order(app(ModelRouter::class)->models($this->task()));
    }

    /**
     * Get the provider-specific generation options.
     *
     * They come from config/ai-routing.php for the model that the task's tier uses, because each model accepts its own options.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return app(ModelRouter::class)->providerOptions($this->task(), $provider);
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
