<?php

use App\Enums\ResumeParseStatus;
use App\Models\Resume;
use App\Models\ResumeChunk;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('a resume lists its chunks in document order', function () {
    $resume = Resume::factory()->create();
    ResumeChunk::factory()->for($resume)->count(3)->sequence(['position' => 2], ['position' => 0], ['position' => 1])->create();

    expect($resume->chunks->pluck('position')->all())->toBe([0, 1, 2]);
});

test('two chunks of a resume cannot share a position', function () {
    $resume = Resume::factory()->create();
    ResumeChunk::factory()->for($resume)->create(['position' => 0]);

    expect(fn () => ResumeChunk::factory()->for($resume)->create(['position' => 0]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('deleting a resume removes its chunks', function () {
    $chunk = ResumeChunk::factory()->create();

    $chunk->resume->delete();

    $this->assertModelMissing($chunk);
});

test('saving content sets the content hash and changing the content changes it', function () {
    $resume = Resume::factory()->create(['content' => 'Backend engineer with 6 years']);

    expect($resume->content_hash)->toBe(hash('sha256', 'Backend engineer with 6 years'));

    $resume->update(['content' => 'Frontend engineer with 2 years']);

    expect($resume->refresh()->content_hash)->toBe(hash('sha256', 'Frontend engineer with 2 years'));
});

test('content that is null has no content hash', function () {
    $resume = Resume::factory()->create(['content' => 'Backend engineer with 6 years']);

    $resume->update(['content' => null]);

    expect($resume->refresh()->content_hash)->toBeNull();
});

test('the parse status is failed, then parsed, then pending', function () {
    expect(Resume::factory()->create()->parseStatus())->toBe(ResumeParseStatus::Pending)
        ->and(Resume::factory()->parsed()->create()->parseStatus())->toBe(ResumeParseStatus::Parsed)
        ->and(Resume::factory()->parseFailed()->create()->parseStatus())->toBe(ResumeParseStatus::Failed);
});

test('a resume without a content hash has no duplicates', function () {
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->for($user)->create();
    Resume::factory()->for($user)->create(['content' => 'Backend engineer with 6 years']);

    expect($resume->duplicates()->count())->toBe(0);
});

test('a resume finds only the duplicates of its own user with the same text', function () {
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->for($user)->create(['content' => 'Backend engineer with 6 years']);
    $duplicate = Resume::factory()->for($user)->create(['content' => 'Backend engineer with 6 years']);
    Resume::factory()->for($user)->create(['content' => 'Frontend engineer with 2 years']);
    Resume::factory()->create(['content' => 'Backend engineer with 6 years']);

    expect($resume->duplicates()->pluck('id')->all())->toBe([$duplicate->id]);
});
