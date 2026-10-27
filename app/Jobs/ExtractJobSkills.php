<?php

namespace App\Jobs;

use App\Actions\Jobs\ExtractPostingSkills;
use App\Ai\DailyBudget;
use App\Models\JobPosting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extract the skills of one posting in the background, so an ingestion run does not wait for the AI provider.
 *
 * Only a provider failure makes the job retry. Invalid output does not throw, so ExtractPostingSkills records it and
 * the job completes. The job is unique per text hash, so postings that post the same text queue one job and the
 * others copy its result.
 */
#[UniqueFor(1200)]
class ExtractJobSkills implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of attempts for a provider outage.
     */
    public int $tries = 3;

    /**
     * The seconds that one attempt may run: 2 attempts, each of which can wait 60 seconds on 2 providers, plus a margin.
     * The batch connection's retry_after (420) must be longer, or a second worker starts the same job while the first one runs.
     */
    public int $timeout = 300;

    /**
     * Skip the job when the posting was deleted before it runs.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        #[WithoutRelations]
        public JobPosting $posting,
    ) {}

    /**
     * Extract the skills of the posting, unless the batch or global daily budget is spent.
     *
     * The posting then stays pending, and the next run queues it again.
     */
    public function handle(ExtractPostingSkills $extract, DailyBudget $budget): void
    {
        if ($budget->isBatchExceeded()) {
            Log::info('jobs.skills.skipped_budget', [
                'job_posting_id' => $this->posting->getKey(),
            ]);

            return;
        }

        $extract->handle($this->posting);
    }

    /**
     * Get the seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Get the key that makes the job unique: one queued extraction per posting text.
     */
    public function uniqueId(): string
    {
        return (string) $this->posting->text_hash;
    }

    /**
     * Log the provider failure after all attempts fail.
     *
     * The exception message is not logged, because it can contain the request body.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('jobs.skills.job_failed', [
            'job_posting_id' => $this->posting->getKey(),
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
