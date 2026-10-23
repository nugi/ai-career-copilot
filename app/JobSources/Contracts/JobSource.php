<?php

namespace App\JobSources\Contracts;

use App\JobSources\JobPostingData;

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
     * @return iterable<int, JobPostingData>
     */
    public function fetch(): iterable;
}
