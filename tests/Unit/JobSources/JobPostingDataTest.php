<?php

use App\JobSources\JobPostingData;
use Carbon\CarbonImmutable;

/**
 * Build a valid posting, with the given values replaced.
 */
$validPosting = function (
    string $source = 'fake',
    string $externalId = 'fake-1',
    string $title = 'Backend Engineer',
    string $companyName = 'Northwind Systems',
    string $applyUrl = 'https://example.com/jobs/fake-1',
    string $descriptionHtml = '<p>Build the billing API.</p>',
    ?CarbonImmutable $postedAt = null,
    ?CarbonImmutable $expiresAt = null,
    ?float $salaryMin = null,
    ?float $salaryMax = null,
    ?string $salaryCurrency = null,
): JobPostingData {
    $postedAt ??= CarbonImmutable::parse('2026-10-20 09:00:00');
    $expiresAt ??= $postedAt->addDays(30);

    return new JobPostingData(
        source: $source,
        externalId: $externalId,
        title: $title,
        companyName: $companyName,
        applyUrl: $applyUrl,
        descriptionHtml: $descriptionHtml,
        postedAt: $postedAt,
        expiresAt: $expiresAt,
        salaryMin: $salaryMin,
        salaryMax: $salaryMax,
        salaryCurrency: $salaryCurrency,
    );
};

test('a posting with only the required values is valid', function () {
    $posting = new JobPostingData(
        source: 'fake',
        externalId: 'fake-1',
        title: 'Backend Engineer',
        companyName: 'Northwind Systems',
        applyUrl: 'https://example.com/jobs/fake-1',
        descriptionHtml: '<p>Build the billing API.</p>',
        postedAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
        expiresAt: CarbonImmutable::parse('2026-11-19 09:00:00'),
    );

    expect($posting->location)->toBeNull()
        ->and($posting->remoteType)->toBeNull()
        ->and($posting->employmentType)->toBeNull()
        ->and($posting->seniority)->toBeNull()
        ->and($posting->salaryMin)->toBeNull()
        ->and($posting->salaryMax)->toBeNull()
        ->and($posting->salaryCurrency)->toBeNull()
        ->and($posting->salaryPeriod)->toBeNull()
        ->and($posting->salaryText)->toBeNull();
});

test('a posting with an invalid value is rejected', function (Closure $build) {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'an empty external ID' => fn () => $validPosting(externalId: ''),
    'a blank title' => fn () => $validPosting(title: '   '),
    'an apply URL that is not a URL' => fn () => $validPosting(applyUrl: 'example.com/jobs/1'),
    'an ftp apply URL' => fn () => $validPosting(applyUrl: 'ftp://example.com/jobs/1'),
    'an expiry date that is not after the posted date' => fn () => $validPosting(
        postedAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
        expiresAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
    ),
    'a negative minimum salary' => fn () => $validPosting(salaryMin: -1.0),
    'a minimum salary above the maximum' => fn () => $validPosting(salaryMin: 60.0, salaryMax: 45.0),
    'a lowercase currency' => fn () => $validPosting(salaryCurrency: 'usd'),
    'a two letter currency' => fn () => $validPosting(salaryCurrency: 'US'),
]);
