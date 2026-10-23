<?php

namespace App\JobSources;

/**
 * The counts and deadline stop status of one fetch() run, returned as the generator's return value.
 */
final readonly class FetchReport
{
    /**
     * Record the run counts and whether its deadline stopped it early.
     */
    public function __construct(
        public int $pages,
        public int $yielded,
        public int $skipped,
        public int $droppedFields,
        public bool $stoppedEarly = false,
    ) {}
}
