<?php

namespace App\Jobs;

use App\Actions\Jobs\ImportJobPostings;
use App\Enums\IngestionRunStatus;
use App\JobSources\Exceptions\JobSourceRateLimited;
use App\JobSources\Exceptions\JobSourceUnavailable;
use App\JobSources\JobSourceRegistry;
use App\Models\IngestionRun;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one source import in the background and record it in ingestion_runs, so a scheduled run never waits on a worker
 * request and its outcome is a row instead of a log search (ADR 0020).
 *
 * The job is unique per source for 3600 seconds, so a second dispatch while a run waits or retries is dropped; the lock
 * must last longer than all the attempts of one job. Because of that lock, a running row of the same source at the start
 * of a run comes from a worker that was killed, so the job marks it abandoned before it records its own run. A rate limit
 * releases the job after the wait that the source asked for and records the run as rate_limited; a release is not an
 * error, so the retry window, not an attempt count, limits how long a rate-limited run keeps trying. An unavailable
 * source fails the job at once, because the HTTP client has already retried and a job retry gets the same answer. Any
 * other error records a failed run and rethrows until maxExceptions unexpected errors have happened. A run that passes
 * the timeout fails at once, because another attempt would wait the same way and the next scheduled run tries again.
 * A failure outside handle() is closed by failed(), which marks the running row failed or, after a lost deadline, adds
 * the failed row that the dropped attempt never recorded. The error column holds the exception class and message only,
 * never a posting, a description or an API key.
 */
#[UniqueFor(3600)]
class IngestJobSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of unexpected errors before the job fails; a rate limit release is not an error.
     */
    public int $maxExceptions = 3;

    /**
     * The seconds that one attempt may run: the worst run is the run deadline plus one attempt, about 75 seconds (ADR 0016).
     * The queue connection's retry_after must be longer, or a second worker starts the same job while the first one runs.
     */
    public int $timeout = 120;

    /**
     * A run that passes the timeout is far past its worst case of about 75 seconds, so another attempt waits the same way.
     * The next scheduled run tries again.
     */
    public bool $failOnTimeout = true;

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
     * Rate limits release the job until this time. The window must end before the 3600-second unique lock expires,
     * with room for one more attempt.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(55);
    }

    /**
     * Import the source, record the run and close it with the counts, the failure or the wait that the source asked for.
     */
    public function handle(JobSourceRegistry $registry, ImportJobPostings $import): void
    {
        $startedAt = hrtime(true);

        IngestionRun::query()
            ->where('source', $this->source)
            ->where('status', IngestionRunStatus::Running)
            ->update([
                'status' => IngestionRunStatus::Abandoned,
                'finished_at' => now(),
            ]);

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
     * Close the run of an attempt that failed outside handle(), such as a timeout or a lost deadline.
     */
    public function failed(?Throwable $exception): void
    {
        $closed = IngestionRun::query()
            ->where('source', $this->source)
            ->where('status', IngestionRunStatus::Running)
            ->update([
                'status' => IngestionRunStatus::Failed,
                'finished_at' => now(),
                'error' => $exception === null ? null : self::errorFor($exception),
            ]);

        if ($closed === 0 && $exception instanceof MaxAttemptsExceededException) {
            IngestionRun::query()->create([
                'source' => $this->source,
                'status' => IngestionRunStatus::Failed,
                'started_at' => now(),
                'finished_at' => now(),
                'error' => self::errorFor($exception),
            ]);

            $closed = 1;
        }

        if ($closed === 0 || $exception === null) {
            return;
        }

        $this->logFailure($exception);
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
            'error' => self::errorFor($exception),
        ]);
    }

    /**
     * Format the error column value from the exception class and message, cut to the column's length.
     */
    private static function errorFor(Throwable $exception): string
    {
        return mb_substr($exception::class.': '.$exception->getMessage(), 0, 255);
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
