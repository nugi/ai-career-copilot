<?php

use App\Actions\Jobs\RerankOpenings;
use App\Enums\WorkArrangement;
use App\Models\AiUsage;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function (): void {
    config([
        'ai-routing.reranking.enabled' => true,
        'ai-routing.reranking.candidates' => 3,
        'ai-routing.global_daily_budget_usd' => null,
    ]);
});

/**
 * A profile with one target role and one skill, saved so its skills can be read.
 */
function rerankProfile(): Profile
{
    $user = User::factory()->onboarded()->create();
    $user->profile()->update(['target_roles' => ['Backend Engineer'], 'work_arrangements' => [WorkArrangement::Remote]]);
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => 'expert', 'source' => 'profile']);

    return $user->profile()->first();
}

/**
 * Openings in hybrid order, with their skills loaded as MatchOpenings leaves them.
 *
 * @return Collection<int, JobPosting>
 */
function hybridOpenings(int $count): Collection
{
    return JobPosting::factory()->count($count)->sequence(fn ($sequence): array => [
        'title' => 'Posting '.$sequence->index,
        'description' => 'Work on things.',
    ])->create()->load('skills');
}

/**
 * A fake reranker answer that returns the given document indexes with falling scores.
 *
 * @param  list<int>  $indexes
 * @return list<RankedDocument>
 */
function rankedAt(array $indexes): array
{
    return array_map(
        fn (int $index, int $position): RankedDocument => new RankedDocument($index, "doc {$index}", 1 - $position / 10),
        $indexes,
        array_keys($indexes),
    );
}

test('a disabled flag keeps the hybrid order and makes no call', function () {
    config(['ai-routing.reranking.enabled' => false]);
    Reranking::fake();
    $openings = hybridOpenings(3);

    $result = app(RerankOpenings::class)->handle(rerankProfile(), $openings);

    expect($result->reranked)->toBeFalse()
        ->and($result->openings->pluck('id')->all())->toBe($openings->pluck('id')->all());
    Reranking::assertNothingReranked();
});

test('the candidates take the reranker order and the rest keep their hybrid place after them', function () {
    Reranking::fake([rankedAt([2, 0, 1])]);
    $openings = hybridOpenings(5);

    $result = app(RerankOpenings::class)->handle(rerankProfile(), $openings);

    expect($result->reranked)->toBeTrue()
        ->and($result->cached)->toBeFalse()
        ->and($result->openings->pluck('id')->all())->toBe([
            $openings[2]->id, $openings[0]->id, $openings[1]->id, $openings[3]->id, $openings[4]->id,
        ])
        ->and($result->openings[0]->getAttribute('rerank_score'))->toBe(1.0)
        ->and($result->openings[3]->getAttribute('rerank_score'))->toBeNull();
    Reranking::assertReranked(fn (RerankingPrompt $prompt): bool => count($prompt->documents) === 3
        && str_contains($prompt->query, 'Backend Engineer')
        && str_contains($prompt->query, 'Laravel')
        && $prompt->documentsContain('Title: Posting 2')
        && $prompt->model === 'rerank-v4.0-pro');
});

test('an index out of range or repeated is ignored and a left-out candidate keeps its hybrid place', function () {
    Reranking::fake([rankedAt([1, 7, 1])]);
    $openings = hybridOpenings(3);

    $result = app(RerankOpenings::class)->handle(rerankProfile(), $openings);

    expect($result->openings->pluck('id')->all())->toBe([$openings[1]->id, $openings[0]->id, $openings[2]->id]);
});

test('a repeat call with the same profile and openings is served from the cache and a fresh call is not', function () {
    Reranking::fake([rankedAt([1, 0, 2]), rankedAt([2, 1, 0])]);
    $profile = rerankProfile();
    $openings = hybridOpenings(3);
    $rerank = app(RerankOpenings::class);

    $first = $rerank->handle($profile, $openings);
    $second = $rerank->handle($profile, $openings);
    $fresh = $rerank->handle($profile, $openings, fresh: true);

    expect($second->cached)->toBeTrue()
        ->and($second->openings->pluck('id')->all())->toBe($first->openings->pluck('id')->all())
        ->and($fresh->cached)->toBeFalse()
        ->and($fresh->openings->pluck('id')->all())->toBe([$openings[2]->id, $openings[1]->id, $openings[0]->id]);
});

test('a changed posting text misses the cache', function () {
    Reranking::fake([rankedAt([1, 0, 2]), rankedAt([2, 1, 0])]);
    $profile = rerankProfile();
    $openings = hybridOpenings(3);
    $rerank = app(RerankOpenings::class);

    $rerank->handle($profile, $openings);
    $openings[0]->description = 'A new description.';
    $second = $rerank->handle($profile, $openings);

    expect($second->cached)->toBeFalse();
});

test('a provider failure keeps the hybrid order', function () {
    Reranking::fake(fn (): never => throw new RuntimeException('Provider down'));
    $openings = hybridOpenings(3);

    $result = app(RerankOpenings::class)->handle(rerankProfile(), $openings);

    expect($result->reranked)->toBeFalse()
        ->and($result->openings->pluck('id')->all())->toBe($openings->pluck('id')->all());
});

test('a spent global budget keeps the hybrid order and makes no call', function () {
    config(['ai-routing.global_daily_budget_usd' => 0.05]);
    AiUsage::factory()->create(['cost_usd' => 0.5, 'created_at' => now()]);
    Reranking::fake();
    $openings = hybridOpenings(3);

    $result = app(RerankOpenings::class)->handle(rerankProfile(), $openings);

    expect($result->reranked)->toBeFalse();
    Reranking::assertNothingReranked();
});

test('a single opening is not reranked', function () {
    Reranking::fake();

    $result = app(RerankOpenings::class)->handle(rerankProfile(), hybridOpenings(1));

    expect($result->reranked)->toBeFalse();
    Reranking::assertNothingReranked();
});
