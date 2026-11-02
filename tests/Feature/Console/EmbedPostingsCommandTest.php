<?php

use App\JobSources\Normalization\NormalizedPosting;
use App\Models\JobPosting;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * A live posting whose skill extraction has settled for its current text.
 */
function liveSettledBackfillPosting(string $title): JobPosting
{
    $hash = NormalizedPosting::textHash($title, 'Work with our team.');

    return JobPosting::factory()->create([
        'title' => $title,
        'description' => 'Work with our team.',
        'text_hash' => $hash,
        'skills_text_hash' => $hash,
        'expires_at' => now()->addDays(20),
    ]);
}

test('the backfill embeds pending postings in one request and then reports nothing to embed', function () {
    $requests = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests++;

        return array_map(fn (string $input): array => array_fill(0, 1536, 0.1), $prompt->inputs);
    });
    liveSettledBackfillPosting('Backend Engineer');
    liveSettledBackfillPosting('Data Engineer');

    $this->artisan('jobs:embed --backfill')->assertExitCode(0);

    expect($requests)->toBe(1);

    $this->artisan('jobs:embed --backfill')
        ->expectsOutput('Nothing to embed.')
        ->assertExitCode(0);

    expect($requests)->toBe(1);
});
