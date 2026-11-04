<?php

namespace App\Actions\Fit;

use App\Actions\Jobs\PostingEmbeddingText;
use App\Ai\EmbedTexts;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeChunk;
use Illuminate\Database\Eloquent\Collection;

/**
 * Find the chunks of one resume that are nearest to a posting (ADR 0034).
 *
 * Only the given resume is searched, so no other user's text can be retrieved. The search is exact: a resume has 6 to
 * 15 chunks, so an index would not help (ADR 0001).
 */
final class RetrieveResumeExcerpts
{
    public function __construct(private EmbedTexts $embedTexts, private PostingEmbeddingText $postingText) {}

    /**
     * Return the nearest embedded chunks, nearest first. A posting without a current vector is embedded on the spot.
     *
     * @return Collection<int, ResumeChunk>
     */
    public function handle(Resume $resume, JobPosting $posting, int $limit): Collection
    {
        $model = config('ai-routing.embeddings.model');
        $vector = $posting->embedding !== null && $posting->embedding_model === $model
            ? $posting->embedding
            : $this->embedTexts->handle([$this->postingText->text($posting->loadMissing('skills'))])['vectors'][0];

        return ResumeChunk::query()
            ->where('resume_id', $resume->getKey())
            ->whereNotNull('embedding')
            ->where('embedding_model', $model)
            ->orderByRaw('embedding <=> ?::vector', [$vector])
            ->orderBy('position')
            ->limit($limit)
            ->get();
    }
}
