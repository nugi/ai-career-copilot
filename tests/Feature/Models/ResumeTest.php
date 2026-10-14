<?php

use App\Models\Resume;
use App\Models\ResumeChunk;
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
