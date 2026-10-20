<?php

namespace App\Jobs;

use App\Actions\Resume\ParseResume;
use App\Models\Resume;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parse an uploaded resume in the background, so the upload request does not wait for the AI provider.
 *
 * Only a provider failure makes the job retry. Invalid output and a resume without text do not throw, so ParseResume records them and the job completes.
 */
#[UniqueFor(600)]
class ParseUploadedResume implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of attempts for a provider outage.
     */
    public int $tries = 3;

    /**
     * The seconds that one attempt may run: 2 parse attempts, each of which can wait 90 seconds on 2 providers, plus a margin.
     * The queue connection's retry_after must be longer, or a second worker starts the same job while the first one runs.
     */
    public int $timeout = 400;

    /**
     * Skip the job when the resume was deleted before it runs.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        #[WithoutRelations]
        public Resume $resume,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ParseResume $parseResume): void
    {
        $parseResume->handle($this->resume);
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
     * Get the key that makes the job unique: one queued parse per resume.
     */
    public function uniqueId(): string
    {
        return (string) $this->resume->getKey();
    }

    /**
     * Record the provider failure on the resume after all attempts fail.
     *
     * The exception message is not logged, because it can contain the request body.
     */
    public function failed(?Throwable $exception): void
    {
        $this->resume->update([
            'parse_failed_at' => now(),
            'parse_errors' => [['path' => '*', 'rule' => 'ProviderUnavailable']],
        ]);

        Log::error('ai.resume.parse_job_failed', [
            'resume_id' => $this->resume->getKey(),
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
