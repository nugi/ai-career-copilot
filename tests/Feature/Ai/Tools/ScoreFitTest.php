<?php

use App\Ai\Agents\FitExplainer;
use App\Ai\Tools\ScoreFit;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    FitExplainer::fake()->preventStrayPrompts();
    Embeddings::fake()->preventStrayEmbeddings();
});

test('it returns the compact score for a live job', function (): void {
    $profile = Profile::factory()->withoutSalary()->create(['work_arrangements' => []]);
    $posting = JobPosting::factory()->create(['expires_at' => null, 'years_required' => null, 'seniority' => null, 'extracted_seniority' => null]);
    $tool = app()->make(ScoreFit::class, ['user' => $profile->user]);

    $result = json_decode($tool->handle(new Request(['job_id' => $posting->id])), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($result))->toBe(['job_id', 'score', 'breakdown', 'matched_skills', 'missing_skills', 'reasons'])
        ->and($result['job_id'])->toBe($posting->id)
        ->and($result['breakdown'])->toBe(['required_skills' => null, 'nice_skills' => null, 'seniority' => null, 'salary' => null, 'location' => 50])
        ->and($result['score'])->toBe(50)
        ->and($result['reasons'])->toBe([]);
});

test('a fourth call in one answer is refused', function (): void {
    $profile = Profile::factory()->withoutSalary()->create();
    $posting = JobPosting::factory()->create(['expires_at' => null]);
    $tool = app()->make(ScoreFit::class, ['user' => $profile->user]);
    $request = new Request(['job_id' => $posting->id]);

    for ($call = 0; $call < 3; $call++) {
        expect(json_decode($tool->handle($request), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('score');
    }
    $match = JobMatch::query()->sole();
    $match->update(['reasons' => ['Do not rescore.']]);

    expect(json_decode($tool->handle($request), true, flags: JSON_THROW_ON_ERROR))->toBe(['error' => 'The limit is 3 fit scores in one answer.'])
        ->and($match->fresh()->reasons)->toBe(['Do not rescore.']);
});

test('an unknown job id returns an error', function (): void {
    $profile = Profile::factory()->create();
    $expired = JobPosting::factory()->create(['expires_at' => now()->subDay()]);
    $tool = app()->make(ScoreFit::class, ['user' => $profile->user]);

    foreach ([$expired->id, $expired->id + 1000] as $id) {
        expect(json_decode($tool->handle(new Request(['job_id' => $id])), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['error' => 'No open job has this id.']);
    }
    expect(JobMatch::query()->count())->toBe(0);
});

test('a job id must be a positive integer', function (): void {
    $profile = Profile::factory()->create();
    $tool = app()->make(ScoreFit::class, ['user' => $profile->user]);

    expect(fn () => $tool->handle(new Request(['job_id' => 0])))->toThrow(ValidationException::class);
});
