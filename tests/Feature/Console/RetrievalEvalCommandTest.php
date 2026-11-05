<?php

use App\Models\JobPosting;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    $this->goldenPath = storage_path('framework/testing/retrieval-'.uniqid().'.json');
    $this->outputDirectory = storage_path('framework/testing/retrieval-results-'.uniqid());
    File::ensureDirectoryExists(dirname($this->goldenPath));
    Embeddings::fake(fn (): array => [[1, ...array_fill(0, 1535, 0)]]);
});

afterEach(function (): void {
    File::delete($this->goldenPath);
    File::deleteDirectory($this->outputDirectory);
});

/**
 * Three live remote postings with vectors: two Laravel roles and a designer.
 *
 * @return array<string, JobPosting>
 */
function retrievalPostings(): array
{
    $postings = JobPosting::factory()->remote()->withEmbedding()->state(['description' => 'Work', 'expires_at' => null, 'source' => 'himalayas']);

    return [
        'laravel' => $postings->create(['external_id' => 'laravel', 'title' => 'Laravel developer', 'embedding' => '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']']),
        'php' => $postings->create(['external_id' => 'php', 'title' => 'PHP engineer', 'embedding' => '['.implode(',', [3, 4, ...array_fill(0, 1534, 0)]).']']),
        'designer' => $postings->create(['external_id' => 'designer', 'title' => 'Designer', 'embedding' => '['.implode(',', [0, 1, ...array_fill(0, 1534, 0)]).']']),
    ];
}

/**
 * Write a golden set with one Laravel case and the given labels.
 *
 * @param  list<array{posting: string, grade: int|null}>  $labels
 */
function writeGolden(string $path, array $labels): void
{
    File::put($path, json_encode(['cases' => [[
        'id' => 'laravel-remote',
        'description' => 'A Laravel profile, remote anywhere.',
        'profile' => [
            'target_roles' => ['Laravel Developer'], 'skills' => ['Laravel' => 'expert', 'PHP' => 'beginner'],
            'work_arrangements' => ['remote'], 'country_code' => null,
            'min_salary' => null, 'salary_currency' => null, 'salary_period' => null,
        ],
        'labels' => $labels,
    ]]], JSON_THROW_ON_ERROR));
}

test('pooling adds every unlabelled posting of every top 10 with a null grade and keeps the graded ones', function () {
    retrievalPostings();
    writeGolden($this->goldenPath, [['posting' => 'himalayas:laravel', 'grade' => 2]]);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--pool' => true])
        ->expectsOutputToContain('Added 2 postings to grade')
        ->assertSuccessful();

    $labels = json_decode(File::get($this->goldenPath), true)['cases'][0]['labels'];

    expect(collect($labels)->pluck('grade', 'posting')->all())->toBe([
        'himalayas:laravel' => 2,
        'himalayas:php' => null,
        'himalayas:designer' => null,
    ])->and($labels[1]['title'])->not->toBeNull();
});

test('scoring prints every variant and saves the run with its settings', function () {
    retrievalPostings();
    writeGolden($this->goldenPath, [
        ['posting' => 'himalayas:laravel', 'grade' => 2],
        ['posting' => 'himalayas:php', 'grade' => 1],
        ['posting' => 'himalayas:designer', 'grade' => 0],
        ['posting' => 'himalayas:gone', 'grade' => 2],
    ]);

    $this->artisan('eval:retrieval', [
        '--labels' => $this->goldenPath, '--bootstrap' => 200, '--save' => true, '--output' => $this->outputDirectory,
    ])
        ->expectsOutputToContain('Cases scored: 1 of 1. Label coverage: 75% of 4 graded labels are live.')
        ->expectsOutputToContain('Under 80% of the graded labels are live.')
        ->assertSuccessful();

    $files = File::files($this->outputDirectory);
    $run = json_decode(File::get($files[0]->getPathname()), true);

    expect($files)->toHaveCount(1)
        ->and(array_keys($run['summary']))->toEqualCanonicalizing([
            'semantic', 'keyword:count', 'hybrid:count', 'keyword:idf', 'hybrid:idf', 'keyword:idf_proficiency', 'hybrid:idf_proficiency',
        ])
        ->and($run['summary']['semantic']['ndcg_at_10'])->toBe(1.0)
        ->and($run['summary']['semantic']['recall_at_10'])->toBe(1.0)
        ->and($run['settings']['embedding_model'])->toBe('text-embedding-3-small')
        ->and($run['settings']['rrf_k'])->toBe(60)
        ->and($run['labels'])->toBe(['graded' => 4, 'live_share' => 0.75])
        ->and($run['summary']['semantic'])->toHaveKey('ndcg_vs_baseline');
});

test('a golden set with no graded relevant label fails and says to pool first', function () {
    retrievalPostings();
    writeGolden($this->goldenPath, [['posting' => 'himalayas:laravel', 'grade' => null]]);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--weighting' => ['count']])
        ->expectsOutputToContain('Run --pool and grade the labels first.')
        ->assertFailed();
});

test('an unknown weighting is refused', function () {
    writeGolden($this->goldenPath, []);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--weighting' => ['bm25']])
        ->expectsOutputToContain('Unknown weighting bm25.')
        ->assertFailed();
});
