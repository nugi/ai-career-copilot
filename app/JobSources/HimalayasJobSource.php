<?php

namespace App\JobSources;

use App\JobSources\Contracts\JobSource;
use Carbon\CarbonImmutable;
use Generator;

/**
 * The Himalayas remote job feed.
 *
 * The source reads the browse feed, which is newest first, and pages through it with a cursor. A run reads at most
 * job-sources.himalayas.max_pages pages, so it reads only the newest jobs of a feed that holds about 123,000. The feed
 * refreshes every 24 hours, and docs/job-sources.md holds the field map, the limits and the terms that come with using
 * the postings.
 */
final class HimalayasJobSource implements JobSource
{
    /**
     * The number of days that a posting stays open when the source sends no expiry date.
     */
    private const int FALLBACK_LIFETIME_DAYS = 30;

    public function key(): string
    {
        return 'himalayas';
    }

    public function fetch(): Generator
    {
        $maxPages = config()->integer('job-sources.himalayas.max_pages');
        $key = config('services.himalayas.key');

        $client = new SourceHttpClient(
            'himalayas',
            config()->string('job-sources.himalayas.base_url'),
            is_string($key) && trim($key) !== '' ? $key : null,
        );

        $builder = new PostingBuilder('himalayas');

        $pages = 0;
        $cursor = null;

        /** @var list<string> $seenCursors */
        $seenCursors = [];

        while ($pages < $maxPages) {
            $data = $client->getJson('/jobs/api', ['limit' => 20, 'cursor' => $cursor]);

            $jobs = $data['jobs'] ?? null;

            if (! is_array($jobs) || $jobs === []) {
                break;
            }

            $pages++;

            foreach ($jobs as $job) {
                $posting = $builder->build(is_array($job) ? $this->values($job) : []);

                if ($posting !== null) {
                    yield $posting;
                }
            }

            $nextCursor = $data['nextCursor'] ?? null;

            if (! is_string($nextCursor) || trim($nextCursor) === '' || in_array($nextCursor, $seenCursors, true)) {
                break;
            }

            $seenCursors[] = $nextCursor;
            $cursor = $nextCursor;
        }

        return $builder->report($pages);
    }

    /**
     * Map one job of the feed to the values that PostingBuilder takes.
     *
     * @param  array<array-key, mixed>  $job
     * @return array<string, mixed>
     */
    private function values(array $job): array
    {
        $postedAt = $this->timestamp($job['pubDate'] ?? null);
        $expiresAt = $this->timestamp($job['expiryDate'] ?? null);

        if ($expiresAt === null && $postedAt !== null) {
            $expiresAt = $postedAt->addDays(self::FALLBACK_LIFETIME_DAYS);
        }

        $salaryMin = $job['minSalary'] ?? null;
        $salaryMax = $job['maxSalary'] ?? null;

        return [
            'externalId' => $job['guid'] ?? null,
            'title' => $job['title'] ?? null,
            'companyName' => $job['companyName'] ?? null,
            'applyUrl' => $job['applicationLink'] ?? null,
            'descriptionHtml' => $job['description'] ?? null,
            'postedAt' => $postedAt,
            'expiresAt' => $expiresAt,
            'location' => $this->joinedList($job['locationRestrictions'] ?? null),
            'remoteType' => 'remote',
            'employmentType' => $job['employmentType'] ?? null,
            'seniority' => $this->seniority($job['seniority'] ?? null),
            'salaryMin' => $salaryMin,
            'salaryMax' => $salaryMax,
            'salaryCurrency' => $job['currency'] ?? null,
            // Himalayas sends a period even when it sends no salary at all.
            'salaryPeriod' => $salaryMin !== null || $salaryMax !== null ? $job['salaryPeriod'] ?? null : null,
            'salaryText' => null,
        ];
    }

    /**
     * Get the date that the source sent as Unix seconds, or null when the value is not one.
     */
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) ? CarbonImmutable::createFromTimestampUTC($value) : null;
    }

    /**
     * Get the seniority that the source sent, which is a list on some jobs and a string on others.
     */
    private function seniority(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return $this->joinedList($value);
    }

    /**
     * Get the string items of the list joined with a comma, or null when the list is missing or empty.
     */
    private function joinedList(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items === [] ? null : implode(', ', $items);
    }
}
