<?php

namespace App\Jobs;

use App\Actions\Resume\EmbedResumeChunks as EmbedChunks;
use App\Models\Resume;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Embed a resume in the background, sharing the provider rate limit with posting embeddings.
 */
#[UniqueFor(1200)]
class EmbedResumeChunks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    /**
     * Keep only the resume key so the worker reads its current chunks.
     */
    public function __construct(
        #[WithoutRelations]
        public Resume $resume,
    ) {}

    /**
     * Embed the current chunks of the resume through the shared action.
     */
    public function handle(EmbedChunks $embed): void
    {
        $embed->handle($this->resume);
    }

    /**
     * Queue at most one embedding job for each resume.
     */
    public function uniqueId(): string
    {
        return (string) $this->resume->getKey();
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
     * Give the provider time to recover before retrying a failed resume.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Log only the exception class, since its message can contain resume text.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('resumes.embeddings.job_failed', [
            'resume_id' => $this->resume->getKey(),
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
