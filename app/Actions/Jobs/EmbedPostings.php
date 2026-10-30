<?php

namespace App\Actions\Jobs;

use App\Models\JobPosting;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;

/**
 * Embed settled postings whose vector is missing or out of date (ADR 0027).
 * A posting copies the vector of another posting with the same text and model, and each distinct text is sent once.
 */
class EmbedPostings
{
    /**
     * Share the benchmark's text builder so production vectors use the same input.
     */
    public function __construct(private PostingEmbeddingText $embeddingText) {}

    /**
     * Reuse saved vectors and group identical inputs so a batch pays once per distinct text.
     *
     * @param  list<int>  $postingIds
     * @return array{embedded: int, copied: int, skipped: int}
     */
    public function handle(array $postingIds): array
    {
        $counts = ['embedded' => 0, 'copied' => 0, 'skipped' => 0];
        $tokens = 0;
        $model = config('ai-routing.embeddings.model');
        $postings = JobPosting::query()->live()
            ->whereKey($postingIds)
            ->whereNotNull('text_hash')
            ->whereColumn('skills_text_hash', 'text_hash')
            ->with('skills:id,name')->orderBy('id')->get();
        $pending = [];
        $texts = [];

        foreach ($postings as $posting) {
            $text = $this->embeddingText->text($posting);
            $hash = $this->embeddingText->hash($text);

            if ($posting->embedding_model === $model && $posting->embedding_text_hash === $hash) {
                $counts['skipped']++;

                continue;
            }

            $pending[$hash][] = $posting;
            $texts[$hash] = $text;
        }

        $sources = JobPosting::query()
            ->whereIn('embedding_text_hash', array_keys($pending))
            ->where('embedding_model', $model)
            ->whereNotNull('embedding')
            ->orderBy('id')->get(['id', 'embedding_text_hash', 'embedding'])
            ->unique('embedding_text_hash')->keyBy('embedding_text_hash');

        foreach ($pending as $hash => $group) {
            $source = $sources->get($hash);

            if ($source === null) {
                continue;
            }

            foreach ($group as $posting) {
                $this->save($posting, $source->embedding, $model, $hash, $texts[$hash]);
                $counts['copied']++;
            }

            unset($pending[$hash], $texts[$hash]);
        }

        if ($pending !== []) {
            $response = Embeddings::for(array_values($texts))
                ->dimensions(config('ai-routing.embeddings.dimensions'))
                ->generate(config('ai-routing.embeddings.provider'), $model);
            $tokens = $response->usage->inputTokens;

            foreach (array_keys($pending) as $index => $hash) {
                $vector = '['.implode(',', $response->embeddings[$index]).']';

                foreach ($pending[$hash] as $posting) {
                    $this->save($posting, $vector, $model, $hash, $texts[$hash]);
                    $counts['embedded']++;
                }
            }
        }

        Log::info('jobs.embeddings.batch', [...$counts, 'tokens' => $tokens]);

        return $counts;
    }

    /**
     * Save the vector and its provenance together so a row never describes a different input.
     */
    private function save(JobPosting $posting, string $vector, string $model, string $hash, string $text): void
    {
        $posting->update([
            'embedding' => $vector,
            'embedding_model' => $model,
            'embedding_text_hash' => $hash,
            'embedded_text' => $text,
            'embedded_at' => now(),
        ]);
    }
}
