<?php

use App\Actions\Jobs\ImportJobPostings;
use App\Enums\IngestionRunStatus;
use App\Jobs\IngestJobSource;
use App\JobSources\Contracts\JobSource;
use App\JobSources\Exceptions\JobSourceRateLimited;
use App\JobSources\Exceptions\JobSourceUnavailable;
use App\JobSources\FakeJobSource;
use App\JobSources\JobSourceRegistry;
use App\Models\IngestionRun;
use Illuminate\Support\Facades\Log;

/**
 * Build a registry that holds one source under the "fake" key.
 */
function ingestSourceRegistry(JobSource $source): JobSourceRegistry
{
    return new JobSourceRegistry([$source]);
}

test('a successful run records the counts', function () {
    Log::spy();
    $registry = ingestSourceRegistry(new FakeJobSource);
    $this->app->instance(JobSourceRegistry::class, $registry);

    (new IngestJobSource('fake'))->handle(app(JobSourceRegistry::class), app(ImportJobPostings::class));

    $run = IngestionRun::query()->sole();
    expect($run->status)->toBe(IngestionRunStatus::Succeeded)
        ->and($run->source)->toBe('fake')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($run->pages)->toBe(1)
        ->and($run->fetched)->toBe(3)
        ->and($run->skipped)->toBe(0)
        ->and($run->dropped_fields)->toBe(0)
        ->and($run->created)->toBe(3)
        ->and($run->updated)->toBe(0)
        ->and($run->unchanged)->toBe(0)
        ->and($run->links_changed)->toBe(0)
        ->and($run->stopped_early)->toBeFalse()
        ->and($run->error)->toBeNull();
    Log::shouldHaveReceived('info')->with('jobs.ingest.finished', [
        'source' => 'fake',
        'pages' => 1,
        'fetched' => 3,
        'skipped' => 0,
        'dropped_fields' => 0,
        'created' => 3,
        'updated' => 0,
        'unchanged' => 0,
        'links_changed' => 0,
        'stopped_early' => false,
    ])->once();
});

test('a rate limited source records the wait and releases the job', function () {
    $registry = ingestSourceRegistry(new class implements JobSource
    {
        public function key(): string
        {
            return 'fake';
        }

        public function fetch(): Generator
        {
            throw new JobSourceRateLimited('fake', 120);
        }
    });
    $this->app->instance(JobSourceRegistry::class, $registry);
    $job = (new IngestJobSource('fake'))->withFakeQueueInteractions();

    $job->handle(app(JobSourceRegistry::class), app(ImportJobPostings::class));

    $job->assertReleased(delay: 120);
    $run = IngestionRun::query()->sole();
    expect($run->status)->toBe(IngestionRunStatus::RateLimited)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->not->toBeNull()
        ->and($run->error)->toContain(JobSourceRateLimited::class);
});

test('an unavailable source records a failure and fails the job', function () {
    Log::spy();
    $registry = ingestSourceRegistry(new class implements JobSource
    {
        public function key(): string
        {
            return 'fake';
        }

        public function fetch(): Generator
        {
            throw new JobSourceUnavailable('fake', 'unreachable');
        }
    });
    $this->app->instance(JobSourceRegistry::class, $registry);
    $job = (new IngestJobSource('fake'))->withFakeQueueInteractions();

    $job->handle(app(JobSourceRegistry::class), app(ImportJobPostings::class));

    $job->assertFailed();
    $run = IngestionRun::query()->sole();
    expect($run->status)->toBe(IngestionRunStatus::Failed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error)->toContain(JobSourceUnavailable::class);
    Log::shouldHaveReceived('warning')->with('jobs.ingest.failed', [
        'source' => 'fake',
        'exception' => JobSourceUnavailable::class,
    ])->once();
});
