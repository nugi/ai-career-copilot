<?php

use App\Jobs\IngestJobSource;
use Illuminate\Support\Facades\Queue;

test('the command queues one ingestion run per source on the batch queue', function () {
    Queue::fake();

    $this->artisan('jobs:ingest')
        ->expectsOutputToContain('Queued ingestion for [himalayas].')
        ->expectsOutputToContain('Queued ingestion for [jobicy].')
        ->assertExitCode(0);

    Queue::assertPushed(IngestJobSource::class, 2);
    Queue::assertPushedOn('ai-batch', IngestJobSource::class);
    Queue::assertPushed(IngestJobSource::class, fn (IngestJobSource $job): bool => $job->source === 'himalayas');
    Queue::assertPushed(IngestJobSource::class, fn (IngestJobSource $job): bool => $job->source === 'jobicy');
});

test('an unknown source fails and queues nothing', function () {
    Queue::fake();

    $this->artisan('jobs:ingest unknown')
        ->expectsOutputToContain('Unknown job source [unknown].')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});
