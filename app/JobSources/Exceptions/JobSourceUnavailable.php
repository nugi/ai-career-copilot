<?php

namespace App\JobSources\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A job source could not be read: it was unreachable, answered with an error, or sent something that cannot be used.
 *
 * The source key and the HTTP status are kept so a caller can tell which source failed and whether trying again is
 * worth it.
 */
class JobSourceUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        string $reason,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($this->message($reason), 0, $previous);
    }

    /**
     * Build the message that names the source and the reason it failed.
     */
    protected function message(string $reason): string
    {
        return sprintf('Job source [%s] is unavailable: %s.', $this->source, $reason);
    }
}
