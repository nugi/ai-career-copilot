<?php

use App\JobSources\PostingBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Build the raw values of a valid posting, with the given values replaced.
 */
$validValues = function (array $overrides = []): array {
    $postedAt = CarbonImmutable::parse('2026-10-20 09:00:00');

    return $overrides + [
        'externalId' => 'himalayas-1',
        'title' => 'Senior Laravel Engineer',
        'companyName' => 'Northwind Systems',
        'applyUrl' => 'https://example.com/jobs/himalayas-1',
        'descriptionHtml' => '<p>Own the billing API.</p>',
        'postedAt' => $postedAt,
        'expiresAt' => $postedAt->addDays(30),
        'location' => 'Germany',
        'remoteType' => 'remote',
        'employmentType' => 'Full Time',
        'seniority' => 'Senior',
        'salaryMin' => 45,
        'salaryMax' => 60,
        'salaryCurrency' => 'EUR',
        'salaryPeriod' => 'hourly',
        'salaryText' => 'Up to €60',
    ];
};

test('a posting without a title is skipped and counted', function () use ($validValues) {
    Log::spy();

    $builder = new PostingBuilder('himalayas');

    expect($builder->build($validValues(['title' => null])))->toBeNull()
        ->and($builder->report(1)->skipped)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->with('jobs.source.posting_skipped', Mockery::on(
            fn (array $context): bool => $context['source'] === 'himalayas'
                && $context['external_id'] === 'himalayas-1'
                && $context['reason'] === 'missing title',
        ))
        ->once();
});

test('a reversed salary range is dropped and the posting is kept', function () use ($validValues) {
    Log::spy();

    $builder = new PostingBuilder('himalayas');

    $posting = $builder->build($validValues([
        'salaryMin' => 60,
        'salaryMax' => 45,
        'salaryCurrency' => 'EUR',
    ]));

    expect($posting)->not->toBeNull()
        ->and($posting->salaryMin)->toBeNull()
        ->and($posting->salaryMax)->toBeNull()
        ->and($posting->salaryCurrency)->toBe('EUR')
        ->and($builder->report(1)->droppedFields)->toBe(2);

    Log::shouldHaveReceived('warning')
        ->with('jobs.source.field_dropped', Mockery::on(
            fn (array $context): bool => $context['source'] === 'himalayas'
                && $context['external_id'] === 'himalayas-1'
                && $context['field'] === 'salaryMin'
                && $context['reason'] === 'minimum salary above the maximum salary',
        ))
        ->once();
});

test('a lowercase currency is dropped and the posting is kept', function () use ($validValues) {
    Log::spy();

    $builder = new PostingBuilder('himalayas');

    $posting = $builder->build($validValues(['salaryCurrency' => 'usd']));

    expect($posting)->not->toBeNull()
        ->and($posting->salaryCurrency)->toBeNull()
        ->and($posting->salaryMin)->toBe(45.0)
        ->and($posting->salaryMax)->toBe(60.0)
        ->and($builder->report(1)->droppedFields)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->with('jobs.source.field_dropped', Mockery::on(
            fn (array $context): bool => $context['source'] === 'himalayas'
                && $context['external_id'] === 'himalayas-1'
                && $context['field'] === 'salaryCurrency',
        ))
        ->once();
});
