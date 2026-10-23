<?php

namespace App\JobSources;

use App\JobSources\Contracts\JobSource;
use Carbon\CarbonImmutable;
use Generator;

/**
 * The Himalayas remote job feed.
 *
 * The source searches the feed for each target-role query, newest first, reading pages_per_query pages per query
 * under the run deadline. It yields each guid once per run. The feed refreshes every 24 hours, and
 * docs/job-sources.md holds the field map, the limits and the terms that come with using the postings.
 */
final class HimalayasJobSource implements JobSource
{
    /**
     * The number of days that a posting stays open when the source sends no expiry date.
     */
    private const int FALLBACK_LIFETIME_DAYS = 30;

    /**
     * Resolve the target-role queries for each run.
     */
    public function __construct(private TargetRoleQueries $queries) {}

    /**
     * Get the source key.
     */
    public function key(): string
    {
        return 'himalayas';
    }

    /**
     * Search each role under the page and time limits, yielding each external ID once.
     *
     * @return Generator<int, JobPostingData, mixed, FetchReport>
     */
    public function fetch(): Generator
    {
        $deadline = RunDeadline::fromConfig();
        $pagesPerQuery = config()->integer('job-sources.himalayas.pages_per_query');
        $key = config('services.himalayas.key');

        $client = new SourceHttpClient(
            'himalayas',
            config()->string('job-sources.himalayas.base_url'),
            is_string($key) && trim($key) !== '' ? $key : null,
            $deadline,
        );

        $builder = new PostingBuilder('himalayas');
        $pages = 0;
        $stoppedEarly = false;

        /** @var array<string, true> $seenGuids */
        $seenGuids = [];

        foreach ($this->queries->all() as $query) {
            for ($page = 1; $page <= $pagesPerQuery; $page++) {
                if ($deadline->hasPassed()) {
                    $stoppedEarly = true;

                    break 2;
                }

                $data = $client->getJson('/jobs/api/search', ['q' => $query, 'sort' => 'recent', 'page' => $page]);
                $jobs = $data['jobs'] ?? null;

                if (! is_array($jobs) || $jobs === []) {
                    break;
                }

                $pages++;

                foreach ($jobs as $job) {
                    $guid = is_array($job) ? ($job['guid'] ?? null) : null;

                    if (is_string($guid) || is_int($guid)) {
                        if (isset($seenGuids[$guid])) {
                            continue;
                        }

                        $seenGuids[$guid] = true;
                    }

                    $posting = $builder->build(is_array($job) ? $this->values($job) : []);

                    if ($posting !== null) {
                        yield $posting;
                    }
                }

                if (count($jobs) < 20) {
                    break;
                }
            }
        }

        return $builder->report($pages, $stoppedEarly);
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
