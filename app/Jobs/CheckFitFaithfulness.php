<?php

namespace App\Jobs;

use App\Actions\Fit\FitExplanation;
use App\Ai\Agents\FaithfulnessJudge;
use App\Ai\DailyBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Ask a second model whether each claim of a sampled fit explanation is supported by the excerpts it cites, and log the
 * share of supported claims (ADR 0034).
 *
 * The job holds the claims and the excerpt texts, not IDs, so it judges exactly what the user was shown.
 *
 * @phpstan-import-type Reason from FitExplanation
 */
class CheckFitFaithfulness implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    /**
     * @param  list<Reason>  $reasons
     */
    public function __construct(public int $postingId, public string $promptVersion, public array $reasons) {}

    /**
     * Judge the claims and log the score. Batch work stops when the batch budget is spent, so this check does too.
     */
    public function handle(DailyBudget $budget): void
    {
        if ($budget->isBatchExceeded()) {
            Log::info('fit.faithfulness.skipped_budget', ['job_posting_id' => $this->postingId]);

            return;
        }

        if ($this->reasons === []) {
            return;
        }

        $judge = new FaithfulnessJudge;
        $claims = array_map(fn (array $reason): array => [
            'claim' => $reason['claim'],
            'excerpts' => array_map(fn (array $excerpt): string => $excerpt['content'], $reason['excerpts']),
        ], $this->reasons);

        /** @var StructuredAgentResponse $response */
        $response = $judge->prompt($judge->message($claims));
        $verdicts = [];

        foreach ($response->toArray()['claims'] ?? [] as $verdict) {
            if (is_array($verdict) && is_int($verdict['claim_id'] ?? null) && is_bool($verdict['supported'] ?? null)) {
                $verdicts[$verdict['claim_id']] ??= $verdict['supported'];
            }
        }

        $unsupported = [];

        foreach ($claims as $index => $claim) {
            if (($verdicts[$index + 1] ?? false) !== true) {
                $unsupported[] = $claim['claim'];
            }
        }

        $total = count($claims);

        Log::info('fit.faithfulness', [
            'job_posting_id' => $this->postingId,
            'prompt_version' => $this->promptVersion,
            'claims' => $total,
            'supported' => $total - count($unsupported),
            'score' => round(($total - count($unsupported)) / $total, 3),
            'unsupported' => $unsupported,
        ]);
    }
}
