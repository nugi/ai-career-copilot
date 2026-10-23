<?php

use App\JobSources\FakeJobSource;
use App\JobSources\JobSourceRegistry;

test('a tagged job source is found by its key', function () {
    app()->tag([FakeJobSource::class], 'job-sources');

    $registry = app(JobSourceRegistry::class);

    expect($registry->get('fake'))->toBeInstanceOf(FakeJobSource::class);
});

test('an unknown key is rejected', function () {
    $registry = new JobSourceRegistry([]);

    expect(fn () => $registry->get('missing'))->toThrow(InvalidArgumentException::class);
});

test('two sources with the same key are rejected', function () {
    expect(fn () => new JobSourceRegistry([new FakeJobSource, new FakeJobSource]))
        ->toThrow(LogicException::class);
});
