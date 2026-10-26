<?php

use App\Models\JobPosting;
use App\Models\Opening;

test('only openings with a live posting are open', function () {
    $live = Opening::factory()->create();
    JobPosting::factory()->for($live)->create(['expires_at' => null]);
    $expired = Opening::factory()->create();
    JobPosting::factory()->for($expired)->create(['expires_at' => now()->subDay()]);
    $merged = Opening::factory()->create(['merged_into_id' => $live->id]);

    expect(Opening::query()->open()->pluck('id')->all())->toBe([$live->id]);
});
