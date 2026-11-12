<?php

use App\Ai\Tools\SearchJobs;
use App\Enums\WorkArrangement;
use App\Models\ExchangeRate;
use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    config(['ai-routing.global_daily_budget_usd' => null]);
    Cache::flush();
});

test('a role replaces the target roles', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]])->preventStrayEmbeddings();
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create(['target_roles' => ['Laravel Developer']]);
    $python = JobPosting::factory()->remote()->create(['title' => 'Python Developer', 'description' => 'Work', 'expires_at' => null]);
    JobPosting::factory()->remote()->create(['title' => 'Laravel Developer', 'description' => 'Work', 'expires_at' => null]);

    $result = json_decode(app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request(['role' => 'Python Developer'])), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($result['jobs'], 'id'))->toBe([$python->id]);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Python Developer']);
});

test('remote true keeps remote openings only', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]])->preventStrayEmbeddings();
    $profile = Profile::factory()->withoutSalary()->create(['target_roles' => ['Developer']]);
    $remote = JobPosting::factory()->remote()->create(['title' => 'Developer', 'expires_at' => null]);
    JobPosting::factory()->create(['title' => 'Developer', 'work_arrangement' => WorkArrangement::Onsite, 'expires_at' => null]);

    $result = json_decode(app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request(['remote' => true])), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($result['jobs'], 'id'))->toBe([$remote->id]);
});

test('a salary floor in another currency removes pay that is certainly lower', function () {
    $this->freezeTime();
    Embeddings::fake([[array_fill(0, 1536, 0.1)]])->preventStrayEmbeddings();
    ExchangeRate::factory()->create(['currency' => 'USD', 'rate' => 1.1188]);
    $profile = Profile::factory()->remoteOnly()->create(['target_roles' => ['Developer'], 'min_salary' => 200_000]);
    $above = JobPosting::factory()->remote()->create([
        'title' => 'Developer', 'expires_at' => null, 'salary_min' => 50_000, 'annual_salary_min' => 50_000,
        'salary_max' => 60_000, 'annual_salary_max' => 60_000,
    ]);
    JobPosting::factory()->remote()->create([
        'title' => 'Developer', 'expires_at' => null, 'salary_min' => 5_000, 'annual_salary_min' => 5_000,
        'salary_max' => 10_000, 'annual_salary_max' => 10_000,
    ]);

    $result = json_decode(app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request([
        'min_salary' => 40_000, 'currency' => 'EUR', 'salary_period' => 'yearly',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($result['jobs'], 'id'))->toBe([$above->id]);
});

test('a currency without a rate returns a note and does not search', function () {
    Embeddings::fake()->preventStrayEmbeddings();
    $profile = Profile::factory()->create();

    $result = json_decode(app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request([
        'min_salary' => 40_000, 'currency' => 'XYZ',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['jobs'])->toBe([]);
    expect($result['note'])->toContain('XYZ');
    Embeddings::assertNothingGenerated();
});

test('a limit above 20 fails validation', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]])->preventStrayEmbeddings();
    $profile = Profile::factory()->create();

    expect(fn () => app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request(['limit' => 21])))
        ->toThrow(ValidationException::class);
    Embeddings::assertNothingGenerated();
});

test('a result has only the compact keys', function () {
    Embeddings::fake([[array_fill(0, 1536, 0.1)]])->preventStrayEmbeddings();
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create(['target_roles' => ['Developer']]);
    JobPosting::factory()->remote()->create([
        'title' => 'Developer', 'expires_at' => null, 'salary_min' => 120_000, 'salary_max' => 150_000,
    ]);

    $result = json_decode(app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($result['jobs'][0]))->toBe(['id', 'title', 'company', 'location', 'arrangement', 'salary', 'match']);
    expect($result['jobs'][0]['salary'])->toBe('USD 120,000–150,000 yearly');
    expect($result['jobs'][0]['location'])->toBeNull();
    expect($result['jobs'][0]['match'])->toBe(50);
});

test('the same role embeds once', function () {
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = $prompt->inputs;

        return [array_fill(0, 1536, 0.1)];
    })->preventStrayEmbeddings();
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create();
    $tool = app()->make(SearchJobs::class, ['user' => $profile->user]);

    $tool->handle(new Request(['role' => 'Python Developer']));
    $tool->handle(new Request(['role' => 'Python Developer']));

    expect($requests)->toBe([['Python Developer']]);
});

test('a failed embed still returns keyword matches', function () {
    $exception = new RuntimeException('Embedding unavailable.');
    Embeddings::fake(function () use ($exception): never {
        throw $exception;
    })->preventStrayEmbeddings();
    Exceptions::fake();
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create(['target_roles' => ['Laravel Developer']]);
    $posting = JobPosting::factory()->remote()->create(['title' => 'Laravel Developer', 'expires_at' => null]);

    $result = json_decode(app()->make(SearchJobs::class, ['user' => $profile->user])->handle(new Request), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($result['jobs'], 'id'))->toBe([$posting->id]);
    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
});
