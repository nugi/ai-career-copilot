<?php

use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;

test('cosine distance returns postings from closest to farthest', function () {
    $zeros = array_fill(0, 1534, 0);
    $query = '['.implode(',', [1, 0, ...$zeros]).']';
    $farthest = JobPosting::factory()->withEmbedding()->create([
        'embedding' => '['.implode(',', [0, 1, ...$zeros]).']',
    ]);
    $closest = JobPosting::factory()->withEmbedding()->create(['embedding' => $query]);
    $middle = JobPosting::factory()->withEmbedding()->create([
        'embedding' => '['.implode(',', [3, 4, ...$zeros]).']',
    ]);

    $postings = DB::select('select id from job_postings order by embedding <=> ? limit 3', [$query]);

    /** Cosine distances to [1, 0, ...] are 0, 1 - 3/5 = 0.4, and 1. */
    expect(array_column($postings, 'id'))->toBe([$closest->id, $middle->id, $farthest->id]);
});
