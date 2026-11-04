<?php

namespace App\Actions\Jobs;

use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Collection;

/**
 * The openings after a rerank attempt, and whether the reranker's order was used.
 */
final readonly class RerankResult
{
    /**
     * @param  Collection<int, JobPosting>  $openings
     */
    public function __construct(
        public Collection $openings,
        public bool $reranked,
        public bool $cached = false,
        public ?int $latencyMs = null,
    ) {}
}
