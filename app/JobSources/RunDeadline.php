<?php

namespace App\JobSources;

use Carbon\CarbonImmutable;

/**
 * Bounds the time available to one source run and its retries.
 */
final readonly class RunDeadline
{
    /**
     * Set the instant at which the run must stop starting requests.
     */
    public function __construct(public CarbonImmutable $endsAt) {}

    /**
     * Start a deadline using the configured run duration.
     */
    public static function fromConfig(): self
    {
        return new self(CarbonImmutable::now()->addSeconds(config()->integer('job-sources.max_run_seconds')));
    }

    /**
     * Determine whether the run has reached its deadline.
     */
    public function hasPassed(): bool
    {
        return CarbonImmutable::now()->greaterThanOrEqualTo($this->endsAt);
    }

    /**
     * Determine whether a wait and attempt can finish before the deadline.
     */
    public function allows(float $seconds): bool
    {
        return CarbonImmutable::now()->addSeconds($seconds)->lessThan($this->endsAt);
    }
}
