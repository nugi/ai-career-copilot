<?php

namespace App\Jobs;

use App\Actions\Jobs\ImportJobPostings;
use App\Enums\IngestionRunStatus;
use App\JobSources\Exceptions\JobSourceRateLimited;
use App\JobSources\Exceptions\JobSourceUnavailable;
use App\JobSources\JobSourceRegistry;
use App\Models\IngestionRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one source import in the background and record it in ingestion_runs, so a scheduled run never waits on a worker
 * request and its outcome is a row instead of a log search (ADR 0020).
 *
 * The job is unique per source for 3600 seconds, so a second dispatch while a run waits or retries is dropped; the lock
 * must last longer than all the attempts of one job. A rate limit releases the job after the wait that the source asked
 * for and records the run as rate_limited. An unavailable source fails the job at once, because the HTTP client has
 * already retried and a job retry gets the same answer. Any other error records a failed run and rethrows, so the queue
 * retries it. The error column holds the exception class and message only, never a posting, a description or an API key.
 */
#[UniqueFor(3600)]
class IngestJobSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of attempts for an unexpected error.
     */
    public int $tries = 3;

    /**
     * The seconds that one attempt may run: the worst run is the run deadline plus one attempt, about 75 seconds (ADR 0016).
     * The queue connection's retry_after must be longer, or a second worker starts the same job while the first one runs.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $source) {}

    /**
     * Get the key that makes the job unique: one queued run per source.
     */
    public function uniqueId(): string
    {
        return $this->source;
    }

    /**
     * Import the source, record the run and close it with the counts, the failure or the wait that the source asked for.
     */
    public function handle(JobSourceRegistry $registry, ImportJobPostings $import): void
    {
        $startedAt = hrtime(true);

        $run = IngestionRun::query()->create([
            'source' => $this->source,
            'status' => IngestionRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $report = $import->handle($registry->get($this->source));
        } catch (JobSourceRateLimited $exception) {
            $this->recordFailure($run, IngestionRunStatus::RateLimited, $exception, $startedAt);

            $this->release($exception->retryAfterSeconds);

            return;
        } catch (JobSourceUnavailable $exception) {
            $this->recordFailure($run, IngestionRunStatus::Failed, $exception, $startedAt);
            $this->logFailure($exception);

            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            $this->recordFailure($run, IngestionRunStatus::Failed, $exception, $startedAt);
            $this->logFailure($exception);

            throw $exception;
        }

        $counts = [
            'pages' => $report->fetch->pages,
            'fetched' => $report->fetch->yielded,
            'skipped' => $report->fetch->skipped + $report->skipped,
            'dropped_fields' => $report->fetch->droppedFields,
            'created' => $report->created,
            'updated' => $report->updated,
            'unchanged' => $report->unchanged,
            'links_changed' => $report->linksChanged,
            'stopped_early' => $report->fetch->stoppedEarly,
        ];

        $run->update([
            'status' => IngestionRunStatus::Succeeded,
            'finished_at' => now(),
            'duration_ms' => self::millisecondsSince($startedAt),
            ...$counts,
        ]);

        Log::info('jobs.ingest.finished', [
            'source' => $this->source,
            ...$counts,
        ]);
    }

    /**
     * Close the run with the failure, keeping only the exception class and a message cut to the column's length.
     */
    private function recordFailure(IngestionRun $run, IngestionRunStatus $status, Throwable $exception, int $startedAt): void
    {
        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => self::millisecondsSince($startedAt),
            'error' => mb_substr($exception::class.': '.$exception->getMessage(), 0, 255),
        ]);
    }

    /**
     * Log the failed run without its message, which the run row already holds.
     */
    private function logFailure(Throwable $exception): void
    {
        Log::warning('jobs.ingest.failed', [
            'source' => $this->source,
            'exception' => $exception::class,
        ]);
    }

    /**
     * Convert a hrtime() start into the whole milliseconds that the run took.
     */
    private static function millisecondsSince(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
