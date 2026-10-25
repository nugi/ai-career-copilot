<?php

namespace App\Actions\Jobs;

use App\JobSources\FetchReport;

/**
 * The fetch, normalization, storage and duplicate-link counts of one source import.
 */
final readonly class ImportReport
{
    public function __construct(
        public string $source,
        public FetchReport $fetch,
        public int $normalized,
        public int $skipped,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $linksChanged,
    ) {}
}
