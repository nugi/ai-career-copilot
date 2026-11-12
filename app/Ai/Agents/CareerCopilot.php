<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Ai\ModelRouter;
use App\Ai\Prompts\Instructions;
use App\Ai\Tools\GetUserProfile;
use App\Ai\Tools\SearchJobs;
use App\Enums\AiFeature;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(5)]
#[Timeout(30)]
class CareerCopilot implements Agent, HasPromptVersion, HasProviderOptions, HasTools, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     *
     * They are the same for every user. Tools provide the user's profile and job facts.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('career-copilot', self::PROMPT_VERSION);
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
     * Get the tools scoped to the signed-in user.
     *
     * @return list<GetUserProfile|SearchJobs>
     */
    public function tools(): array
    {
        return [
            app()->make(GetUserProfile::class, ['user' => $this->user]),
            app()->make(SearchJobs::class, ['user' => $this->user]),
        ];
    }
}
