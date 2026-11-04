<?php

use App\Enums\WorkArrangement;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

/**
 * The React page is a separate task, so backend responses must not require its Vite asset.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

test('guests are redirected to the login page', function () {
    $this->get(route('matches.index'))->assertRedirect(route('login'));
});

test('the matches page lists eligible openings nearest first without a match percent and with profile filters', function () {
    $this->freezeTime();
    config(['ai-routing.global_daily_budget_usd' => null]);
    $vector = [1, 0, ...array_fill(0, 1534, 0)];
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [$vector]);
    $user = User::factory()->onboarded()->create();
    $user->profile()->update([
        'country_code' => 'ID', 'work_arrangements' => [WorkArrangement::Remote],
        'min_salary' => null, 'salary_currency' => null, 'salary_period' => null,
    ]);
    $postings = JobPosting::factory()->remote()->withEmbedding()->state(['expires_at' => null]);
    $farthest = $postings->create(['embedding' => '['.implode(',', [0, 1, ...array_fill(0, 1534, 0)]).']']);
    $closest = $postings->create(['embedding' => '['.implode(',', $vector).']']);
    $middle = $postings->create(['embedding' => '['.implode(',', [3, 4, ...array_fill(0, 1534, 0)]).']']);
    $postings->create(['country_code' => 'US', 'country_codes' => ['US']]);
    $postings->create(['work_arrangement' => WorkArrangement::Onsite]);

    $this->actingAs($user)->get(route('matches.index', ['mode' => 'semantic']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches', false)
            ->where('failed', false)
            ->where('filters.country', 'ID')
            ->where('filters.mode', 'semantic')
            ->has('countries')
            ->has('matches.data', 3)
            ->where('matches.data.0.opening_id', $closest->opening_id)
            ->missing('matches.data.0.match_percent')
            ->where('matches.data.0.eligibility', 'open')
            ->where('matches.data.1.opening_id', $middle->opening_id)
            ->where('matches.data.2.opening_id', $farthest->opening_id)
        );
});

test('an invalid search mode fails validation', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get(route('matches.index', ['mode' => 'invalid']))
        ->assertSessionHasErrors('mode');
});

test('keyword mode returns matching postings without generating embeddings', function () {
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return [array_fill(0, 1536, 0.1)];
    });
    $user = User::factory()->onboarded()->create();
    $user->profile()->update([
        'target_roles' => ['Laravel'], 'country_code' => null, 'work_arrangements' => [WorkArrangement::Remote],
        'min_salary' => null, 'salary_currency' => null, 'salary_period' => null,
    ]);
    $postings = JobPosting::factory()->remote()->state(['description' => 'Other', 'embedding' => null, 'expires_at' => null]);
    $matching = $postings->create(['title' => 'Laravel developer']);
    $postings->create(['title' => 'Designer']);

    $this->actingAs($user)->get(route('matches.index', ['mode' => 'keyword']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches', false)
            ->where('failed', false)
            ->has('matches.data', 1)
            ->where('matches.data.0.opening_id', $matching->opening_id)
        );
    expect($calls)->toBe(0);
});

test('hybrid mode falls back to keyword results when embedding fails', function () {
    config(['ai-routing.global_daily_budget_usd' => null]);
    $exception = new RuntimeException('Embedding provider unavailable.');
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($exception): array {
        throw $exception;
    });
    Exceptions::fake();
    $user = User::factory()->onboarded()->create();
    $user->profile()->update([
        'target_roles' => ['Laravel'], 'country_code' => null, 'work_arrangements' => [WorkArrangement::Remote],
        'min_salary' => null, 'salary_currency' => null, 'salary_period' => null,
    ]);
    $postings = JobPosting::factory()->remote()->state(['description' => 'Other', 'embedding' => null, 'expires_at' => null]);
    $matching = $postings->create(['title' => 'Laravel developer']);
    $postings->create(['title' => 'Designer']);

    $this->actingAs($user)->get(route('matches.index', ['mode' => 'hybrid']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches', false)
            ->where('filters.mode', 'hybrid')
            ->where('failed', false)
            ->has('matches.data', 1)
            ->where('matches.data.0.opening_id', $matching->opening_id)
        );
    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
});

test('a second visit with the same profile makes no provider call even when the budget is spent', function () {
    config(['ai-routing.global_daily_budget_usd' => null]);
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        $calls++;

        return [array_fill(0, 1536, 0.1)];
    });
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get(route('matches.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('matches', false)->where('failed', false)->where('filters.mode', 'hybrid'));
    expect($calls)->toBe(1);

    config(['ai-routing.global_daily_budget_usd' => 0]);
    $this->get(route('matches.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('matches', false)->where('failed', false));
    expect($calls)->toBe(1);
});

test('hybrid mode shows the reranked order when the flag is on, and keyword mode stays unreranked', function () {
    config(['ai-routing.global_daily_budget_usd' => null, 'ai-routing.reranking.enabled' => true]);
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [array_fill(0, 1536, 0.1)]);
    Reranking::fake([[new RankedDocument(1, 'second', 0.9), new RankedDocument(0, 'first', 0.2)]]);
    $user = User::factory()->onboarded()->create();
    $user->profile()->update([
        'target_roles' => ['Laravel', 'PHP'], 'country_code' => null, 'work_arrangements' => [WorkArrangement::Remote],
        'min_salary' => null, 'salary_currency' => null, 'salary_period' => null,
    ]);
    $postings = JobPosting::factory()->remote()->state(['description' => 'Other', 'embedding' => null, 'expires_at' => null]);
    $first = $postings->create(['title' => 'Laravel PHP developer']);
    $second = $postings->create(['title' => 'Laravel developer']);

    $this->actingAs($user)->get(route('matches.index', ['mode' => 'keyword']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reranked', false)
            ->where('matches.data.0.opening_id', $first->opening_id)
        );
    Reranking::assertNothingReranked();

    $this->get(route('matches.index', ['mode' => 'hybrid']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reranked', true)
            ->where('matches.data.0.opening_id', $second->opening_id)
            ->where('matches.data.1.opening_id', $first->opening_id)
            ->missing('matches.data.0.rerank_score')
        );
});

test('a provider exception in semantic mode returns 200 with failed true and no matches', function () {
    config(['ai-routing.global_daily_budget_usd' => null]);
    $exception = new RuntimeException('Embedding provider unavailable.');
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($exception): array {
        throw $exception;
    });
    Exceptions::fake();
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get(route('matches.index', ['mode' => 'semantic']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches', false)
            ->where('failed', true)
            ->where('matches.data', [])
        );

    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
});

test('the response body does not contain match_embedding', function () {
    config(['ai-routing.global_daily_budget_usd' => null]);
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [array_fill(0, 1536, 0.1)]);
    $user = User::factory()->onboarded()->create();
    $user->profile()->update(['min_salary' => null, 'salary_currency' => null, 'salary_period' => null]);
    JobPosting::factory()->remote()->withEmbedding()->create(['expires_at' => null]);

    $this->actingAs($user)->get(route('matches.index'))->assertOk()
        ->assertDontSee('match_embedding')
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches', false)
            ->where('failed', false)
            ->has('matches.data', 1)
        );
});
