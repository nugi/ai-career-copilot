<?php

use App\JobSources\Contracts\JobSource;
use App\JobSources\FakeJobSource;
use App\JobSources\JobPostingData;

test('a job source yields valid postings under its own key', function (Closure $sourceFactory) {
    /** @var JobSource $source */
    $source = $sourceFactory();

    $postings = iterator_to_array($source->fetch(), false);

    expect($postings)->not->toBeEmpty();

    foreach ($postings as $posting) {
        expect($posting)->toBeInstanceOf(JobPostingData::class)
            ->and($posting->source)->toBe($source->key());
    }

    $externalIds = array_map(
        fn (JobPostingData $posting): string => $posting->externalId,
        $postings,
    );

    expect(array_unique($externalIds))->toHaveCount(count($externalIds));
})->with([
    // Every job source class must be added to this dataset.
    'fake' => fn () => new FakeJobSource,
]);
