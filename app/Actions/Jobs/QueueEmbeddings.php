<?php

namespace App\Actions\Jobs;

use App\Ai\DailyBudget;
use App\Jobs\EmbedJobPostings;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Find the settled postings whose embedded text or model changed since their vector was made, and queue them in batches.
 */
class QueueEmbeddings
{
    /**
     * Share the budget guard and text builder so queueing respects spend limits and the actual vector input.
     */
    public function __construct(private DailyBudget $budget, private PostingEmbeddingText $embeddingText) {}

    /**
     * Leave postings pending when the budget is spent so a later run can queue them.
     */
    public function handle(?int $limit = null): int
    {
        if ($this->budget->isBatchExceeded()) {
            Log::info('jobs.embeddings.skipped_budget');

            return 0;
        }

        $postingIds = $this->pendingIds($limit);

        foreach (array_chunk($postingIds, config('ai-routing.embeddings.batch_size')) as $batch) {
            EmbedJobPostings::dispatch($batch);
        }

        return count($postingIds);
    }

    /**
     * Compare settled postings with their saved vector input so unchanged postings cost nothing.
     *
     * @return list<int>
     */
    public function pendingIds(?int $limit = null): array
    {
        $postingIds = [];
        $model = config('ai-routing.embeddings.model');

        if ($limit !== null && $limit < 1) {
            return [];
        }

        JobPosting::query()->live()
            ->whereNotNull('text_hash')
            ->whereColumn('skills_text_hash', 'text_hash')
            ->with('skills:id,name')
            ->chunkById(500, function (Collection $postings) use (&$postingIds, $model, $limit): bool {
                foreach ($postings as $posting) {
                    $hash = $this->embeddingText->hash($this->embeddingText->text($posting));

                    if ($posting->embedding_model === $model && $posting->embedding_text_hash === $hash) {
                        continue;
                    }

                    $postingIds[] = (int) $posting->id;

                    if ($limit !== null && count($postingIds) >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $postingIds;
    }
}
