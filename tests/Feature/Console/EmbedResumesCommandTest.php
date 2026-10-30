<?php

use App\Models\Resume;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('the command rebuilds chunks for an older parsed resume and skips embedded chunks on the next run', function () {
    $resume = Resume::factory()->parsed()->create();
    $resume->update(['parsed_data' => [...$resume->parsed_data, 'summary' => 'Backend engineer.']]);
    $resume->chunks()->delete();
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
        fn (string $input): array => array_fill(0, 1536, 0.1),
        $prompt->inputs,
    ));

    $this->artisan('resumes:embed')
        ->expectsOutput('Embedded 4, copied 0, skipped 0 chunks of 1 resumes.')
        ->assertExitCode(0);

    expect($resume->chunks()->count())->toBe(4);
    foreach ($resume->chunks()->get() as $chunk) {
        expect($chunk->embedding)->not->toBeNull();
    }

    $this->artisan('resumes:embed')
        ->expectsOutput('Embedded 0, copied 0, skipped 4 chunks of 1 resumes.')
        ->assertExitCode(0);
});
