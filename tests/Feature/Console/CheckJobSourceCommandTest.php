<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Load the Himalayas first page fixture, as the body of a response.
 *
 * @return array<string, mixed>
 */
function himalayasPage(): array
{
    $contents = file_get_contents(base_path('tests/Fixtures/job-sources/himalayas/page.json'));

    return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
}

test('a source with normal fields passes', function () {
    Log::spy();

    Http::fake([
        'himalayas.app/*' => Http::response(himalayasPage()),
    ]);

    $this->artisan('jobs:check-source himalayas')
        ->expectsOutputToContain('himalayas: 2 postings, fields look normal.')
        ->assertExitCode(0);

    Log::shouldNotHaveReceived('error');
    Log::shouldHaveReceived('info')
        ->with('jobs.source.field_shares', [
            'source' => 'himalayas',
            'postings' => 2,
            'shares' => ['salaryMin' => 0.5, 'seniority' => 1.0, 'employmentType' => 1.0, 'location' => 0.5, 'descriptionHtml' => 1.0],
        ])->once();
});

test('a renamed salary field fails on the second run and is logged', function () {
    Log::spy();

    $page = himalayasPage();

    foreach ($page['jobs'] as $index => $job) {
        unset($page['jobs'][$index]['minSalary'], $page['jobs'][$index]['maxSalary']);

        $page['jobs'][$index]['salaryMinimum'] = $job['minSalary'];
        $page['jobs'][$index]['salaryMaximum'] = $job['maxSalary'];
    }

    Http::fake([
        'himalayas.app/*' => Http::response($page),
    ]);

    $this->artisan('jobs:check-source himalayas')
        ->expectsOutputToContain('salaryMin set on 0% of postings, expected at least 5% (first miss)')
        ->assertExitCode(0);

    Log::shouldHaveReceived('warning')
        ->with('jobs.source.schema_drift_suspected', ['source' => 'himalayas', 'fields' => ['salaryMin' => 0.0]])
        ->once();
    Log::shouldNotHaveReceived('error');

    $this->artisan('jobs:check-source himalayas')
        ->expectsOutputToContain('salaryMin set on 0% of postings, expected at least 5%')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'jobs.source.schema_drift'
            && $context['source'] === 'himalayas'
            && $context['problems'] !== []);
});

test('a field that recovers clears the first miss', function () {
    Log::spy();
    $normal = himalayasPage();
    $renamed = $normal;

    foreach ($renamed['jobs'] as $index => $job) {
        unset($renamed['jobs'][$index]['minSalary'], $renamed['jobs'][$index]['maxSalary']);
        $renamed['jobs'][$index]['salaryMinimum'] = $job['minSalary'];
        $renamed['jobs'][$index]['salaryMaximum'] = $job['maxSalary'];
    }

    $response = $renamed;
    Http::fake([
        'himalayas.app/*' => function () use (&$response) {
            return Http::response($response);
        },
    ]);

    $this->artisan('jobs:check-source himalayas')->assertExitCode(0);

    $response = $normal;
    $this->artisan('jobs:check-source himalayas')->assertExitCode(0);

    $response = $renamed;
    $this->artisan('jobs:check-source himalayas')
        ->expectsOutputToContain('salaryMin set on 0% of postings, expected at least 5% (first miss)')
        ->assertExitCode(0);

    Log::shouldNotHaveReceived('error');
});

test('an unavailable source fails at once', function () {
    Log::spy();
    Http::fake(['himalayas.app/*' => Http::response([], 404)]);

    $this->artisan('jobs:check-source himalayas')->assertExitCode(1);

    Http::assertSentCount(1);
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'jobs.source.unavailable'
            && $context['source'] === 'himalayas')
        ->once();
});
