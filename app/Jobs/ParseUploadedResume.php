<?php

namespace App\Jobs;

use App\Actions\Resume\ParseResume;
use App\Ai\Agents\ResumeParser;
use App\Enums\ResumeParseStatus;
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
 * The job is unique per user and content hash, so the same text is parsed once, and a duplicate resume reuses the parse of the resume that ran first.
 * The unique lock lasts 1800 seconds: 3 attempts of up to 400 seconds plus 150 seconds of backoff take up to 1350 seconds,
 * so the lock must last longer than all the attempts of one job.
 */
#[UniqueFor(1800)]
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
     * Execute the job, reusing the parse of a duplicate resume when one has a usable parse.
     *
     * ShouldBeUnique drops a second dispatch with the same key, so the duplicates that wait behind this job are
     * copied from its result afterwards. Without that step they would stay pending for ever.
     */
    public function handle(ParseResume $parseResume): void
    {
        $duplicate = $this->resume->duplicates()->get()
            ->first(fn (Resume $resume): bool => $this->hasUsableParse($resume));

        if ($duplicate === null) {
            $parseResume->handle($this->resume);
        } else {
            $parseResume->copyParse($duplicate, $this->resume);
        }

        $this->resume->refresh();

        if (! $this->hasUsableParse($this->resume) && $this->resume->parseStatus() !== ResumeParseStatus::Failed) {
            return;
        }

        if ($this->hasUsableParse($this->resume)) {
            EmbedResumeChunks::dispatch($this->resume);
        }

        foreach ($this->resume->duplicates()->get() as $pending) {
            if ($pending->parseStatus() !== ResumeParseStatus::Pending) {
                continue;
            }

            if ($this->hasUsableParse($this->resume)) {
                $parseResume->copyParse($this->resume, $pending);
                EmbedResumeChunks::dispatch($pending);
            } else {
                $parseResume->copyFailure($this->resume, $pending);
            }
        }
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
     * Get the key that makes the job unique: one queued parse per user and resume text.
     *
     * The text is the key, so uploading the same file twice queues one parse and the second resume copies the result.
     * A resume without text has no hash, so it falls back to its own id.
     */
    public function uniqueId(): string
    {
        if ($this->resume->content_hash === null) {
            return (string) $this->resume->getKey();
        }

        return "{$this->resume->user_id}:{$this->resume->content_hash}";
    }

    /**
     * Record the provider failure on the resume, and on the duplicates that wait for its parse, after all attempts fail.
     *
     * The exception message is not logged, because it can contain the request body.
     */
    public function failed(?Throwable $exception): void
    {
        $failedAt = now();
        $errors = [['path' => '*', 'rule' => 'ProviderUnavailable']];

        $this->resume->update([
            'parse_failed_at' => $failedAt,
            'parse_errors' => $errors,
        ]);

        foreach ($this->resume->duplicates()->get() as $duplicate) {
            if ($duplicate->parseStatus() !== ResumeParseStatus::Pending) {
                continue;
            }

            $duplicate->update([
                'parse_failed_at' => $failedAt,
                'parse_errors' => $errors,
            ]);
        }

        Log::error('ai.resume.parse_job_failed', [
            'resume_id' => $this->resume->getKey(),
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }

    /**
     * Determine whether the resume holds a parse that can be copied: it succeeded with the current prompt version.
     */
    private function hasUsableParse(Resume $resume): bool
    {
        return $resume->parseStatus() === ResumeParseStatus::Parsed
            && $resume->prompt_version === ResumeParser::PROMPT_VERSION;
    }
}
