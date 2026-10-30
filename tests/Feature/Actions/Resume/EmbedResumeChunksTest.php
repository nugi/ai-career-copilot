<?php

use App\Actions\Resume\EmbedResumeChunks;
use App\Models\Resume;
use App\Models\ResumeChunk;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('only pending chunks are embedded together and a second run makes no request', function () {
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = $prompt->inputs;

        return array_map(fn (string $input): array => array_fill(0, 1536, 0.1), $prompt->inputs);
    });
    $resume = Resume::factory()->create();
    $vector = '['.implode(',', array_fill(0, 1536, 0.1)).']';
    ResumeChunk::factory()->for($resume)->create([
        'content' => 'Summary: Backend engineer', 'embedding' => $vector,
        'embedding_model' => config('ai-routing.embeddings.model'), 'embedded_at' => now(),
    ]);
    $pending = ResumeChunk::factory()->for($resume)->create(['content' => 'Skills: PHP, Laravel']);
    ResumeChunk::factory()->for($resume)->create(['content' => 'Education: Computer science']);

    $counts = app(EmbedResumeChunks::class)->handle($resume);

    expect($counts)->toBe(['embedded' => 2, 'copied' => 0, 'skipped' => 1])
        ->and($requests)->toBe([['Skills: PHP, Laravel', 'Education: Computer science']])
        ->and($pending->fresh()->embedding)->toBe($vector)
        ->and($pending->fresh()->embedding_model)->toBe(config('ai-routing.embeddings.model'))
        ->and($pending->fresh()->embedded_at)->not->toBeNull();

    expect(app(EmbedResumeChunks::class)->handle($resume))->toBe(['embedded' => 0, 'copied' => 0, 'skipped' => 3])
        ->and($requests)->toHaveCount(1);
});
