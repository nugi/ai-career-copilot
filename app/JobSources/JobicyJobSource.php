<?php

namespace App\JobSources;

use App\JobSources\Contracts\JobSource;
use Carbon\CarbonImmutable;
use Generator;
use Throwable;

/**
 * The Jobicy remote job feed.
 *
 * The source searches the feed for each target-role query, reading pages_per_query pages per query under the run
 * deadline by passing each page's nextCursor back as cursor while hasMore is true. It yields each id once per run.
 * The feed lists jobs from the last 7 days, so a posting expires 7 days after it was posted, and
 * docs/job-sources.md holds the field map, the limits and the terms that come with using the postings.
 */
final class JobicyJobSource implements JobSource
{
    /**
     * The number of days that a posting stays open. The feed lists jobs from the last 7 days.
     */
    private const int LIFETIME_DAYS = 7;

    /**
     * Resolve the target-role queries for each run.
     */
    public function __construct(private TargetRoleQueries $queries) {}

    /**
     * Get the source key.
     */
    public function key(): string
    {
        return 'jobicy';
    }

    /**
     * Search each role under the page and time limits, yielding each external ID once.
     *
     * @return Generator<int, JobPostingData, mixed, FetchReport>
     */
    public function fetch(): Generator
    {
        $deadline = RunDeadline::fromConfig();
        $pagesPerQuery = config()->integer('job-sources.jobicy.pages_per_query');

        $client = new SourceHttpClient(
            'jobicy',
            config()->string('job-sources.jobicy.base_url'),
            null,
            $deadline,
        );

        $builder = new PostingBuilder('jobicy');
        $pages = 0;
        $stoppedEarly = false;

        /** @var array<int|string, true> $seenIds */
        $seenIds = [];

        foreach ($this->queries->all() as $query) {
            $cursor = null;

            /** @var array<string, true> $usedCursors */
            $usedCursors = [];

            for ($page = 0; $page < $pagesPerQuery; $page++) {
                if ($deadline->hasPassed()) {
                    $stoppedEarly = true;

                    break 2;
                }

                $data = $client->getJson('/api/jobs', ['count' => 50, 'tag' => $query, 'cursor' => $cursor]);

                if (is_string($cursor)) {
                    $usedCursors[$cursor] = true;
                }

                $jobs = $data['jobs'] ?? null;

                if (! is_array($jobs) || $jobs === []) {
                    break;
                }

                $pages++;

                foreach ($jobs as $job) {
                    $id = is_array($job) ? ($job['id'] ?? null) : null;

                    if (is_string($id) || is_int($id)) {
                        if (isset($seenIds[$id])) {
                            continue;
                        }

                        $seenIds[$id] = true;
                    }

                    $posting = $builder->build(is_array($job) ? $this->values($job) : []);

                    if ($posting !== null) {
                        yield $posting;
                    }
                }

                $nextCursor = $data['nextCursor'] ?? null;

                if (($data['hasMore'] ?? null) === true && is_string($nextCursor) && $nextCursor !== '' && ! isset($usedCursors[$nextCursor])) {
                    $cursor = $nextCursor;
                } else {
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
        $postedAt = $this->postedAt($job['pubDate'] ?? null);

        $salaryMin = $job['salaryMin'] ?? null;
        $salaryMax = $job['salaryMax'] ?? null;

        return [
            'externalId' => $job['id'] ?? null,
            'title' => $job['jobTitle'] ?? null,
            'companyName' => $job['companyName'] ?? null,
            'applyUrl' => $job['url'] ?? null,
            'descriptionHtml' => $job['jobDescription'] ?? null,
            'postedAt' => $postedAt,
            'expiresAt' => $postedAt !== null ? $postedAt->addDays(self::LIFETIME_DAYS) : null,
            'location' => $this->location($job['jobGeo'] ?? null),
            'remoteType' => 'remote',
            'employmentType' => $this->joinedList($job['jobType'] ?? null),
            'seniority' => $job['jobLevel'] ?? null,
            'salaryMin' => $salaryMin,
            'salaryMax' => $salaryMax,
            'salaryCurrency' => $job['salaryCurrency'] ?? null,
            // Jobicy sends a period even when it sends no salary at all.
            'salaryPeriod' => $salaryMin !== null || $salaryMax !== null ? $job['salaryPeriod'] ?? null : null,
            'salaryText' => null,
        ];
    }

    /**
     * Get the date that the source sent as an ISO 8601 string, in UTC, or null when the value is not one.
     */
    private function postedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the region with each run of spaces collapsed to one space, or null when it is missing or blank.
     */
    private function location(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $collapsed = trim(preg_replace('/ +/', ' ', $value) ?? '');

        return $collapsed === '' ? null : $collapsed;
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
