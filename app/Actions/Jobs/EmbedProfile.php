<?php

namespace App\Actions\Jobs;

use App\Ai\DailyBudget;
use App\Ai\EmbedTexts;
use App\Models\Profile;
use RuntimeException;

/**
 * Cache a profile's query vector until its text or embedding model changes.
 */
final class EmbedProfile
{
    public function __construct(
        private ProfileEmbeddingText $embeddingText,
        private EmbedTexts $embedTexts,
        private DailyBudget $dailyBudget,
    ) {}

    /**
     * Reuse a current vector before checking the global budget for a new embedding.
     */
    public function handle(Profile $profile): string
    {
        $text = $this->embeddingText->text($profile);
        $hash = $this->embeddingText->hash($text);
        $model = config('ai-routing.embeddings.model');

        if ($profile->match_embedding_hash === $hash
            && $profile->match_embedding_model === $model
            && $profile->match_embedding !== null) {
            return $profile->match_embedding;
        }

        if ($this->dailyBudget->isGloballyExceeded()) {
            throw new RuntimeException('The daily global AI budget is exceeded.');
        }

        $result = $this->embedTexts->handle([$text]);
        $vector = $result['vectors'][0];

        $profile->match_embedding = $vector;
        $profile->match_embedding_model = $model;
        $profile->match_embedding_hash = $hash;
        $profile->save();

        return $vector;
    }
}
