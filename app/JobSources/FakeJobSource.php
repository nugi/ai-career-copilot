<?php

namespace App\JobSources;

use App\JobSources\Contracts\JobSource;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A job source for tests and local runs.
 *
 * It returns postings that are written here, and it makes no network calls.
 */
final class FakeJobSource implements JobSource
{
    /**
     * @param  list<JobPostingData>|null  $postings  The postings to yield, or null to yield the built in samples.
     */
    public function __construct(private ?array $postings = null) {}

    public function key(): string
    {
        return 'fake';
    }

    public function fetch(): Generator
    {
        $postings = $this->postings ?? $this->samplePostings();

        foreach ($postings as $posting) {
            yield $posting;
        }

        return new FetchReport(
            pages: 1,
            yielded: count($postings),
            skipped: 0,
            droppedFields: 0,
        );
    }

    /**
     * Get the three postings that stand in for a real source.
     *
     * @return list<JobPostingData>
     */
    private function samplePostings(): array
    {
        return [
            new JobPostingData(
                source: 'fake',
                externalId: 'fake-1',
                title: 'Senior Laravel Engineer',
                companyName: 'Northwind Systems',
                applyUrl: 'https://example.com/jobs/fake-1',
                descriptionHtml: '<p>Own the billing API and its queue workers.</p>',
                postedAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
                expiresAt: CarbonImmutable::parse('2026-11-19 09:00:00'),
                location: 'Germany, Netherlands',
                remoteType: 'remote',
                employmentType: 'Contractor',
                seniority: 'Senior',
                salaryMin: 45.0,
                salaryMax: 60.0,
                salaryCurrency: 'EUR',
                salaryPeriod: 'hourly',
            ),
            new JobPostingData(
                source: 'fake',
                externalId: 'fake-2',
                title: 'Backend Engineer',
                companyName: 'Baltic Data',
                applyUrl: 'https://example.com/jobs/fake-2',
                descriptionHtml: '<p>Build the ingestion service for partner feeds.</p>',
                postedAt: CarbonImmutable::parse('2026-10-19 14:30:00'),
                expiresAt: CarbonImmutable::parse('2026-11-18 14:30:00'),
                remoteType: 'remote',
                employmentType: 'Full Time',
            ),
            new JobPostingData(
                source: 'fake',
                externalId: 'fake-3',
                title: 'Full Stack Developer',
                companyName: 'Helio Labs',
                applyUrl: 'https://example.com/jobs/fake-3',
                descriptionHtml: '<p>Ship features across the Laravel API and the React app.</p>',
                postedAt: CarbonImmutable::parse('2026-10-18 11:15:00'),
                expiresAt: CarbonImmutable::parse('2026-11-17 11:15:00'),
                location: 'Lisbon, Portugal',
                remoteType: 'remote',
                employmentType: 'Full Time',
                seniority: 'Mid-level',
                salaryMax: 60000.0,
                salaryCurrency: 'USD',
                salaryPeriod: 'annual',
                salaryText: 'Up to $60k',
            ),
        ];
    }
}
