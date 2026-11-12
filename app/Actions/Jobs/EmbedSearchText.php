<?php

namespace App\Actions\Jobs;

use App\Ai\DailyBudget;
use App\Ai\EmbedTexts;
use App\Models\Profile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Generate or reuse a query vector for a profile and optional role.
 */
final class EmbedSearchText
{
    public function __construct(
        private EmbedProfile $embedProfile,
        private ProfileEmbeddingText $embeddingText,
        private EmbedTexts $embedTexts,
        private DailyBudget $dailyBudget,
    ) {}

    /**
     * Reuse the profile vector or cache a role-specific pgvector literal for thirty days.
     */
    public function handle(Profile $profile, ?string $role = null): string
    {
        if (trim($role ?? '') === '') {
            return $this->embedProfile->handle($profile);
        }

        $text = $this->embeddingText->text($profile, $role);
        $model = config('ai-routing.embeddings.model');
        $key = 'search-vector:'.hash('sha256', $model."\n".$text);

        return Cache::remember($key, now()->addDays(30), function () use ($text): string {
            if ($this->dailyBudget->isGloballyExceeded()) {
                throw new RuntimeException('The daily global AI budget is exceeded.');
            }

            return $this->embedTexts->handle([$text])['vectors'][0];
        });
    }
}
