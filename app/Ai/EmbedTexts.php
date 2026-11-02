<?php

namespace App\Ai;

use Laravel\Ai\Embeddings;
use UnexpectedValueException;

/**
 * Embed texts with the configured model and refuse a response whose count does not match, so a vector is never saved for the wrong text.
 */
class EmbedTexts
{
    /**
     * Return one pgvector literal for each text, in input order, and the input tokens the call used.
     *
     * @param  list<string>  $texts
     * @return array{vectors: list<string>, tokens: int}
     */
    public function handle(array $texts): array
    {
        $response = Embeddings::for($texts)
            ->dimensions(config('ai-routing.embeddings.dimensions'))
            ->generate(config('ai-routing.embeddings.provider'), config('ai-routing.embeddings.model'));

        if (count($response->embeddings) !== count($texts)) {
            throw new UnexpectedValueException(sprintf(
                'The provider returned %d embeddings for %d texts.',
                count($response->embeddings),
                count($texts),
            ));
        }

        return [
            'vectors' => array_map(
                fn (array $vector): string => '['.implode(',', $vector).']',
                $response->embeddings,
            ),
            'tokens' => $response->usage->inputTokens,
        ];
    }
}
