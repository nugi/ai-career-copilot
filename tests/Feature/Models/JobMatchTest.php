<?php

use App\Models\JobMatch;
use App\Models\Resume;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a match links a user, a posting and the resume it was scored against', function () {
    $resume = Resume::factory()->create();
    $match = JobMatch::factory()->for($resume->user)->for($resume)->create();

    expect($match->user->is($resume->user))->toBeTrue()
        ->and($match->resume->is($resume))->toBeTrue()
        ->and($match->jobPosting->matches->sole()->is($match))->toBeTrue();
});

test('a user has at most one match per posting', function () {
    $match = JobMatch::factory()->create();

    expect(fn () => JobMatch::factory()->for($match->user)->for($match->jobPosting)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the database rejects a score outside 0 to 100', function (int $score) {
    expect(fn () => JobMatch::factory()->create(['score' => $score]))
        ->toThrow(QueryException::class, 'job_matches_score_check');
})->with([
    'below 0' => -1,
    'above 100' => 101,
]);

test('a match survives the deletion of its resume', function () {
    $match = JobMatch::factory()->for(Resume::factory())->create();

    $match->resume->delete();

    expect($match->fresh()->resume_id)->toBeNull();
});
