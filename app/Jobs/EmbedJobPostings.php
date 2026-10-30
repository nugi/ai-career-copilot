<?php

namespace App\Jobs;

use App\Actions\Jobs\EmbedPostings;
use App\Ai\DailyBudget;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Embed one batch of postings in the background.
 * A rate-limit release does not consume the exception allowance: the job retries until retryUntil() and fails after maxExceptions provider errors.
 */
#[UniqueFor(1200)]
class EmbedJobPostings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    /**
     * Keep only posting IDs so the worker reads their current text and skills.
     *
     * @param  list<int>  $postingIds
     */
    public function __construct(public array $postingIds) {}

    /**
     * Recheck the budget at execution time because queued batches may outlive the available allowance.
     */
    public function handle(EmbedPostings $embed, DailyBudget $budget): void
    {
        if ($budget->isBatchExceeded()) {
            Log::info('jobs.embeddings.skipped_budget', ['count' => count($this->postingIds)]);

            return;
        }

        $embed->handle($this->postingIds);
    }

    /**
     * Treat reordered copies of the same batch as one job to avoid duplicate provider calls.
     */
    public function uniqueId(): string
    {
        $ids = $this->postingIds;
        sort($ids, SORT_NUMERIC);

        return sha1(implode(',', $ids));
    }

    /**
     * Share the provider rate limit across workers so concurrent batches do not exceed it.
     *
     * @return list<RateLimited>
     */
    public function middleware(): array
    {
        return [new RateLimited('embeddings')];
    }

    /**
     * Bound retries by time so rate-limit releases do not exhaust a fixed attempt count.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Give the provider time to recover before retrying a failed batch.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Log only the exception class, since its message can contain request text.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('jobs.embeddings.job_failed', [
            'count' => count($this->postingIds),
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
