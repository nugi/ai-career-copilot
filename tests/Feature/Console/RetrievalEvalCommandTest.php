<?php

use App\Ai\Agents\RelevanceGrader;
use App\Models\AiUsage;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Reranking;

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

test('pooling excludes seeded postings even when they are the best match', function () {
    retrievalPostings();
    JobPosting::factory()->remote()->withEmbedding()->create([
        'source' => 'manual', 'external_id' => null, 'title' => 'Laravel Developer',
        'description' => 'Laravel Developer PHP', 'expires_at' => null,
        'embedding' => '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']',
    ]);
    writeGolden($this->goldenPath, []);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--pool' => true])
        ->expectsOutputToContain('Added 3 postings to grade')->assertSuccessful();

    $labels = json_decode(File::get($this->goldenPath), true)['cases'][0]['labels'];
    expect(array_column($labels, 'posting'))->toEqualCanonicalizing(['himalayas:laravel', 'himalayas:php', 'himalayas:designer']);
});

test('grading fills null labels and preserves graded labels without sending them', function () {
    $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 6)->startOfDay());
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    retrievalPostings();
    $graded = ['posting' => 'himalayas:designer', 'grade' => 0];
    writeGolden($this->goldenPath, [
        ['posting' => 'himalayas:laravel', 'grade' => null],
        ['posting' => 'himalayas:php', 'grade' => null],
        $graded,
    ]);
    $golden = json_decode(File::get($this->goldenPath), true);
    $golden['cases'][] = [...$golden['cases'][0], 'id' => 'already-graded', 'labels' => [$graded]];
    File::put($this->goldenPath, json_encode($golden));
    RelevanceGrader::fake([['profile_field' => 'engineering', 'profile_level' => 'mid', 'postings' => [
        ['posting_id' => 1, 'reason' => ' Same work. ', 'main_work' => 'backend', 'posting_level' => 'mid', 'matches_target_role' => true],
        ['posting_id' => 1, 'reason' => 'Duplicate.', 'main_work' => 'design', 'posting_level' => 'mid', 'matches_target_role' => false],
        ['posting_id' => 2, 'reason' => 'Adjacent work.', 'main_work' => 'frontend', 'posting_level' => 'mid', 'matches_target_role' => false],
    ]]]);
    Reranking::fake();

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--grade' => true, '--with-rerank' => true, '--pool' => true])
        ->expectsOutputToContain('Graded: 2. Calls: 1. Not live: 0. Invalid: 0. Cost: $0.000000')
        ->assertSuccessful();

    $saved = json_decode(File::get($this->goldenPath), true);
    expect($saved['labelled_on'])->toBe('2026-11-06')
        ->and($saved['labelled_by'])->toBe('relevance-grader/'.RelevanceGrader::PROMPT_VERSION)
        ->and($saved['cases'][0]['labels'][2])->toBe($graded);
    foreach (array_slice($saved['cases'][0]['labels'], 0, 2) as $label) {
        expect($label['grader'])->toBe('relevance-grader/'.RelevanceGrader::PROMPT_VERSION)->and($label['reason'])->toBe(trim($label['reason']))
            ->and($label['facts'])->toBe([
                'main_work' => $label['grade'] === 2 ? 'backend' : 'frontend',
                'posting_level' => 'mid',
                'profile_level' => 'mid',
                'matches_target_role' => $label['grade'] === 2,
            ]);
    }
    expect(array_column(array_slice($saved['cases'][0]['labels'], 0, 2), 'grade'))->toEqualCanonicalizing([2, 1]);
    RelevanceGrader::assertPromptedTimes(1);
    RelevanceGrader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Laravel developer') && $prompt->contains('PHP engineer') && ! $prompt->contains('Designer'));
    Embeddings::assertNothingGenerated();
    Reranking::assertNothingReranked();
});

test('invalid grader entries and labels without live postings keep null grades', function () {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    $postings = retrievalPostings();
    $postings['designer']->update(['expires_at' => now()->subDay()]);
    JobPosting::factory()->create(['source' => 'manual', 'external_id' => null, 'expires_at' => null]);
    writeGolden($this->goldenPath, array_map(fn (string $key): array => ['posting' => $key, 'grade' => null], [
        'himalayas:laravel', 'himalayas:php', 'himalayas:designer', 'himalayas:gone', 'manual:',
    ]));
    $original = File::get($this->goldenPath);
    RelevanceGrader::fake([['profile_field' => 'engineering', 'profile_level' => 'mid', 'postings' => [
        ['posting_id' => 1, 'reason' => 'Unknown work.', 'main_work' => 'unknown', 'posting_level' => 'mid', 'matches_target_role' => true],
        ['posting_id' => 2, 'reason' => 'Not a boolean.', 'main_work' => 'backend', 'posting_level' => 'mid', 'matches_target_role' => 'true'],
        ['posting_id' => 99, 'reason' => 'Unknown posting.', 'main_work' => 'backend', 'posting_level' => 'mid', 'matches_target_role' => true],
        ['posting_id' => '1', 'reason' => 'Not an integer ID.', 'main_work' => 'backend', 'posting_level' => 'mid', 'matches_target_role' => true],
    ]]]);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--grade' => true])
        ->expectsOutputToContain('Graded: 0. Calls: 1. Not live: 3. Invalid: 2.')
        ->assertSuccessful();

    expect(File::get($this->goldenPath))->toBe($original);
    RelevanceGrader::assertPromptedTimes(1);
});

test('an invalid profile level leaves every posting ungraded', function () {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    retrievalPostings();
    writeGolden($this->goldenPath, [
        ['posting' => 'himalayas:laravel', 'grade' => null],
        ['posting' => 'himalayas:php', 'grade' => null],
    ]);
    $original = File::get($this->goldenPath);
    RelevanceGrader::fake([['profile_field' => 'engineering', 'profile_level' => 'unknown', 'postings' => [
        ['posting_id' => 1, 'reason' => 'Same work.', 'main_work' => 'backend', 'posting_level' => 'mid', 'matches_target_role' => true],
    ]]]);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--grade' => true])
        ->expectsOutputToContain('Graded: 0. Calls: 1. Not live: 0. Invalid: 2.')
        ->assertSuccessful();

    expect(File::get($this->goldenPath))->toBe($original);
});

test('relevance grades follow the fact rules', function (string $field, string $profileLevel, string $work, string $postingLevel, bool $matches, int $grade) {
    expect(RelevanceGrader::grade($field, $profileLevel, $work, $postingLevel, $matches))->toBe($grade);
})->with([
    ['engineering', 'junior', 'backend', 'senior', true, 0],
    ['engineering', 'mid', 'design', 'mid', true, 0],
    ['engineering', 'mid', 'other', 'mid', true, 0],
    ['design', 'mid', 'design', 'mid', false, 2],
    ['design', 'mid', 'frontend', 'mid', false, 1],
    ['design', 'mid', 'backend', 'mid', false, 0],
    ['engineering', 'senior', 'backend', 'lead', true, 2],
    ['engineering', 'senior', 'full_stack', 'senior', false, 1],
]);

test('a spent batch budget fails grading without a call', function () {
    config(['ai-routing.batch_daily_budget_usd' => 0.01, 'ai-routing.global_daily_budget_usd' => null]);
    retrievalPostings();
    AiUsage::factory()->create(['feature' => 'embedding', 'cost_usd' => 0.02, 'created_at' => now()]);
    writeGolden($this->goldenPath, [['posting' => 'himalayas:laravel', 'grade' => null]]);
    $original = File::get($this->goldenPath);
    RelevanceGrader::fake()->preventStrayPrompts();

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--grade' => true])
        ->expectsOutputToContain('budget is spent')->assertFailed();

    expect(File::get($this->goldenPath))->toBe($original);
    RelevanceGrader::assertNeverPrompted();
    Embeddings::assertNothingGenerated();
});

test('a failed second case leaves the first saved and a second run grades only the second case', function () {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
    retrievalPostings();
    writeGolden($this->goldenPath, [['posting' => 'himalayas:laravel', 'grade' => null]]);
    $golden = json_decode(File::get($this->goldenPath), true);
    $golden['cases'][] = [...$golden['cases'][0], 'id' => 'second', 'labels' => [['posting' => 'himalayas:php', 'grade' => null]]];
    File::put($this->goldenPath, json_encode($golden));
    $calls = 0;
    RelevanceGrader::fake(function () use (&$calls): array {
        if (++$calls === 2) {
            throw new RuntimeException('Grader unavailable');
        }

        return ['profile_field' => 'engineering', 'profile_level' => 'mid', 'postings' => [['posting_id' => 1, 'reason' => 'Same role.', 'main_work' => 'backend', 'posting_level' => 'mid', 'matches_target_role' => true]]];
    });
    Exceptions::fake();

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--grade' => true])
        ->expectsOutputToContain('Case second failed')->assertFailed();

    $saved = json_decode(File::get($this->goldenPath), true);
    expect($saved['cases'][0]['labels'][0]['grade'])->toBe(2)
        ->and($saved['cases'][1]['labels'][0]['grade'])->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
    $resumedPrompts = [];
    RelevanceGrader::fake(function (string $prompt) use (&$resumedPrompts): array {
        $resumedPrompts[] = $prompt;

        return ['profile_field' => 'engineering', 'profile_level' => 'mid', 'postings' => [['posting_id' => 1, 'reason' => 'Adjacent role.', 'main_work' => 'frontend', 'posting_level' => 'mid', 'matches_target_role' => false]]];
    });

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--grade' => true])->assertSuccessful();

    $resumed = json_decode(File::get($this->goldenPath), true);
    expect($resumed['cases'][0])->toBe($saved['cases'][0])
        ->and($resumed['cases'][1]['labels'][0]['grade'])->toBe(1);
    RelevanceGrader::assertPromptedTimes(3);
    expect($resumedPrompts)->toHaveCount(1)
        ->and($resumedPrompts[0])->toContain('PHP engineer')->not->toContain('Laravel developer');
});

test('an unknown weighting is refused', function () {
    writeGolden($this->goldenPath, []);

    $this->artisan('eval:retrieval', ['--labels' => $this->goldenPath, '--weighting' => ['bm25']])
        ->expectsOutputToContain('Unknown weighting bm25.')
        ->assertFailed();
});
