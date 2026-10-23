<?php

namespace App\Ai;

use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Enums\AiUsageStatus;
use App\Models\AiUsage;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Throwable;

/**
 * Records one ai_usages row per provider attempt of an agent call.
 *
 * It also keeps the start time of each running invocation, so the row can carry the latency of the attempt. Writing a
 * row is best effort: a broken insert or an unknown tier never reaches the caller, because the answer is worth more
 * than its cost record.
 */
class AiUsageRecorder
{
    /**
     * The start times of the running attempts, in nanoseconds, keyed by invocation ID.
     *
     * @var array<string, int>
     */
    private array $starts = [];

    public function __construct(private ModelRouter $router) {}

    /**
     * Remember when an attempt of the invocation started.
     */
    public function start(string $invocationId): void
    {
        $this->starts[$invocationId] = (int) hrtime(true);
    }

    /**
     * Get the milliseconds since the start of the invocation, or null when no start was recorded.
     */
    public function latencyMs(string $invocationId): ?int
    {
        $start = $this->starts[$invocationId] ?? null;

        if ($start === null) {
            return null;
        }

        return intdiv(hrtime(true) - $start, 1_000_000);
    }

    /**
     * Write the usage row of one provider attempt.
     *
     * An agent that does not implement TracksUsage writes no row. A model without a price writes a zero cost and a
     * warning, so an unpriced call is visible instead of silently free. A failed attempt may carry unknown token counts, which are marked as estimated.
     */
    public function record(
        Agent $agent,
        string $invocationId,
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        AiUsageStatus $status,
        bool $tokensEstimated = false,
    ): void {
        if (! $agent instanceof TracksUsage) {
            return;
        }

        try {
            $task = $agent->task();
            $cost = $this->router->cost($model, $inputTokens, $outputTokens);

            if ($cost === null) {
                Log::warning('ai.usage.unpriced_model', ['model' => $model]);
            }

            AiUsage::create([
                'user_id' => $agent->usageUser()?->getKey(),
                'feature' => $task,
                'invocation_id' => $invocationId,
                'agent' => class_basename($agent),
                'tier' => $this->router->tier($task)->value,
                'prompt_version' => $agent instanceof HasPromptVersion ? $agent->promptVersion() : null,
                'provider' => $provider,
                'model' => $model,
                'status' => $status,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'tokens_estimated' => $tokensEstimated,
                'cost_usd' => $cost ?? '0.000000',
                'latency_ms' => $this->latencyMs($invocationId),
            ]);
        } catch (Throwable $exception) {
            Log::warning('ai.usage.record_failed', ['exception' => $exception::class]);

            return;
        }

        if ($status !== AiUsageStatus::FailedOver) {
            unset($this->starts[$invocationId]);
        }
    }
}
