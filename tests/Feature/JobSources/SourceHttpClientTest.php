<?php

use App\JobSources\Exceptions\JobSourceRateLimited;
use App\JobSources\Exceptions\JobSourceUnavailable;
use App\JobSources\RunDeadline;
use App\JobSources\SourceHttpClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('a 429 waits for Retry-After and then succeeds', function () {
    Sleep::fake();

    Http::fake([
        'https://jobs.example.com/*' => Http::sequence()
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '2'])
            ->push(['jobs' => []], 200),
    ]);

    $client = new SourceHttpClient('himalayas', 'https://jobs.example.com');

    expect($client->getJson('/jobs'))->toBe(['jobs' => []]);

    Http::assertSentCount(2);

    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 2,
        1,
    );
});

test('a 429 whose wait passes the deadline throws without waiting', function () {
    Sleep::fake();
    $this->freezeTime();

    Http::fake([
        'https://jobs.example.com/*' => Http::response([], 429, ['Retry-After' => '10']),
    ]);

    $client = new SourceHttpClient('himalayas', 'https://jobs.example.com', null, new RunDeadline(CarbonImmutable::now()->addSeconds(20)));

    expect(fn () => $client->getJson('/jobs'))->toThrow(JobSourceRateLimited::class);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('a 429 with a long Retry-After throws at once', function () {
    Http::fake([
        'https://jobs.example.com/*' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => '120']),
    ]);

    $client = new SourceHttpClient('himalayas', 'https://jobs.example.com');

    expect(fn () => $client->getJson('/jobs'))->toThrow(function (JobSourceRateLimited $exception) {
        expect($exception->source)->toBe('himalayas')
            ->and($exception->retryAfterSeconds)->toBe(120);
    });

    Http::assertSentCount(1);
});

test('server errors are retried and then reported', function () {
    Sleep::fake();

    Http::fake([
        'https://jobs.example.com/*' => Http::sequence()
            ->push(['error' => 'down'], 503)
            ->push(['error' => 'down'], 503)
            ->push(['error' => 'down'], 503),
    ]);

    $client = new SourceHttpClient('himalayas', 'https://jobs.example.com');

    expect(fn () => $client->getJson('/jobs'))->toThrow(function (JobSourceUnavailable $exception) {
        expect($exception->source)->toBe('himalayas')
            ->and($exception->status)->toBe(503);
    });

    Http::assertSentCount(3);
});

test('a client error is not retried', function () {
    Http::fake([
        'https://jobs.example.com/*' => Http::response(['error' => 'not found'], 404),
    ]);

    $client = new SourceHttpClient('himalayas', 'https://jobs.example.com');

    expect(fn () => $client->getJson('/jobs'))->toThrow(function (JobSourceUnavailable $exception) {
        expect($exception->source)->toBe('himalayas')
            ->and($exception->status)->toBe(404);
    });

    Http::assertSentCount(1);
});

test('the request names the app and sends the key', function () {
    Http::fake([
        'https://jobs.example.com/*' => Http::response(['jobs' => []]),
    ]);

    $withToken = new SourceHttpClient('himalayas', 'https://jobs.example.com', 'secret-token');
    $withToken->getJson('/jobs');

    $userAgent = config('job-sources.user_agent');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', $userAgent)
        && $request->hasHeader('Authorization', 'Bearer secret-token'));

    $withoutToken = new SourceHttpClient('himalayas', 'https://jobs.example.com');
    $withoutToken->getJson('/jobs');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));

    Http::assertSentCount(2);
});
