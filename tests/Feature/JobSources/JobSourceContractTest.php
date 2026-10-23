<?php

use App\JobSources\Contracts\JobSource;
use App\JobSources\FakeJobSource;
use App\JobSources\FetchReport;
use App\JobSources\HimalayasJobSource;
use App\JobSources\JobPostingData;
use Illuminate\Support\Facades\Http;

test('a job source yields valid postings under its own key', function (Closure $sourceFactory) {
    /** @var JobSource $source */
    $source = $sourceFactory();

    $generator = $source->fetch();
    $postings = iterator_to_array($generator, false);

    expect($postings)->not->toBeEmpty();

    foreach ($postings as $posting) {
        expect($posting)->toBeInstanceOf(JobPostingData::class)
            ->and($posting->source)->toBe($source->key())
            ->and($posting->expiresAt)->toBeGreaterThan($posting->postedAt);
    }

    $externalIds = array_map(
        fn (JobPostingData $posting): string => $posting->externalId,
        $postings,
    );

    expect(array_unique($externalIds))->toHaveCount(count($externalIds));

    $report = $generator->getReturn();

    expect($report)->toBeInstanceOf(FetchReport::class)
        ->and($report->yielded)->toBe(count($postings));
})->with([
    // Every job source class must be added to this dataset.
    'fake' => fn () => new FakeJobSource,
    'himalayas' => function (): HimalayasJobSource {
        Http::fake([
            'himalayas.app/*' => Http::sequence()
                ->pushFile(base_path('tests/Fixtures/job-sources/himalayas/page.json'))
                ->pushFile(base_path('tests/Fixtures/job-sources/himalayas/last-page.json')),
        ]);

        config(['services.himalayas.key' => 'test-key']);

        return new HimalayasJobSource;
    },
]);
