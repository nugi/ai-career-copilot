<?php

use App\Ai\EmbedTexts;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('a response with fewer vectors than texts is refused', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => [array_fill(0, 1536, 0.1)]);

    app(EmbedTexts::class)->handle(['a', 'b']);
})->throws(UnexpectedValueException::class, 'The provider returned 1 embeddings for 2 texts.');
