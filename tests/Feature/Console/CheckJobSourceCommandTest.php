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
});

test('a renamed salary field fails and is logged', function () {
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
        ->expectsOutputToContain('salaryMin set on 0% of postings, expected at least 5%')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'jobs.source.schema_drift'
            && $context['source'] === 'himalayas'
            && $context['problems'] !== []);
});
