<?php

namespace App\JobSources\Contracts;

use App\JobSources\FetchReport;
use App\JobSources\JobPostingData;
use Generator;

/**
 * A job board API or feed that posts can be fetched from.
 *
 * fetch() yields postings with the values that the source sends. A later step normalizes those values, so a source
 * never needs to know the app's enums or its salary rules.
 */
interface JobSource
{
    /**
     * Get the key that identifies the source. It is stored in job_postings.source, for example "himalayas".
     */
    public function key(): string;

    /**
     * Fetch the source's current postings, page by page.
     *
     * A source maps the values of each posting and builds it with PostingBuilder. A posting that cannot be used is
     * skipped and logged, so one bad posting never ends the run. The generator returns a FetchReport for the run
     * summary.
     *
     * Every posting carries an expiresAt that the source sets. Ingestion expires a posting by that date only, never
     * because a run did not see the posting.
     *
     * @return Generator<int, JobPostingData, mixed, FetchReport>
     */
    public function fetch(): Generator;
}
