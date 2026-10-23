<?php

namespace App\JobSources\Exceptions;

use Throwable;

/**
 * A job source refused the request with a wait that is too long for one run, so the run should be tried again later.
 *
 * retryAfterSeconds holds the wait that the source asked for, so a queued run can release itself instead of holding a
 * worker.
 */
class JobSourceRateLimited extends JobSourceUnavailable
{
    public function __construct(
        string $source,
        public readonly int $retryAfterSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($source, 'rate limited', 429, $previous);
    }

    /**
     * Build the message that names the source and the wait that it asked for.
     */
    protected function message(string $reason): string
    {
        return sprintf(
            'Job source [%s] is rate limited; retry after %d seconds.',
            $this->source,
            $this->retryAfterSeconds,
        );
    }
}
