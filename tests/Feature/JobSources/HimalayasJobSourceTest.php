<?php

use App\JobSources\HimalayasJobSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Load one Himalayas fixture, as the body of a response.
 *
 * @return array<string, mixed>
 */
function himalayasFixture(string $name): array
{
    $contents = file_get_contents(base_path("tests/Fixtures/job-sources/himalayas/{$name}.json"));

    return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Get the query parameters of a recorded request.
 *
 * @return array<string, mixed>
 */
function himalayasQuery(Request $request): array
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
        'services.himalayas.key' => 'test-key',
        'job-sources.himalayas.pages_per_query' => 1,
        'job-sources.default_queries' => ['php developer'],
    ]);
});

test('a page maps to postings', function () {
    $page = himalayasFixture('page');

    Http::fake([
        'himalayas.app/*' => Http::sequence()
            ->push($page)
            ->push(himalayasFixture('last-page')),
    ]);

    $postings = iterator_to_array(app(HimalayasJobSource::class)->fetch(), false);

    $first = $postings[0];
    $second = $postings[1];

    expect($first->externalId)->toBe($page['jobs'][0]['guid'])
        ->and($first->applyUrl)->toBe($page['jobs'][0]['applicationLink'])
        ->and($first->title)->toBe($page['jobs'][0]['title'])
        ->and($first->companyName)->toBe($page['jobs'][0]['companyName'])
        ->and($first->salaryMin)->toBe(57860.0)
        ->and($first->salaryMax)->toBe(68704.0)
        ->and($first->salaryCurrency)->toBe('USD')
        ->and($first->salaryPeriod)->toBe('annual')
        ->and($first->location)->toBe('United States')
        ->and($first->seniority)->toBe('Manager')
        ->and($first->employmentType)->toBe('Full Time')
        ->and($first->remoteType)->toBe('remote')
        ->and($first->postedAt->getTimestamp())->toBe($page['jobs'][0]['pubDate'])
        ->and($first->expiresAt->getTimestamp())->toBe($page['jobs'][0]['expiryDate'])
        ->and($second->salaryPeriod)->toBeNull()
        ->and($second->location)->toBeNull();
});

test('each query is searched newest first', function () {
    config(['job-sources.default_queries' => ['php developer', 'go developer']]);

    Http::fake([
        'himalayas.app/*' => Http::sequence()
            ->push(himalayasFixture('page'))
            ->push(himalayasFixture('last-page')),
    ]);

    iterator_to_array(app(HimalayasJobSource::class)->fetch(), false);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect($requests)->toHaveCount(2);

    foreach (['php developer', 'go developer'] as $index => $query) {
        expect(parse_url($requests[$index]->url(), PHP_URL_PATH))->toBe('/jobs/api/search')
            ->and(himalayasQuery($requests[$index]))->toBe(['q' => $query, 'sort' => 'recent', 'page' => '1']);
    }
});

test('a job found by two queries is yielded once', function () {
    config(['job-sources.default_queries' => ['php developer', 'go developer']]);
    $page = himalayasFixture('page');

    Http::fake(['himalayas.app/*' => Http::response($page)]);

    $generator = app(HimalayasJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->toHaveCount(count($page['jobs']))
        ->and($generator->getReturn()->yielded)->toBe(count($page['jobs']));

    Http::assertSentCount(2);
});

test('a run stops at the deadline', function () {
    $this->freezeTime();
    config([
        'job-sources.max_run_seconds' => 60,
        'job-sources.default_queries' => ['php developer', 'go developer'],
    ]);

    Http::fake(function (Request $request) {
        $this->travel(61)->seconds();

        return Http::response(himalayasFixture('page'));
    });

    $generator = app(HimalayasJobSource::class)->fetch();
    iterator_to_array($generator, false);

    expect($generator->getReturn()->stoppedEarly)->toBeTrue();
    Http::assertSentCount(1);
});

test('a full page asks for the next page', function () {
    config(['job-sources.himalayas.pages_per_query' => 2]);
    $page = himalayasFixture('page');
    $jobs = $page['jobs'];
    $page['jobs'] = [];

    for ($index = 0; $index < 20; $index++) {
        $job = $jobs[$index % count($jobs)];
        $job['guid'] .= '-'.$index;
        $page['jobs'][] = $job;
    }

    Http::fake([
        'himalayas.app/*' => Http::sequence()
            ->push($page)
            ->push(himalayasFixture('last-page')),
    ]);

    $generator = app(HimalayasJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect($postings)->toHaveCount(21)
        ->and($requests)->toHaveCount(2)
        ->and(himalayasQuery($requests[1])['page'])->toBe('2')
        ->and($generator->getReturn()->pages)->toBe(2);
});

test('a run stops at the page limit', function () {
    config(['job-sources.himalayas.pages_per_query' => 1]);

    Http::fake([
        'himalayas.app/*' => Http::response(himalayasFixture('page')),
    ]);

    $postings = iterator_to_array(app(HimalayasJobSource::class)->fetch(), false);

    expect($postings)->toHaveCount(2);

    Http::assertSentCount(1);
});

test('an empty page ends the run', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(himalayasFixture('empty-page')),
    ]);

    $generator = app(HimalayasJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->toHaveCount(0)
        ->and($generator->getReturn()->pages)->toBe(0);
});

test('a job without a title is skipped', function () {
    $page = himalayasFixture('page');
    unset($page['jobs'][0]['title']);

    Http::fake([
        'himalayas.app/*' => Http::sequence()
            ->push($page)
            ->push(himalayasFixture('last-page')),
    ]);

    $generator = app(HimalayasJobSource::class)->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->toHaveCount(1)
        ->and($generator->getReturn()->skipped)->toBe(1);
});

test('a missing expiry date falls back to 30 days', function () {
    $lastPage = himalayasFixture('last-page');
    $lastPage['jobs'][0]['expiryDate'] = null;

    Http::fake([
        'himalayas.app/*' => Http::response($lastPage),
    ]);

    $postings = iterator_to_array(app(HimalayasJobSource::class)->fetch(), false);

    expect($postings)->toHaveCount(1)
        ->and($postings[0]->expiresAt->getTimestamp())
        ->toBe($postings[0]->postedAt->addDays(30)->getTimestamp());
});

test('the API key is sent as a bearer token', function () {
    Http::fake([
        'himalayas.app/*' => Http::sequence()
            ->push(himalayasFixture('page'))
            ->push(himalayasFixture('last-page')),
    ]);

    iterator_to_array(app(HimalayasJobSource::class)->fetch(), false);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('the fixtures have the fields the mapper reads', function (string $fixture) {
    $page = himalayasFixture($fixture);

    expect($page['jobs'])->toBeArray()->not->toBeEmpty();

    foreach ($page['jobs'] as $job) {
        expect($job)->toBeArray();

        foreach (['guid', 'title', 'companyName', 'applicationLink', 'description'] as $field) {
            expect($job[$field])->toBeString();
        }

        foreach (['pubDate', 'expiryDate'] as $field) {
            expect($job[$field])->toBeInt();
        }

        foreach (['minSalary', 'maxSalary'] as $field) {
            expect($job[$field] === null || is_int($job[$field]) || is_float($job[$field]))->toBeTrue();
        }

        foreach (['currency', 'employmentType'] as $field) {
            expect($job[$field] === null || is_string($job[$field]))->toBeTrue();
        }

        expect($job['salaryPeriod'])->toBeString()
            ->and($job['seniority'])->toBeArray()
            ->and($job['locationRestrictions'])->toBeArray();
    }

    expect($page['totalCount'])->toBeInt();
})->with([
    'first page' => ['page'],
    'last page' => ['last-page'],
]);
