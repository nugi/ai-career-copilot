<?php

namespace App\JobSources;

/**
 * The counts of one fetch() run, returned as the generator's return value.
 */
final readonly class FetchReport
{
    public function __construct(
        public int $pages,
        public int $yielded,
        public int $skipped,
        public int $droppedFields,
    ) {}
}
