<?php

use App\JobSources\JobicyJobSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Load one Jobicy fixture, as the body of a response.
 *
 * @return array<string, mixed>
 */
function jobicyFixture(string $name): array
{
    $contents = file_get_contents(base_path("tests/Fixtures/job-sources/jobicy/{$name}.json"));

    return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Get the query parameters of a recorded request.
 *
 * @return array<string, mixed>
 */
function jobicyQuery(Request $request): array
{
    $query = parse_url($request->url(), PHP_URL_QUERY);

    if (! is_string($query)) {
        return [];
    }

    parse_str($query, $parameters);

    return $parameters;
}

beforeEach(function () {
    config([
        'job-sources.jobicy.pages_per_query' => 1,
        'job-sources.default_queries' => ['backend engineer'],
    ]);
});

test('a page maps to postings', function () {
    $page = jobicyFixture('page');

    Http::fake([
        'jobicy.com/*' => Http::response($page),
    ]);

    $postings = iterator_to_array(app(JobicyJobSource::class)->fetch(), false);

    $first = $postings[0];
    $second = $postings[1];

    expect($postings)->toHaveCount(2)
        ->and($first->externalId)->toBe('900101')
        ->and($first->title)->toBe($page['jobs'][0]['jobTitle'])
        ->and($first->companyName)->toBe($page['jobs'][0]['companyName'])
        ->and($first->applyUrl)->toBe($page['jobs'][0]['url'])
        ->and($first->descriptionHtml)->toBe($page['jobs'][0]['jobDescription'])
        ->and($first->postedAt->toIso8601String())->toBe('2026-10-23T04:00:11+00:00')
        ->and($first->expiresAt->toIso8601String())->toBe($first->postedAt->addDays(7)->toIso8601String())
        ->and($first->location)->toBe('Finland')
        ->and($first->remoteType)->toBe('remote')
        ->and($first->employmentType)->toBe('Full-Time')
        ->and($first->seniority)->toBe('Senior')
        ->and($first->salaryMin)->toBe(45000.0)
        ->and($first->salaryMax)->toBe(60000.0)
        ->and($first->salaryCurrency)->toBe('EUR')
        ->and($first->salaryPeriod)->toBe('yearly')
        ->and($second->salaryMin)->toBeNull()
        ->and($second->salaryMax)->toBeNull()
        ->and($second->salaryCurrency)->toBeNull()
        ->and($second->salaryPeriod)->toBeNull()
        ->and($second->location)->toBe('Anywhere');
});

test('the location collapses spaces', function () {
    Http::fake([
        'jobicy.com/*' => Http::response(jobicyFixture('last-page')),
    ]);

    $postings = iterator_to_array(app(JobicyJobSource::class)->fetch(), false);

    expect($postings)->toHaveCount(1)
        ->and($postings[0]->location)->toBe('Canada, USA');
});

test('the cursor is followed while hasMore is true', function () {
    config(['job-sources.jobicy.pages_per_query' => 2]);
    $page = jobicyFixture('page');

    Http::fake([
        'jobicy.com/*' => Http::sequence()
            ->push($page)
            ->push(jobicyFixture('last-page')),
    ]);

    $generator = app(JobicyJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect($postings)->toHaveCount(3)
        ->and($requests)->toHaveCount(2)
        ->and(parse_url($requests[0]->url(), PHP_URL_PATH))->toBe('/api/jobs')
        ->and(jobicyQuery($requests[0]))->toBe(['count' => '50', 'tag' => 'backend engineer'])
        ->and(jobicyQuery($requests[1]))->toBe(['count' => '50', 'tag' => 'backend engineer', 'cursor' => $page['nextCursor']])
        ->and($generator->getReturn()->pages)->toBe(2);
});

test('a run stops at the page limit', function () {
    config(['job-sources.jobicy.pages_per_query' => 1]);

    Http::fake([
        'jobicy.com/*' => Http::response(jobicyFixture('page')),
    ]);

    $postings = iterator_to_array(app(JobicyJobSource::class)->fetch(), false);

    expect($postings)->toHaveCount(2);

    Http::assertSentCount(1);
});

test('an empty page ends the query', function () {
    Http::fake([
        'jobicy.com/*' => Http::response(jobicyFixture('empty-page')),
    ]);

    $generator = app(JobicyJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->toHaveCount(0)
        ->and($generator->getReturn()->pages)->toBe(0);
});

test('a job found by two queries is yielded once', function () {
    config(['job-sources.default_queries' => ['backend engineer', 'php developer']]);
    $page = jobicyFixture('page');

    Http::fake(['jobicy.com/*' => Http::response($page)]);

    $generator = app(JobicyJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->toHaveCount(count($page['jobs']))
        ->and($generator->getReturn()->yielded)->toBe(count($page['jobs']));

    Http::assertSentCount(2);
});

test('a job without a title is skipped', function () {
    $page = jobicyFixture('page');
    unset($page['jobs'][0]['jobTitle']);

    Http::fake([
        'jobicy.com/*' => Http::response($page),
    ]);

    $generator = app(JobicyJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->toHaveCount(1)
        ->and($generator->getReturn()->skipped)->toBe(1);
});
