<?php

namespace App\Actions\Resume;

use App\Models\Resume;
use App\Models\ResumeChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;

/**
 * Reuse the owner's current vectors and embed each distinct pending chunk text once.
 */
class EmbedResumeChunks
{
    /**
     * Embed pending chunks and reuse current vectors from the same owner.
     *
     * @return array{embedded: int, copied: int, skipped: int}
     */
    public function handle(Resume $resume): array
    {
        $counts = ['embedded' => 0, 'copied' => 0, 'skipped' => 0];
        $tokens = 0;
        $model = config('ai-routing.embeddings.model');
        $pending = [];

        foreach ($resume->chunks()->get() as $chunk) {
            if ($chunk->embedding !== null && $chunk->embedding_model === $model) {
                $counts['skipped']++;

                continue;
            }

            $pending[$chunk->content][] = $chunk;
        }

        $sources = ResumeChunk::query()
            ->whereHas('resume', fn (Builder $query): Builder => $query->where('user_id', $resume->user_id))
            ->whereIn('content', array_keys($pending))
            ->whereNotNull('embedding')->where('embedding_model', $model)
            ->orderBy('id')->get()->keyBy('content');

        foreach ($pending as $text => $group) {
            $source = $sources->get($text);

            if ($source === null) {
                continue;
            }

            foreach ($group as $chunk) {
                $this->save($chunk, $source->embedding, $model);
                $counts['copied']++;
            }

            unset($pending[$text]);
        }

        if ($pending !== []) {
            $response = Embeddings::for(array_keys($pending))
                ->dimensions(config('ai-routing.embeddings.dimensions'))
                ->generate(config('ai-routing.embeddings.provider'), $model);
            $tokens = $response->usage->inputTokens;

            foreach (array_values($pending) as $index => $group) {
                $vector = '['.implode(',', $response->embeddings[$index]).']';

                foreach ($group as $chunk) {
                    $this->save($chunk, $vector, $model);
                    $counts['embedded']++;
                }
            }
        }

        if ($counts['embedded'] > 0 || $counts['copied'] > 0) {
            Log::info('resumes.embeddings.done', [...$counts, 'resume_id' => $resume->id, 'tokens' => $tokens]);
        }

        return $counts;
    }

    /**
     * Save the vector and its model together without logging resume text.
     */
    private function save(ResumeChunk $chunk, string $vector, string $model): void
    {
        $chunk->update([
            'embedding' => $vector,
            'embedding_model' => $model,
            'embedded_at' => now(),
        ]);
    }
}
