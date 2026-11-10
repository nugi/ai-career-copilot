<?php

namespace App\Actions\Fit;

use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeChunk;
use Illuminate\Database\Eloquent\Collection;

/**
 * Find the chunks of one resume that are nearest to a posting (ADR 0034).
 *
 * Only the given resume is searched, so no other user's text can be retrieved. The search is exact: a resume has 6 to
 * 15 chunks, so an index would not help (ADR 0001). Only the posting's stored vector is used; retrieval never embeds text.
 */
final class RetrieveResumeExcerpts
{
    /**
     * Whether the posting has a stored vector for the configured embedding model.
     */
    public function hasCurrentVector(JobPosting $posting): bool
    {
        return $posting->embedding !== null && $posting->embedding_model === config('ai-routing.embeddings.model');
    }

    /**
     * Return the nearest embedded chunks, nearest first, using the posting's own vector.
     *
     * @return Collection<int, ResumeChunk>
     */
    public function handle(Resume $resume, JobPosting $posting, int $limit): Collection
    {
        $model = config('ai-routing.embeddings.model');

        return ResumeChunk::query()
            ->where('resume_id', $resume->getKey())
            ->whereNotNull('embedding')
            ->where('embedding_model', $model)
            ->orderByRaw('embedding <=> ?::vector', [$posting->embedding])
            ->orderBy('position')
            ->limit($limit)
            ->get();
    }
}
