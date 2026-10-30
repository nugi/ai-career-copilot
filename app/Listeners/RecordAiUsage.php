<?php

namespace App\Listeners;

use App\Ai\AiUsageRecorder;
use App\Enums\AiFeature;
use App\Enums\AiUsageStatus;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsFailed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class RecordAiUsage
{
    /**
     * Create the event listener.
     */
    public function __construct(private AiUsageRecorder $recorder) {}

    /**
     * Start the timer of an attempt before the first provider is called.
     */
    public function handlePrompting(PromptingAgent $event): void
    {
        $this->recorder->start($event->invocationId);
    }

    /**
     * Start the timer of a streamed attempt too, because the SDK dispatches StreamingAgent and listeners of the parent class do not get it.
     */
    public function handleStreaming(StreamingAgent $event): void
    {
        $this->handlePrompting($event);
    }

    /**
     * Record the attempt that answered, with the provider, the model and the tokens that it billed.
     */
    public function handlePrompted(AgentPrompted $event): void
    {
        $this->recorder->record(
            $event->prompt->agent,
            $event->invocationId,
            (string) $event->response->meta->provider,
            (string) $event->response->meta->model,
            $event->response->usage->inputTokens,
            $event->response->usage->outputTokens,
            AiUsageStatus::Succeeded,
        );
    }

    /**
     * Record a streamed answer too, because the SDK dispatches AgentStreamed and listeners of the parent class do not get it.
     */
    public function handleStreamed(AgentStreamed $event): void
    {
        $this->handlePrompted($event);
    }

    /**
     * Record the provider that failed and passed the prompt to the next one. An error response means it billed no tokens, while any other failure leaves the token count unknown.
     */
    public function handleFailedOver(AgentFailedOver $event): void
    {
        $this->recorder->record(
            $event->agent,
            $event->invocationId,
            $event->provider->name(),
            $event->model,
            0,
            0,
            AiUsageStatus::FailedOver,
            $this->tokensAreUnknown($event->exception),
        );
    }

    /**
     * Record the last provider tried, because the SDK does not dispatch AgentFailedOver for it.
     */
    public function handleFailed(AgentFailed $event): void
    {
        $this->recorder->record(
            $event->prompt->agent,
            $event->invocationId,
            $event->prompt->provider->name(),
            $event->prompt->model,
            0,
            0,
            AiUsageStatus::Failed,
            $this->tokensAreUnknown($event->exception),
        );
    }

    /**
     * Start the embedding attempt's timer so its usage row includes provider latency.
     */
    public function handleGeneratingEmbeddings(GeneratingEmbeddings $event): void
    {
        $this->recorder->start($event->invocationId);
    }

    /**
     * Record the embedding provider's billed input tokens so non-agent calls count toward spend.
     */
    public function handleEmbeddingsGenerated(EmbeddingsGenerated $event): void
    {
        $this->recorder->recordCall(
            AiFeature::Embedding,
            'Embeddings',
            null,
            null,
            null,
            $event->invocationId,
            $event->provider->name(),
            $event->model,
            $event->response->usage->inputTokens,
            0,
            AiUsageStatus::Succeeded,
        );
    }

    /**
     * Record a failed embedding attempt with estimated tokens when its billed usage is unknown.
     */
    public function handleEmbeddingsFailed(EmbeddingsFailed $event): void
    {
        $this->recorder->recordCall(
            AiFeature::Embedding,
            'Embeddings',
            null,
            null,
            null,
            $event->invocationId,
            $event->provider->name(),
            $event->model,
            0,
            0,
            AiUsageStatus::Failed,
            $this->tokensAreUnknown($event->exception),
        );
    }

    /**
     * Tell whether the token count of a failure is unknown. An error response means that the provider generated nothing, and any other failure (a dropped connection, a timeout, a broken stream) can come after the provider billed tokens.
     */
    private function tokensAreUnknown(Throwable $exception): bool
    {
        return ! ($exception instanceof ProviderOverloadedException
            || $exception instanceof RateLimitedException
            || $exception instanceof InsufficientCreditsException
            || $exception instanceof RequestException);
    }
}
