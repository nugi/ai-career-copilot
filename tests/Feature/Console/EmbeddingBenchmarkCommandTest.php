<?php

use App\Models\JobPosting;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('embedding benchmark ranks relevant openings and excludes seeded postings', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
        fn (string $input): array => str_contains($input, 'PHP') ? [1, 0] : [0, 1],
        $prompt->inputs,
    ));

    JobPosting::factory()->create(['source' => 'manual', 'title' => 'PHP seeded', 'description' => 'PHP', 'expires_at' => null]);
    $first = JobPosting::factory()->create(['source' => 'jobicy', 'external_id' => 'first', 'title' => 'PHP first', 'description' => 'PHP', 'expires_at' => null]);
    $second = JobPosting::factory()->create(['source' => 'jobicy', 'external_id' => 'second', 'title' => 'PHP second', 'description' => 'PHP', 'expires_at' => null]);
    JobPosting::factory()->create(['source' => 'jobicy', 'external_id' => 'other', 'title' => 'Python', 'description' => 'Python', 'expires_at' => null]);

    $path = tempnam(sys_get_temp_dir(), 'embedding-labels-');
    file_put_contents($path, json_encode(['queries' => [['language' => 'en', 'query' => 'PHP query', 'relevant' => ["jobicy:{$first->external_id}", "jobicy:{$second->external_id}", 'jobicy:missing']]]]));

    try {
        $this->artisan('eval:embeddings', ['--config' => ['fake-model:2'], '--labels' => $path, '--details' => true])
            ->expectsOutputToContain('Label not found in the live corpus: jobicy:missing')
            ->expectsOutputToContain("{$first->opening_id}: #1 {$second->opening_id}: #2")
            ->expectsTable(
                ['Configuration', 'Hit@5', 'Recall@10', 'MRR', 'Recall@10 en', 'Recall@10 id', 'Tokens', 'Cost (USD)'],
                [['fake-model:2', '1.00', '1.00', '1.00', '1.00', '0.00', 0, 'unpriced']],
            )
            ->assertExitCode(0);
    } finally {
        unlink($path);
    }
});

test('embedding benchmark dry run does not generate embeddings', function () {
    Embeddings::fake();
    $path = tempnam(sys_get_temp_dir(), 'embedding-labels-');
    file_put_contents($path, json_encode(['queries' => []]));

    try {
        $this->artisan('eval:embeddings', ['--dry-run' => true, '--labels' => $path])->assertExitCode(0);
        Embeddings::assertNothingGenerated();
    } finally {
        unlink($path);
    }
});
