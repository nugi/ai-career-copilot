<?php

use App\Enums\EmploymentType;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\JobSources\JobPostingData;
use App\JobSources\Normalization\PostingNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Build source data directly, with only the values relevant to each example changed.
 */
$posting = function (
    string $source = 'himalayas',
    string $title = 'Backend Engineer',
    string $companyName = 'Northwind Systems',
    string $descriptionHtml = '<p>Build the billing API.</p>',
    ?string $location = null,
    ?string $remoteType = 'remote',
    ?string $seniority = null,
    ?float $salaryMin = null,
    ?float $salaryMax = null,
    ?string $salaryCurrency = null,
    ?string $salaryPeriod = null,
    ?string $salaryText = null,
): JobPostingData {
    return new JobPostingData(
        source: $source,
        externalId: 'posting-1',
        title: $title,
        companyName: $companyName,
        applyUrl: 'https://example.com/jobs/posting-1',
        descriptionHtml: $descriptionHtml,
        postedAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
        expiresAt: CarbonImmutable::parse('2026-11-19 09:00:00'),
        location: $location,
        remoteType: $remoteType,
        employmentType: 'Full Time',
        seniority: $seniority,
        salaryMin: $salaryMin,
        salaryMax: $salaryMax,
        salaryCurrency: $salaryCurrency,
        salaryPeriod: $salaryPeriod,
        salaryText: $salaryText,
    );
};

test('a posting without a salary has no salary fields', function () use ($posting) {
    $source = $posting(salaryCurrency: 'USD', salaryPeriod: 'annual');

    $normalized = (new PostingNormalizer)->normalize($source);
    $attributes = $normalized->toAttributes();
    $contentHash = $attributes['content_hash'];
    unset($attributes['content_hash']);

    expect($normalized)->not->toBeNull()
        ->and($normalized->salaryMin)->toBeNull()
        ->and($normalized->salaryMax)->toBeNull()
        ->and($normalized->salaryCurrency)->toBeNull()
        ->and($normalized->salaryPeriod)->toBeNull()
        ->and($normalized->annualSalaryMin)->toBeNull()
        ->and($normalized->annualSalaryMax)->toBeNull()
        ->and($normalized->salaryNormalizationVersion)->toBeNull()
        ->and($normalized->employmentType)->toBe(EmploymentType::FullTime)
        ->and($attributes)->toBe([
            'source' => 'himalayas',
            'external_id' => 'posting-1',
            'title' => 'Backend Engineer',
            'company_name' => 'Northwind Systems',
            'description' => 'Build the billing API.',
            'location' => null,
            'city' => null,
            'country_code' => null,
            'country_codes' => [],
            'region' => 'worldwide',
            'work_arrangement' => 'remote',
            'employment_type' => 'full_time',
            'seniority' => null,
            'salary_min' => null,
            'salary_max' => null,
            'salary_currency' => null,
            'salary_period' => null,
            'annual_salary_min' => null,
            'annual_salary_max' => null,
            'salary_normalization_version' => null,
            'apply_url' => 'https://example.com/jobs/posting-1',
            'posted_at' => $source->postedAt,
            'expires_at' => $source->expiresAt,
            'normalized_company' => 'northwind systems',
            'normalized_title' => 'backend engineer',
            'location_key' => 'worldwide',
            'duplicate_key' => '6432a93a2f7020bdb1d04d4d79bc0a2739873da4347df6e20da70bc6346a0a46',
            'text_hash' => 'c7b5070be5e14b9d995c54102ad86e4b1427bc59461291e9418385af754ab15a',
        ])
        ->and($contentHash)->toMatch('/^[0-9a-f]{64}$/');
});

test('an hourly salary gets annual figures', function () use ($posting) {
    $source = $posting(salaryMin: 40, salaryMax: 60, salaryCurrency: 'USD', salaryPeriod: 'hourly');

    $normalized = (new PostingNormalizer)->normalize($source);

    expect($normalized->salaryMin)->toBe(40)
        ->and($normalized->salaryMax)->toBe(60)
        ->and($normalized->salaryCurrency)->toBe('USD')
        ->and($normalized->salaryPeriod)->toBe(SalaryPeriod::Hourly)
        ->and($normalized->annualSalaryMin)->toBe(83200)
        ->and($normalized->annualSalaryMax)->toBe(124800)
        ->and($normalized->salaryNormalizationVersion)->toBe(1);
});

test('a monthly salary in Indonesia counts 13 months', function () use ($posting) {
    $source = $posting(location: 'Indonesia', salaryMin: 10000000, salaryCurrency: 'IDR', salaryPeriod: 'monthly');

    $normalized = (new PostingNormalizer)->normalize($source);

    expect($normalized->countryCode)->toBe('ID')
        ->and($normalized->annualSalaryMin)->toBe(130000000)
        ->and($normalized->annualSalaryMax)->toBeNull();
});

test('salary text is parsed when the source sends no amount', function () use ($posting) {
    $source = $posting(salaryText: 'Up to $60k per year');

    $normalized = (new PostingNormalizer)->normalize($source);

    expect($normalized->salaryMin)->toBeNull()
        ->and($normalized->salaryMax)->toBe(60000)
        ->and($normalized->salaryCurrency)->toBe('USD')
        ->and($normalized->salaryPeriod)->toBe(SalaryPeriod::Yearly)
        ->and($normalized->annualSalaryMin)->toBeNull()
        ->and($normalized->annualSalaryMax)->toBe(60000);
});

test('the lowest seniority wins', function (string $seniority, ?SeniorityLevel $expected) use ($posting) {
    Log::spy();

    $normalized = (new PostingNormalizer)->normalize($posting(seniority: $seniority));

    expect($normalized->seniority)->toBe($expected);
    Log::shouldNotHaveReceived('warning');
})->with([
    'mixed seniority' => ['Mid-level, Senior', SeniorityLevel::Mid],
    'any seniority' => ['Any', null],
]);

test('an unknown remote type skips the posting', function () use ($posting) {
    Log::spy();

    $normalized = (new PostingNormalizer)->normalize($posting(remoteType: 'teleport'));

    expect($normalized)->toBeNull();
    Log::shouldHaveReceived('warning')->with('jobs.source.posting_skipped', [
        'source' => 'himalayas',
        'external_id' => 'posting-1',
        'reason' => 'unknown remote type',
    ])->once();
});

test('the same job from two sources gets the same duplicate key', function () use ($posting) {
    $normalizer = new PostingNormalizer;

    $first = $normalizer->normalize($posting(
        source: 'himalayas',
        companyName: 'Northwind Systems, Inc.',
        title: 'Sr. Backend Engineer (Laravel)',
        location: 'United States',
    ));
    $second = $normalizer->normalize($posting(
        source: 'jobicy',
        companyName: 'Northwind Systems',
        title: 'Senior Backend Engineer – Laravel',
        location: 'USA',
    ));
    $different = $normalizer->normalize($posting(
        title: 'Senior Frontend Engineer – Laravel',
        location: 'USA',
    ));

    expect($first->normalizedCompany)->toBe('northwind systems')
        ->and($first->normalizedTitle)->toBe('senior backend engineer laravel')
        ->and($first->locationKey)->toBe('US')
        ->and($first->duplicateKey)->toBe($second->duplicateKey)
        ->and($different->duplicateKey)->not->toBe($first->duplicateKey);
});

test('description HTML becomes text', function () use ($posting) {
    $normalized = (new PostingNormalizer)->normalize($posting(descriptionHtml: '<p>Hi</p>'));

    expect($normalized->description)->toBe('Hi');
});
