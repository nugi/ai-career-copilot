<?php

namespace App\Actions\Resume;

use App\Ai\EmbedTexts;
use App\Models\Resume;
use App\Models\ResumeChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reuse the owner's current vectors and embed each distinct pending chunk text once.
 */
class EmbedResumeChunks
{
    /**
     * Share the embedding call so resume vectors use the same count check as posting vectors.
     */
    public function __construct(private EmbedTexts $embedTexts) {}

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
            $result = $this->embedTexts->handle(array_keys($pending));
            $tokens = $result['tokens'];

            DB::transaction(function () use ($pending, $result, $model, &$counts): void {
                foreach (array_values($pending) as $index => $group) {
                    foreach ($group as $chunk) {
                        $this->save($chunk, $result['vectors'][$index], $model);
                        $counts['embedded']++;
                    }
                }
            });
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
