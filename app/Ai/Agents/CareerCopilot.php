<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Ai\Middleware\LimitTurn;
use App\Ai\ModelRouter;
use App\Ai\Prompts\Instructions;
use App\Ai\Tools\GetUserProfile;
use App\Ai\Tools\ScoreFit;
use App\Ai\Tools\SearchJobs;
use App\Ai\Tools\SkillGaps;
use App\Enums\AiFeature;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(5)]
#[Timeout(30)]
class CareerCopilot implements Agent, Conversational, HasMiddleware, HasPromptVersion, HasProviderOptions, HasTools, TracksUsage
{
    use Promptable;
    use RemembersConversations {
        messages as private rememberedMessages;
    }

    public const PROMPT_VERSION = 'v2';

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
        return AiFeature::Copilot;
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
     * A new LimitTurn serves one turn only, because it keeps the start time of the turn.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new LimitTurn(
            app(ModelRouter::class),
            (float) config('ai-routing.copilot.turn_cost_usd'),
            (int) config('ai-routing.copilot.turn_seconds'),
        )];
    }

    /**
     * Get the tools scoped to the signed-in user.
     *
     * @return list<GetUserProfile|SearchJobs|ScoreFit|SkillGaps>
     */
    public function tools(): array
    {
        return [
            app()->make(GetUserProfile::class, ['user' => $this->user]),
            app()->make(SearchJobs::class, ['user' => $this->user]),
            app()->make(ScoreFit::class, ['user' => $this->user]),
            app()->make(SkillGaps::class, ['user' => $this->user]),
        ];
    }

    /**
     * Get the messages the copilot reads.
     *
     * When the conversation has a summary, the messages are the summary, then the stored messages newer than summary_through. The
     * newer messages come from the store as the SDK builds them, so each tool call keeps its result. Without a summary, the
     * messages are the SDK's own.
     *
     * @return iterable<int, Message>
     */
    public function messages(): iterable
    {
        $conversation = $this->summarizedConversation();

        if ($conversation === null) {
            return $this->rememberedMessages();
        }

        $conversationId = $conversation->getKey();
        $newerCount = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('id', '>', $conversation->getAttribute('summary_through'))
            ->count();

        $summaryMessage = new Message('user', "Summary of the earlier conversation:\n".$conversation->getAttribute('summary'));

        if ($newerCount === 0) {
            return [$summaryMessage];
        }

        $newerMessages = resolve(ConversationStore::class)
            ->getLatestConversationMessages($conversationId, min($newerCount, $this->maxConversationMessages()))
            ->all();

        return [$summaryMessage, ...$newerMessages];
    }

    /**
     * Get the current conversation with its summary, or null when the copilot reads every message.
     *
     * The summary is read only when summaries are on, and when the summary and its last message are both stored.
     */
    private function summarizedConversation(): ?Conversation
    {
        $conversationId = $this->currentConversation();

        if ($conversationId === null || ! config('ai-routing.copilot.summarize')) {
            return null;
        }

        $conversation = Conversation::query()->find($conversationId, ['id', 'summary', 'summary_through']);

        if ($conversation === null || $conversation->getAttribute('summary') === null || $conversation->getAttribute('summary_through') === null) {
            return null;
        }

        return $conversation;
    }
}
